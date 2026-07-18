<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\SpecEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** FR-12: buat varian bahasa untuk versi current — version_no sama, baris terpisah. */
class TranslateDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 540;

    public function __construct(public string $documentId) {}

    public function handle(SpecEngine $engine): void
    {
        $document = Document::with('currentVersion', 'project')->findOrFail($this->documentId);
        $current = $document->currentVersion;
        if (! $current) {
            return;
        }
        $target = $document->project->variantLanguage();
        if ($document->versions()->where('version_no', $current->version_no)->where('language', $target)->exists()) {
            return; // idempotent — varian versi ini sudah ada
        }

        [$md, $meta] = $engine->translate($document->project, $current, $target);
        $document->versions()->create([
            'version_no' => $current->version_no,
            'content_md' => $md,
            'source' => 'ai',
            'language' => $target,
            'generated_meta' => $meta,
        ]);
    }
}
