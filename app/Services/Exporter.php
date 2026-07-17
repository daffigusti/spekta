<?php

namespace App\Services;

use App\Models\Project;
use ZipArchive;

/**
 * FR-21 subset MVP: ZIP markdown + agent pack (CLAUDE.md, .cursorrules, AGENTS.md, tasks.md).
 */
class Exporter
{
    public function zip(Project $project, string $kind): string
    {
        $dir = storage_path('app/exports');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $path = $dir.'/'.$project->id.'-'.$kind.'-'.time().'.zip';

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $docs = $project->documents()->with('currentVersion')->get();
        foreach ($docs as $doc) {
            if ($doc->currentVersion) {
                $zip->addFromString($doc->doc_key.'.md', $doc->currentVersion->content_md);
            }
        }

        if ($kind === 'agent_pack') {
            foreach ($this->agentPack($project) as $file => $content) {
                $zip->addFromString($file, $content);
            }
        }

        $zip->close();

        return $path;
    }

    private function agentPack(Project $project): array
    {
        $stack = $project->stackChoices->map(fn ($s) => "- **{$s->layer}**: {$s->choice} — {$s->justification}")->implode("\n");
        $docsList = $project->documents->pluck('doc_key')->map(fn ($k) => "- `$k.md`")->implode("\n");
        $assumptions = collect($project->assumptions())->map(fn ($a) => "- $a")->implode("\n");

        $summary = <<<MD
# {$project->name}

Proyek: {$project->name} (klien: {$project->client_name})
Digenerate oleh Spekta — spec lengkap ada di file markdown terlampir.

## Tech Stack

$stack

## Dokumen Rujukan

$docsList

## Asumsi Proyek

$assumptions

## Konvensi

- Ikuti acceptance criteria di REQUIREMENTS.md — jangan improvisasi scope.
- Setiap FR harus selesai dengan skenario uji di TESTING.md hijau.
- Perubahan scope memerlukan Change Request — jangan implementasi di luar spec.
MD;

        $tasks = "# tasks.md — breakdown eksekusi\n\n";
        foreach (app(SpecEngine::class)->structureArray($project) as $phase) {
            $tasks .= "## {$phase['phase']}\n\n";
            foreach ($phase['features'] as $f) {
                $tasks .= "- [ ] **{$f['title']}** ({$f['est_md']} MD, scope {$f['scope']})\n";
                foreach ($f['subfeatures'] as $sub) {
                    $tasks .= "  - [ ] {$sub['title']}\n";
                }
            }
            $tasks .= "\n";
        }

        return [
            'CLAUDE.md' => $summary,
            'AGENTS.md' => $summary,
            '.cursorrules' => $summary,
            'tasks.md' => $tasks,
        ];
    }
}
