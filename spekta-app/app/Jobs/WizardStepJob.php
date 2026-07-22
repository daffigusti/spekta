<?php

namespace App\Jobs;

use App\Models\Project;
use App\Services\SpecEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Step wizard berat (panggilan LLM lama) berjalan async di queue:
 * buildStructure (FR-04, dari finishInterview) & recommendStack (FR-06, dari confirmStructure).
 *
 * Status dilaporkan via cache wizardstep:{projectId} dan dipoll halaman wizard —
 * pola sama dengan genstream:{runId} di step generate (FR-07). Sukses = cache dihapus
 * dan project.wizard_step maju; frontend mendeteksi lewat perubahan wizard_step.
 */
class WizardStepJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1; // kegagalan LLM deterministik — retry manual dari UI, bukan otomatis

    public int $timeout = 540; // harus < queue:listen --timeout (600) < retry_after (630)

    public function __construct(public string $projectId, public string $step) {}

    public static function statusKey(string $projectId): string
    {
        return 'wizardstep:'.$projectId;
    }

    public function handle(SpecEngine $engine): void
    {
        $project = Project::findOrFail($this->projectId);
        $key = self::statusKey($project->id);
        Cache::put($key, ['status' => 'running', 'step' => $this->step], 900);

        try {
            $this->step === 'structure'
                ? $this->buildStructure($project, $engine)
                : $this->recommendStack($project, $engine);
            Cache::forget($key);
        } catch (\Throwable $e) {
            Cache::put($key, [
                'status' => 'error', 'step' => $this->step,
                'error' => mb_substr($e->getMessage(), 0, 300),
            ], 900);
            throw $e;
        }
    }

    public function failed(?\Throwable $e): void
    {
        // Timeout / worker mati: handle() tidak sempat menulis status error
        Cache::put(self::statusKey($this->projectId), [
            'status' => 'error', 'step' => $this->step,
            'error' => mb_substr($e?->getMessage() ?? 'Job berhenti tanpa pesan.', 0, 300),
        ], 900);
    }

    /** FR-04: bangun struktur awal dari AI (dipindah dari WizardController::finishInterview). */
    private function buildStructure(Project $project, SpecEngine $engine): void
    {
        // AI dipanggil SEBELUM tulis apa pun — gagal di sini = tidak ada state parsial
        $phases = $engine->buildStructure($project);
        if (! $phases) {
            throw new \RuntimeException('AI gagal menyusun struktur — coba lagi.');
        }

        DB::transaction(function () use ($project, $phases) {
            $project->structureNodes()->delete(); // bersihkan sisa parsial (root yatim)
            $root = $project->structureNodes()->create(['kind' => 'root', 'title' => $project->name, 'sort' => 0]);
            foreach ($phases as $pi => $phase) {
                $phaseNode = $project->structureNodes()->create([
                    'kind' => 'phase', 'parent_id' => $root->id, 'title' => $phase['title'],
                    'phase_no' => $pi + 1, 'sort' => $pi,
                ]);
                foreach ($phase['features'] ?? [] as $fi => $f) {
                    $featureNode = $project->structureNodes()->create([
                        'kind' => 'feature', 'parent_id' => $phaseNode->id, 'title' => $f['title'],
                        'description' => $f['description'] ?? null, 'scope' => $f['scope'] ?? 'mvp',
                        'est_md' => $f['est_md'] ?? 0, 'sort' => $fi,
                    ]);
                    foreach ($f['subfeatures'] ?? [] as $si => $sub) {
                        $subNode = $project->structureNodes()->create([
                            'kind' => 'subfeature', 'parent_id' => $featureNode->id,
                            'title' => is_array($sub) ? $sub['title'] : $sub,
                            'description' => is_array($sub) ? ($sub['description'] ?? null) : null,
                            'est_md' => is_array($sub) ? ($sub['est_md'] ?? 0) : 0, 'sort' => $si,
                        ]);
                        // FR-04: task per sub-fitur — 'tasks' hilang/kosong = sub-fitur tetap leaf
                        foreach ((is_array($sub) ? ($sub['tasks'] ?? []) : []) as $ti => $t) {
                            $project->structureNodes()->create([
                                'kind' => 'task', 'parent_id' => $subNode->id,
                                'title' => $t['title'] ?? 'Task',
                                'description' => $t['description'] ?? null,
                                'est_md' => $t['est_md'] ?? 0, 'sort' => $ti,
                            ]);
                        }
                    }
                }
            }
        });

        $project->update(['wizard_step' => 'structure']);
    }

    /** FR-06: rekomendasi stack (dipindah dari WizardController::confirmStructure). */
    private function recommendStack(Project $project, SpecEngine $engine): void
    {
        foreach ($engine->recommendStack($project) as $layer) {
            $project->stackChoices()->create([
                'layer' => $layer['layer'],
                'choice' => $layer['choice'],
                'justification' => $layer['justification'] ?? null,
                'alternatives' => $layer['alternatives'] ?? [],
                'source' => 'ai',
            ]);
        }

        $project->update(['wizard_step' => 'stack']);
    }
}
