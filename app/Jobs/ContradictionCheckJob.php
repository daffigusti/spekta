<?php

namespace App\Jobs;

use App\Models\Project;
use App\Services\SpecEngine;
use App\Services\SpecHealthValidator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** FR-11(f): cek kontradiksi via LLM, replace temuan lama, hitung ulang skor. */
class ContradictionCheckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 540;

    public function __construct(public string $projectId) {}

    public function handle(SpecEngine $engine, SpecHealthValidator $validator): void
    {
        $project = Project::findOrFail($this->projectId);
        $found = $engine->findContradictions($project);

        $project->healthFindings()->where('rule_key', 'contradiction')->delete();
        foreach ($found as $f) {
            $project->healthFindings()->create([
                'rule_key' => 'contradiction',
                'severity' => 'warning',
                'location' => $f['location'] ?? null,
                'message' => $f['message'] ?? '-',
                'suggestion' => $f['suggestion'] ?? null,
            ]);
        }
        $validator->recomputeScore($project);
    }
}
