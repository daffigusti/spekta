<?php

namespace App\Services;

use App\Models\Project;

/**
 * FR-11 aturan inti:
 * (a) tiap FR punya acceptance criteria di REQUIREMENTS (+ minimal 1 butir AC di bawah headingnya)
 * (b) tiap FR muncul di ROADMAP
 * (c) tiap FR punya skenario di TESTING — critical untuk FR scope mvp (P0), warning sisanya
 * (d) PRD punya section Assumptions (BR-13)
 * (e) WIREFRAMES JSON valid + coverage flow vs USER_FLOWS
 * (f) DATABASE punya erDiagram
 * (g) SECURITY menyebut semua role (matrix akses lengkap)
 * Skor = 100 - Σ penalti (critical 15, warning 7, info 2), floor 0.
 */
class SpecHealthValidator
{
    public function run(Project $project): int
    {
        $project->healthFindings()->delete();

        $docs = $project->documents()->with('currentVersion')->get()
            ->mapWithKeys(fn ($d) => [$d->doc_key => $d->currentVersion?->content_md ?? '']);

        $findings = [];
        $prd = $docs['PRD'] ?? '';
        // Terima FR-01 maupun FR-1; "FR-1.1" otomatis tereduksi ke FR top-level FR-1
        preg_match_all('/FR-\d+/', $prd, $m);
        $frs = array_unique($m[0]);

        // FR-xx → scope: penomoran FR mengikuti urutan fitur di struktur (template PRD)
        $scopeByFr = $this->scopeByFr($project);

        foreach ($frs as $fr) {
            if (isset($docs['REQUIREMENTS']) && ! $this->mentions($docs['REQUIREMENTS'], $fr)) {
                $findings[] = ['rule_key' => 'fr_has_ac', 'severity' => 'critical', 'location' => "REQUIREMENTS / $fr",
                    'message' => "$fr tidak memiliki acceptance criteria di REQUIREMENTS.md",
                    'suggestion' => "Tambahkan section $fr dengan acceptance criteria terukur."];
            } elseif (isset($docs['REQUIREMENTS']) && ! $this->hasBulletsUnderHeading($docs['REQUIREMENTS'], $fr)) {
                $findings[] = ['rule_key' => 'fr_ac_empty', 'severity' => 'critical', 'location' => "REQUIREMENTS / $fr",
                    'message' => "Section $fr di REQUIREMENTS.md tidak berisi butir acceptance criteria",
                    'suggestion' => "Isi section $fr dengan ≥3 acceptance criteria format Given/When/Then."];
            }
            if (isset($docs['ROADMAP']) && ! $this->mentions($docs['ROADMAP'], $fr)) {
                $findings[] = ['rule_key' => 'fr_in_roadmap', 'severity' => 'warning', 'location' => "ROADMAP / $fr",
                    'message' => "$fr tidak muncul di ROADMAP.md",
                    'suggestion' => "Petakan $fr ke fase & prioritas di ROADMAP."];
            }
            if (isset($docs['TESTING']) && ! $this->mentions($docs['TESTING'], $fr)) {
                $isP0 = ($scopeByFr[$fr] ?? null) === 'mvp';
                $findings[] = ['rule_key' => 'fr_has_test', 'severity' => $isP0 ? 'critical' : 'warning', 'location' => "TESTING / $fr",
                    'message' => "$fr belum punya skenario uji di TESTING.md".($isP0 ? ' (scope MVP/P0)' : ''),
                    'suggestion' => "Tambahkan skenario uji untuk $fr."];
            }
        }

        if ($prd !== '' && ! preg_match('/#+\s*[\d.\s]*(Assumptions|Asumsi)/iu', $prd)) {
            $findings[] = ['rule_key' => 'prd_assumptions', 'severity' => 'critical', 'location' => 'PRD',
                'message' => 'Tidak memiliki section Assumptions/Asumsi (wajib per BR-13)',
                'suggestion' => 'Tambahkan section Assumptions berisi seluruh asumsi proyek.'];
        }

        if (empty($frs) && $prd !== '') {
            $findings[] = ['rule_key' => 'prd_has_fr', 'severity' => 'critical', 'location' => 'PRD',
                'message' => 'Tidak berisi functional requirement ber-nomor (FR-xx)',
                'suggestion' => 'Strukturkan kebutuhan sebagai FR-01, FR-02, …'];
        }

        if (($docs['DATABASE'] ?? '') !== '' && ! str_contains($docs['DATABASE'], 'erDiagram')) {
            $findings[] = ['rule_key' => 'db_has_erd', 'severity' => 'warning', 'location' => 'DATABASE',
                'message' => 'Tidak memiliki diagram relasi (mermaid erDiagram)',
                'suggestion' => 'Tambahkan blok ```mermaid erDiagram``` yang memuat seluruh entity.'];
        }

        foreach ($this->wireframeFindings($docs['WIREFRAMES'] ?? '', $docs['USER_FLOWS'] ?? '') as $f) {
            $findings[] = $f;
        }

        // SECURITY: matrix akses wajib mencakup semua role dari understanding
        if (($security = $docs['SECURITY'] ?? '') !== '') {
            foreach ($project->understanding?->roles ?? [] as $r) {
                $name = $r['name'] ?? '';
                if ($name !== '' && stripos($security, $name) === false) {
                    $findings[] = ['rule_key' => 'security_role_coverage', 'severity' => 'warning', 'location' => "SECURITY / $name",
                        'message' => "Role \"$name\" tidak disebut di SECURITY.md — matrix akses belum lengkap",
                        'suggestion' => "Tambahkan role $name ke Matrix Akses beserta hak aksesnya."];
                }
            }
        }

        $penalty = ['critical' => 15, 'warning' => 7, 'info' => 2];
        $score = 100;
        foreach ($findings as $f) {
            $score -= $penalty[$f['severity']];
            $project->healthFindings()->create($f);
        }
        $score = max(0, $score);
        $project->update(['health_score' => $score]);

        return $score;
    }

