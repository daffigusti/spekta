<?php

namespace App\Services;

use App\Jobs\GenerateDocumentJob;
use App\Models\GenerationRun;
use App\Models\Project;
use Illuminate\Support\Facades\Bus;

/**
 * FR-07: pipeline DAG. Eksekusi sebagai chain berurutan topological
 * (ponytail: worker tunggal MVP — paralelisme antar-branch belum perlu,
 * upgrade ke Bus::batch per-level bila worker > 1).
 */
class GenerationPipeline
{
    public function start(Project $project): GenerationRun
    {
        $complexity = $project->understanding?->complexity ?? 3;
        // Set dokumen template perusahaan jadi default saat depth 'auto';
        // kedalaman eksplisit (concise/full) tetap menimpa template & kompleksitas.
        $tplKinds = $project->docTemplate?->doc_kinds;
        $docKeys = match ($project->blueprint['depth'] ?? 'auto') {
            'concise' => config('spekta.doc_sets.1'),
            'full' => config('spekta.doc_sets.3'),
            default => $tplKinds ?: config('spekta.doc_sets.'.$complexity),
        };
        $graph = config('spekta.doc_pipeline');
        // Buang doc key basi (mis. template menyimpan kind yang sudah tak ada di pipeline)
        $docKeys = array_values(array_intersect($docKeys, array_keys($graph)));

        $run = $project->generationRuns()->create(['trigger' => 'full', 'status' => 'queued']);

        $ordered = $this->topoSort($docKeys, $graph);
        $nodes = [];
        foreach ($ordered as $key) {
            $nodes[] = $run->nodes()->create([
                'doc_key' => $key,
                'depends_on' => array_values(array_intersect($graph[$key] ?? [], $docKeys)),
            ]);
        }

        $this->dispatchChain($nodes);

        return $run;
    }

    /** Generate hanya dokumen yang belum ada (set lengkap pipeline) — upstream dibaca dari dokumen tersimpan. */
    public function startMissing(Project $project, ?array $only = null): ?GenerationRun
    {
        $graph = config('spekta.doc_pipeline');
        $existing = $project->documents()->pluck('doc_key')->all();
        $missing = array_values(array_diff(array_keys($graph), $existing));
        if ($only !== null) {
            $missing = array_values(array_intersect($missing, $only));
        }
        if (! $missing) {
            return null;
        }

        $run = $project->generationRuns()->create(['trigger' => 'missing', 'status' => 'queued']);

        $nodes = [];
        foreach ($this->topoSort($missing, $graph) as $key) {
            $nodes[] = $run->nodes()->create([
                'doc_key' => $key,
                // depends_on boleh menunjuk dokumen yang sudah ada — job membaca isinya dari DB
                'depends_on' => array_values(array_intersect($graph[$key] ?? [], array_merge($missing, $existing))),
            ]);
        }

        $this->dispatchChain($nodes);

        return $run;
    }

    public function resume(GenerationRun $run): void
    {
        $pending = $run->nodes()->whereIn('status', ['queued', 'error', 'running'])->get();
        foreach ($pending as $node) {
            if ($node->status !== 'queued') {
                $node->update(['status' => 'queued', 'error_text' => null]);
            }
        }
        $run->update(['status' => 'queued']);
        $this->dispatchChain($pending->all());
    }

    private function dispatchChain(array $nodes): void
    {
        $jobs = array_map(fn ($n) => new GenerateDocumentJob($n->id), $nodes);
        if ($jobs) {
            Bus::chain($jobs)->dispatch();
        }
    }

    private function topoSort(array $keys, array $graph): array
    {
        $sorted = [];
        $visit = function (string $key) use (&$visit, &$sorted, $keys, $graph) {
            if (in_array($key, $sorted) || ! in_array($key, $keys)) {
                return;
            }
            foreach ($graph[$key] ?? [] as $dep) {
                $visit($dep);
            }
            $sorted[] = $key;
        };
        foreach ($keys as $key) {
            $visit($key);
        }

        return $sorted;
    }
}
