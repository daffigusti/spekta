<?php

namespace App\Jobs;

use App\Models\GenerationRun;
use App\Services\SpecEngine;
use App\Services\SpecHealthValidator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * FR-11 auto-repair SATU pass: temuan critical Spec Health dikirim balik ke LLM
 * untuk diperbaiki per dokumen, lalu validator jalan ulang. repaired_at mencegah loop.
 */
class RepairRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1; // best-effort — repair gagal jangan mengulang biaya token

    public int $timeout = 600; // bisa beberapa dokumen berurutan

    public function __construct(public string $runId) {}

    public function handle(SpecEngine $engine): void
    {
        $run = GenerationRun::findOrFail($this->runId);
        if ($run->repaired_at) {
            return;
        }
        $run->update(['repaired_at' => now()]);
        $project = $run->project;

        // location format "DOCKEY" atau "DOCKEY / FR-xx" → kelompok per dokumen
        $byDoc = $project->healthFindings()->where('severity', 'critical')->get()
            ->groupBy(fn ($f) => trim(explode('/', $f->location)[0]));

        $repairedAny = false;
        foreach ($byDoc as $docKey => $findings) {
            $document = $project->documents()->where('doc_key', $docKey)->first();
            if (! $document?->current_version_id) {
                continue;
            }
            $current = $document->currentVersion->content_md;
            [$md, $meta] = $engine->repairDocument(
                $project,
                $docKey,
                $findings->map(fn ($f) => ['severity' => $f->severity, 'message' => $f->message, 'suggestion' => $f->suggestion])->all(),
                $current
            );
            if (trim($md) === '' || trim($md) === trim($current)) {
                continue; // tidak ada perubahan (mis. stub) — jangan bikin versi kosong/duplikat
            }
            $version = $document->versions()->create([
                'version_no' => ($document->versions()->max('version_no') ?? 0) + 1,
                'content_md' => $md,
                'source' => 'ai',
                'language' => $project->primaryLanguage(), // FR-12: default kolom 'id' salah utk proyek EN
                'generated_meta' => $meta,
            ]);
            $document->update(['current_version_id' => $version->id]);
            $repairedAny = true;
        }

        if ($repairedAny) {
            app(SpecHealthValidator::class)->run($project);
        }
    }
}
