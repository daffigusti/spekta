<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\GenerationNode;
use App\Models\GenerationRun;
use App\Services\SpecEngine;
use App\Services\SpecHealthValidator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3; // BR-11: retry maks 2×

    public int $timeout = 300; // panggilan LLM per dokumen bisa >60 dtk; harus < retry_after queue

    public function __construct(public string $nodeId)
    {
    }

    public function handle(SpecEngine $engine): void
    {
        $node = GenerationNode::findOrFail($this->nodeId);
        $run = $node->run;
        $project = $run->project;

        if ($node->status === 'done') {
            return; // resume tidak mengulang node selesai (BR-11)
        }

        $node->update(['status' => 'running', 'attempt' => $node->attempt + 1]);
        if ($run->status !== 'running') {
            $run->update(['status' => 'running', 'started_at' => $run->started_at ?? now()]);
            $project->update(['status' => 'generating']);
        }

        // Konteks upstream (BR-52 area — prompt caching di driver nyata)
        $upstream = [];
        foreach ($node->depends_on ?? [] as $depKey) {
            $doc = $project->documents()->where('doc_key', $depKey)->first();
            if ($doc?->current_version_id) {
                $upstream[$depKey] = $doc->currentVersion->content_md;
            }
        }

        // FR-07 streaming: buffer progresif → cache, dipoll wizard step generate
        $streamKey = 'genstream:'.$run->id;
        $lastWrite = 0.0;
        $onDelta = function (string $acc) use ($streamKey, $node, &$lastWrite) {
            if (microtime(true) - $lastWrite < 0.3) {
                return; // throttle tulisan cache
            }
            $lastWrite = microtime(true);
            \Illuminate\Support\Facades\Cache::put($streamKey, ['doc_key' => $node->doc_key, 'text' => $acc], 600);
        };

        [$md, $meta] = $engine->generateDocument($project, $node->doc_key, $upstream, $onDelta);
        \Illuminate\Support\Facades\Cache::forget($streamKey);

        $document = Document::firstOrCreate(
            ['project_id' => $project->id, 'doc_key' => $node->doc_key],
            ['title' => $node->doc_key.'.md']
        );
        $versionNo = ($document->versions()->max('version_no') ?? 0) + 1;
        $version = $document->versions()->create([
            'version_no' => $versionNo,
            'content_md' => $md,
            'source' => 'ai',
            'generated_meta' => $meta, // BR-12
        ]);
        $document->update(['current_version_id' => $version->id]);

        $node->update(['status' => 'done']);

        // Node terakhir → finalisasi run + Spec Health (FR-11)
        if (! $run->nodes()->where('status', '!=', 'done')->exists()) {
            $run->update(['status' => 'done', 'finished_at' => now()]);
            $project->update(['status' => 'ready', 'wizard_step' => 'done']);
            app(SpecHealthValidator::class)->run($project);
        }
    }

    public function failed(?\Throwable $e): void
    {
        $node = GenerationNode::find($this->nodeId);
        if ($node) {
            $node->update(['status' => 'error', 'error_text' => $e?->getMessage()]);
            $node->run->update(['status' => 'paused']); // paused-error (USER_FLOWS 8)
        }
    }
}
