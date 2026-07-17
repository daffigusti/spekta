<?php

namespace App\Services;

use App\Models\Project;
use Dompdf\Dompdf;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * FR-21 subset MVP: ZIP markdown + agent pack (CLAUDE.md, .cursorrules, AGENTS.md, tasks.md)
 * + PDF gabungan seluruh dokumen.
 */
class Exporter
{
    /** PDF gabungan: satu file, satu dokumen per halaman (page break per doc_key). */
    public function pdf(Project $project): string
    {
        $sections = '';
        foreach ($project->documents()->with('currentVersion')->get() as $i => $doc) {
            if (! $doc->currentVersion) {
                continue;
            }
            $break = $i > 0 ? 'page-break-before: always;' : '';
            $title = Str::of($doc->doc_key)->replace(['_', '-'], ' ')->upper();
            // ponytail: blok mermaid tampil sebagai code block; render ke gambar butuh Browsershot
            $body = Str::markdown($doc->currentVersion->content_md, [
                'html_input' => 'strip',
                'allow_unsafe_links' => false,
            ]);
            $sections .= "<section style=\"$break\"><div class=\"doc-title\">$title</div>$body</section>";
        }

        $html = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="utf-8"><style>
    @page { margin: 60px 50px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2937; line-height: 1.55; }
    .doc-title { font-size: 9px; font-weight: bold; letter-spacing: 2px; color: #0d9488; border-bottom: 2px solid #0d9488; padding-bottom: 4px; margin-bottom: 14px; }
    h1 { font-size: 17px; } h2 { font-size: 14px; } h3 { font-size: 12px; } h4, h5, h6 { font-size: 10px; }
    h1, h2, h3 { color: #134e4a; }
    table { border-collapse: collapse; width: 100%; margin: 8px 0; }
    th, td { border: 1px solid #d1d5db; padding: 4px 6px; text-align: left; }
    th { background: #f0fdfa; }
    pre { background: #f3f4f6; padding: 8px; font-size: 9px; white-space: pre-wrap; word-wrap: break-word; }
    code { font-family: DejaVu Sans Mono, monospace; font-size: 9px; }
    blockquote { border-left: 3px solid #99f6e4; margin-left: 0; padding-left: 10px; color: #4b5563; }
</style></head><body>$sections</body></html>
HTML;

        $dompdf = new Dompdf(['isRemoteEnabled' => false]);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4');
        $dompdf->render();

        $dir = storage_path('app/exports');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $path = $dir.'/'.$project->id.'-pdf-'.time().'.pdf';
        file_put_contents($path, $dompdf->output());

        return $path;
    }

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
