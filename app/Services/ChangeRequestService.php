<?php

namespace App\Services;

use App\Models\Baseline;
use App\Models\ChangeRequest;
use App\Models\Project;

/**
 * FR-20 + BR-25/BR-26: CR bernomor, delta biaya dari blended rate, approve → baseline baru.
 * ponytail: delta MD diisi manual tim (impact review) — AI impact analysis = FR-09, Fase 4.
 */
class ChangeRequestService
{

    public function create(Project $project, array $attrs): ChangeRequest
    {
        if (isset($attrs['delta_md'])) {
            $attrs['delta_cost'] = $this->costOfDelta($project, (float) $attrs['delta_md']);
        }

        return $project->changeRequests()->create($attrs + [
            'number' => ($project->changeRequests()->max('number') ?? 0) + 1,
        ]);
    }

    public function setImpact(ChangeRequest $cr, float $deltaMd, array $docKeys): void
    {
        $cr->update([
            'delta_md' => $deltaMd,
            'delta_cost' => $this->costOfDelta($cr->project, $deltaMd),
            'affected_doc_keys' => $docKeys,
        ]);
    }

    /** BR-26: approve → baseline baru (v+1), baseline lama tetap; selisih RAB = dasar penagihan. */
    public function approve(ChangeRequest $cr, string $decidedBy): Baseline
    {
        $project = $cr->project;
        $prev = $project->baselines()->orderByDesc('number')->first();

        $documents = $project->documents()->with('currentVersion')->get();
        $snapshot = [
            'documents' => $documents->map(fn ($d) => [
                'doc_key' => $d->doc_key,
                'version_id' => $d->currentVersion?->id,
                'version_no' => $d->currentVersion?->version_no,
            ])->all(),
            'total_md' => round(($prev->snapshot['total_md'] ?? 0) + $cr->delta_md, 1),
            'total_cost' => round(($prev->snapshot['total_cost'] ?? 0) + $cr->delta_cost),
            'timeline' => $prev->snapshot['timeline'] ?? null,
            'assumptions' => $project->assumptions(),
            'change_request' => $cr->label(),
        ];

        $baseline = Baseline::create([
            'project_id' => $project->id,
            'number' => ($prev?->number ?? 0) + 1,
            'snapshot' => $snapshot,
            'hash' => hash('sha256', json_encode($snapshot)),
            'approver_email' => $decidedBy,
            'approved_at' => now(),
        ]);

        $cr->update([
            'status' => 'approved',
            'decided_by' => $decidedBy,
            'decided_at' => now(),
            'baseline_id' => $baseline->id,
        ]);

        return $baseline;
    }

    /** BR-25: dokumen ter-baseline hanya boleh diubah bila tercakup CR yang masih proposed. */
    public function editAllowed(Project $project, string $docKey): bool
    {
        if ($project->status !== 'approved') {
            return true;
        }

        return $project->changeRequests()->where('status', 'proposed')
            ->get()
            ->contains(fn ($cr) => in_array($docKey, $cr->affected_doc_keys ?? []));
    }

    private function costOfDelta(Project $project, float $deltaMd): float
    {
        $card = $project->workspace->rateCards()->where('is_default', true)->first();
        $rates = collect($card?->roles ?? [])->keyBy('role');
        $margin = ($card?->margin_pct ?? 0) / 100;

        $cost = 0.0;
        foreach (Estimator::roleSplit() as $role => $pct) {
            $cost += $deltaMd * $pct * (float) ($rates[$role]['daily_rate'] ?? 0);
        }

        return round($cost * (1 + $margin));
    }
}
