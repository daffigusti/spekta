<?php

namespace App\Services;

use App\Models\Estimate;
use App\Models\Project;
use App\Models\RateCard;

/**
 * FR-14 + BR-20/BR-21/BR-22.
 * Bottom-up: MD fitur = Σ sub-fitur (atau est fitur bila tanpa sub) + overhead integrasi 10%.
 * Baris buffer "setup, deploy, UAT & buffer" 15% dari total.
 * RAB = Σ (MD per peran × tarif) × (1 + margin). Distribusi peran default: FE 35% BE 40% QA 15% PM 10%.
 */
class Estimator
{
    private const ROLE_SPLIT = ['FE' => 0.35, 'BE' => 0.40, 'QA' => 0.15, 'PM' => 0.10];

    public function compute(Project $project, string $scope, ?RateCard $rateCard = null): Estimate
    {
        $cfg = config('spekta.estimate');
        $rateCard ??= $project->workspace->rateCards()->where('is_default', true)->first();
        $rates = collect($rateCard?->roles ?? [])->keyBy('role');
        $margin = ($rateCard?->margin_pct ?? 0) / 100;

        $nodes = $project->structureNodes;
        $features = $nodes->where('kind', 'feature')->filter(
            fn ($f) => $scope === 'full' || $f->scope === 'mvp'
        );

        $estimate = Estimate::updateOrCreate(
            ['project_id' => $project->id, 'scope' => $scope],
            [
                'rate_card_snapshot' => $rateCard?->only(['name', 'currency', 'roles', 'margin_pct']),
                'currency' => $rateCard?->currency ?? 'IDR',
                'range_pct' => $cfg['confidence_range_pct'],
                'status' => 'draft',
            ]
        );
        $estimate->lines()->delete();

        $totalMd = 0.0;
        $totalCost = 0.0;

        foreach ($features as $feature) {
            $subMd = $nodes->where('parent_id', $feature->id)->sum('est_md');
            $md = ($subMd > 0 ? $subMd : $feature->est_md) * (1 + $cfg['integration_overhead_pct'] / 100);

            $existing = $estimate->lines()->where('structure_node_id', $feature->id)->first();
            [$roleBreakdown, $cost] = $this->costOf($md, $rates, $margin);

            $estimate->lines()->create([
                'structure_node_id' => $feature->id,
                'md' => round($md, 1),
                'role_breakdown' => $roleBreakdown,
                'cost' => $cost,
            ]);
            $totalMd += $md;
            $totalCost += $cost;
        }

        // BR-20: baris buffer
        $bufferMd = $totalMd * $cfg['buffer_pct'] / 100;
        [, $bufferCost] = $this->costOf($bufferMd, $rates, $margin);
        $totalMd += $bufferMd;
        $totalCost += $bufferCost;

        // Komposisi tim & durasi kasar: paralel 3 track, 5 hari/minggu
        $teamComposition = collect(self::ROLE_SPLIT)
            ->map(fn ($pct, $role) => ['role' => $role, 'md' => round($totalMd * $pct, 1)])
            ->values()->all();
        $durationWeeks = round($totalMd / (3 * 5), 1);

        $estimate->update([
            'total_md' => round($totalMd, 1),
            'total_cost' => round($totalCost),
            'team_composition' => $teamComposition,
            'duration_weeks' => $durationWeeks,
            'timeline' => $this->timeline($project, $scope, $cfg, $bufferMd),
        ]);

        return $estimate->fresh('lines');
    }

    /**
     * FR-15: gantt sederhana — fase berurutan (dependensi antar fase), slot UAT & buffer 15% di akhir.
     * ponytail: fase strictly sequential; overlap antar fase nanti kalau perlu.
     *
     * @return array<int, array{label: string, start_week: float, weeks: float, md: float, kind: string}>
     */
    private function timeline(Project $project, string $scope, array $cfg, float $bufferMd): array
    {
        $nodes = $project->structureNodes;
        $phases = $nodes->where('kind', 'phase')->sortBy('phase_no')->values();
        $weeksOf = fn (float $md) => max(round($md / 15, 1), 0.5); // 3 track paralel × 5 hari

        $timeline = [];
        $cursor = 0.0;
        foreach ($phases as $phase) {
            $md = $nodes->where('parent_id', $phase->id)->where('kind', 'feature')
                ->filter(fn ($f) => $f->scope !== 'parked' && ($scope === 'full' || $f->scope === 'mvp'))
                ->sum(function ($f) use ($nodes) {
                    $sub = $nodes->where('parent_id', $f->id)->sum('est_md');

                    return $sub > 0 ? $sub : $f->est_md;
                });
            $md *= 1 + $cfg['integration_overhead_pct'] / 100;
            if ($md <= 0) {
                continue;
            }
            $weeks = $weeksOf($md);
            $timeline[] = ['label' => $phase->title, 'start_week' => $cursor, 'weeks' => $weeks, 'md' => round($md, 1), 'kind' => 'phase'];
            $cursor += $weeks;
        }

        $timeline[] = [
            'label' => 'Setup, deploy, UAT & buffer',
            'start_week' => $cursor,
            'weeks' => $weeksOf($bufferMd),
            'md' => round($bufferMd, 1),
            'kind' => 'buffer',
        ];

        return $timeline;
    }

    public function applyOverride(Estimate $estimate, string $lineId, float $md, string $reason): void
    {
        $line = $estimate->lines()->findOrFail($lineId);
        $rates = collect($estimate->rate_card_snapshot['roles'] ?? [])->keyBy('role');
        $margin = ($estimate->rate_card_snapshot['margin_pct'] ?? 0) / 100;

        [$roleBreakdown, $cost] = $this->costOf($md, $rates, $margin);
        $line->update([
            'md' => $md,
            'cost' => $cost,
            'role_breakdown' => $roleBreakdown,
            'overridden' => true,
            'override_reason' => $reason,
        ]);

        $this->retotal($estimate);
    }

    private function retotal(Estimate $estimate): void
    {
        $cfg = config('spekta.estimate');
        $rates = collect($estimate->rate_card_snapshot['roles'] ?? [])->keyBy('role');
        $margin = ($estimate->rate_card_snapshot['margin_pct'] ?? 0) / 100;

        $linesMd = (float) $estimate->lines()->sum('md');
        $linesCost = (float) $estimate->lines()->sum('cost');
        $bufferMd = $linesMd * $cfg['buffer_pct'] / 100;
        [, $bufferCost] = $this->costOf($bufferMd, $rates, $margin);

        $estimate->update([
            'total_md' => round($linesMd + $bufferMd, 1),
            'total_cost' => round($linesCost + $bufferCost),
            'duration_weeks' => round(($linesMd + $bufferMd) / 15, 1),
        ]);
    }

    /** @return array{0: array, 1: float} */
    private function costOf(float $md, $rates, float $margin): array
    {
        $breakdown = [];
        $cost = 0.0;
        foreach (self::ROLE_SPLIT as $role => $pct) {
            $roleMd = $md * $pct;
            $rate = (float) ($rates[$role]['daily_rate'] ?? 0);
            $breakdown[] = ['role' => $role, 'md' => round($roleMd, 2)];
            $cost += $roleMd * $rate;
        }

        return [$breakdown, round($cost * (1 + $margin))]; // BR-21
    }
}
