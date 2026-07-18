<?php

namespace App\Services;

use App\Models\Estimate;
use App\Models\EstimateLine;
use App\Models\Project;
use App\Models\RateCard;
use Illuminate\Support\Collection;

/**
 * FR-14 + BR-20/BR-21/BR-22.
 * Bottom-up: MD fitur = Σ sub-fitur (atau est fitur bila tanpa sub) + overhead integrasi 10%.
 * Baris buffer "setup, deploy, UAT & buffer" 15% dari total.
 * RAB = Σ (MD per peran × tarif) × (1 + margin). Distribusi peran: config spekta.estimate.role_split.
 */
class Estimator
{
    /** @return array<string, float> */
    public static function roleSplit(): array
    {
        return config('spekta.estimate.role_split');
    }

    /**
     * Multiplier efektif mode pengerjaan: impl_multiplier hanya kena porsi FE+BE;
     * QA & PM tetap 1.0× (review kode AI & komunikasi klien tidak ikut cepat).
     * est_md dari AI = baseline konvensional (prompt buildStructure).
     */
    public static function effectiveMultiplier(string $mode): float
    {
        $impl = (float) (config("spekta.estimate.work_modes.$mode.impl_multiplier") ?? 1.0);
        $split = self::roleSplit();
        $implShare = ($split['FE'] ?? 0) + ($split['BE'] ?? 0);

        return round($implShare * $impl + (1 - $implShare), 3);
    }

    public function compute(Project $project, string $scope, ?RateCard $rateCard = null): Estimate
    {
        $cfg = config('spekta.estimate');
        $rateCard ??= $project->workspace->rateCards()->where('is_default', true)->first();
        $rates = collect($rateCard?->roles ?? [])->keyBy('role');
        $margin = ($rateCard?->margin_pct ?? 0) / 100;

        $mode = $project->blueprint['work_mode'] ?? 'conservative';
        $mult = self::effectiveMultiplier($mode);

        $nodes = $project->structureNodes;
        // Parked selalu keluar — timeline & proposal juga mengecualikannya, total harus rekonsiliasi
        $features = $nodes->where('kind', 'feature')->filter(
            fn ($f) => $f->scope !== 'parked' && ($scope === 'full' || $f->scope === 'mvp')
        );

        $estimate = Estimate::updateOrCreate(
            ['project_id' => $project->id, 'scope' => $scope],
            [
                'rate_card_snapshot' => $rateCard?->only(['name', 'currency', 'roles', 'margin_pct']),
                'currency' => $rateCard?->currency ?? 'IDR',
                'range_pct' => config("spekta.estimate.work_modes.$mode.range_pct") ?? $cfg['confidence_range_pct'],
                'work_mode' => $mode,
                'status' => 'draft',
            ]
        );
        $estimate->lines()->delete();

        $baselineMd = 0.0;

        foreach ($features as $feature) {
            $subMd = $nodes->where('parent_id', $feature->id)->sum('est_md');
            $mdBase = ($subMd > 0 ? $subMd : $feature->est_md) * (1 + $cfg['integration_overhead_pct'] / 100);
            $baselineMd += $mdBase;
            $md = round($mdBase * $mult, 2);

            [$roleBreakdown, $cost] = $this->costOf($md, $rates, $margin);

            $estimate->lines()->create([
                'structure_node_id' => $feature->id,
                'md' => $md,
                'ai_md' => $md, // pembanding warning override <50%
                'role_breakdown' => $roleBreakdown,
                'cost' => $cost,
            ]);
        }

        // baseline_md = estimasi konvensional AI (invarian terhadap override manual)
        $baselineMd *= 1 + $cfg['buffer_pct'] / 100;
        $estimate->update(['baseline_md' => round($baselineMd, 1)]);

        $this->refreshTotals($estimate);

        return $estimate->fresh('lines');
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

        $this->refreshTotals($estimate);
    }

    /**
     * FR-15: satu jalur untuk compute & override — total, komposisi tim, durasi,
     * dan timeline semua diturunkan dari estimate lines, jadi override manual
     * otomatis mengalir ke Gantt.
     */
    private function refreshTotals(Estimate $estimate): void
    {
        $cfg = config('spekta.estimate');
        $rates = collect($estimate->rate_card_snapshot['roles'] ?? [])->keyBy('role');
        $margin = ($estimate->rate_card_snapshot['margin_pct'] ?? 0) / 100;
        $capacity = $cfg['parallel_tracks'] * $cfg['days_per_week']; // MD per minggu

        $lines = $estimate->lines()->with('structureNode')->get();
        $linesMd = (float) $lines->sum('md');
        $linesCost = (float) $lines->sum('cost');

        // BR-20: baris buffer
        $bufferMd = $linesMd * $cfg['buffer_pct'] / 100;
        [, $bufferCost] = $this->costOf($bufferMd, $rates, $margin);
        $totalMd = $linesMd + $bufferMd;

        $estimate->update([
            'total_md' => round($totalMd, 1),
            'total_cost' => round($linesCost + $bufferCost),
            'team_composition' => collect(self::roleSplit())
                ->map(fn ($pct, $role) => ['role' => $role, 'md' => round($totalMd * $pct, 1)])
                ->values()->all(),
            'duration_weeks' => round($totalMd / $capacity, 1),
            'timeline' => $this->timeline($estimate->project, $lines, $bufferMd, $capacity),
        ]);
    }

    /**
     * FR-15: gantt sederhana — MD per fase dari estimate lines (bukan structure nodes,
     * supaya override ikut terbaca), fase berurutan, slot UAT & buffer 15% di akhir.
     * ponytail: fase strictly sequential; overlap antar fase nanti kalau perlu.
     *
     * @param  Collection<int, EstimateLine>  $lines
     * @return array<int, array{label: string, start_week: float, weeks: float, md: float, kind: string}>
     */
    private function timeline(Project $project, $lines, float $bufferMd, int $capacity): array
    {
        $phases = $project->structureNodes->where('kind', 'phase')->sortBy('phase_no')->values();
        $mdByPhase = $lines->groupBy(fn ($l) => $l->structureNode?->parent_id)->map(fn ($g) => $g->sum('md'));
        $weeksOf = fn (float $md) => max(round($md / $capacity, 1), 0.5);

        $timeline = [];
        $cursor = 0.0;
        foreach ($phases as $phase) {
            $md = (float) ($mdByPhase[$phase->id] ?? 0);
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

    /** @return array{0: array, 1: float} */
    private function costOf(float $md, $rates, float $margin): array
    {
        $breakdown = [];
        $cost = 0.0;
        foreach (self::roleSplit() as $role => $pct) {
            $roleMd = $md * $pct;
            $rate = (float) ($rates[$role]['daily_rate'] ?? 0);
            $breakdown[] = ['role' => $role, 'md' => round($roleMd, 2)];
            $cost += $roleMd * $rate;
        }

        return [$breakdown, round($cost * (1 + $margin))]; // BR-21
    }
}