    /** FR disebut sebagai token utuh — "FR-1" tidak boleh cocok dengan "FR-10" (sub "FR-1.x" tetap dihitung). */
    private function mentions(string $md, string $fr): bool
    {
        return (bool) preg_match('/'.preg_quote($fr, '/').'(?!\d)/', $md);
    }

    /** FR-01 = fitur pertama urutan struktur, dst — konsisten dengan template PRD. Key padded & non-padded. */
    private function scopeByFr(Project $project): array
    {
        $nodes = $project->structureNodes;
        $map = [];
        $i = 1;
        foreach ($nodes->where('kind', 'phase') as $phase) {
            foreach ($nodes->where('parent_id', $phase->id) as $feature) {
                $map[sprintf('FR-%02d', $i)] = $feature->scope;
                $map['FR-'.$i++] = $feature->scope;
            }
        }

        return $map;
    }

    /** Ada ≥1 bullet/baris tabel di bawah heading yang memuat $fr, sebelum heading berikutnya. */
    private function hasBulletsUnderHeading(string $md, string $fr): bool
    {
        if (! preg_match('/^#{1,6}[^\n]*'.preg_quote($fr, '/').'(?![\d.])[^\n]*\n(.*?)(?=^#{1,6}\s|\z)/ms', $md, $m)) {
            return true; // FR disebut tapi bukan heading — jangan false positive
        }

        return (bool) preg_match('/^\s*([-*+]\s|\d+\.\s|\|)/m', $m[1]);
    }

    /** WIREFRAMES harus JSON valid; coverage flow dibanding jumlah flow USER_FLOWS. */
    private function wireframeFindings(string $wireframes, string $userFlows): array
    {
        if ($wireframes === '') {
            return [];
        }

        $json = json_decode($wireframes, true);
        if (! is_array($json) || ! isset($json['screens'])) {
            return [['rule_key' => 'wireframes_json', 'severity' => 'critical', 'location' => 'WIREFRAMES',
                'message' => 'Konten WIREFRAMES bukan JSON valid berskema {"screens":[…]} — canvas tidak bisa render',
                'suggestion' => 'Regenerate WIREFRAMES atau perbaiki via asisten chat.']];
        }

        $findings = [];
        $flowCount = count(array_unique(array_map(fn ($s) => $s['flow'] ?? '', $json['screens'])));
        $expected = preg_match_all('/^##\s*Flow\b/mi', $userFlows);
        if ($expected > 0 && $flowCount < $expected) {
            $findings[] = ['rule_key' => 'wireframes_flow_coverage', 'severity' => 'info', 'location' => 'WIREFRAMES',
                'message' => "Wireframe hanya mencakup $flowCount dari $expected flow di USER_FLOWS.md",
                'suggestion' => 'Lengkapi layar untuk flow yang belum tergambar.'];
        }

        return $findings;
    }
}
