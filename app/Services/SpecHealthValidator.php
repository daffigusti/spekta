<?php

namespace App\Services;

use App\Models\Project;

/**
 * FR-11 aturan inti:
 * (a) tiap FR punya acceptance criteria di REQUIREMENTS
 * (b) tiap FR muncul di ROADMAP
 * (c) tiap FR P0 punya skenario di TESTING
 * (d) PRD punya section Assumptions (BR-13)
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
        preg_match_all('/FR-\d{2}/', $prd, $m);
        $frs = array_unique($m[0]);

        foreach ($frs as $fr) {
            if (isset($docs['REQUIREMENTS']) && ! str_contains($docs['REQUIREMENTS'], $fr)) {
                $findings[] = ['rule_key' => 'fr_has_ac', 'severity' => 'critical', 'location' => "REQUIREMENTS / $fr",
                    'message' => "$fr tidak memiliki acceptance criteria di REQUIREMENTS.md",
                    'suggestion' => "Tambahkan section $fr dengan acceptance criteria terukur."];
            }
            if (isset($docs['ROADMAP']) && ! str_contains($docs['ROADMAP'], $fr)) {
                $findings[] = ['rule_key' => 'fr_in_roadmap', 'severity' => 'warning', 'location' => "ROADMAP / $fr",
                    'message' => "$fr tidak muncul di ROADMAP.md",
                    'suggestion' => "Petakan $fr ke fase & prioritas di ROADMAP."];
            }
            if (isset($docs['TESTING']) && ! str_contains($docs['TESTING'], $fr)) {
                $findings[] = ['rule_key' => 'fr_has_test', 'severity' => 'warning', 'location' => "TESTING / $fr",
                    'message' => "$fr belum punya skenario uji di TESTING.md",
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
}
