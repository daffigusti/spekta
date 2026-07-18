<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\Estimator;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EstimateController extends Controller
{
    public function show(Request $request, Project $project, Estimator $estimator)
    {
        ProjectController::authorizeProject($request, $project);

        // Hitung kedua skenario bila belum ada (FR-05/FR-14: MVP vs Full)
        foreach (['mvp', 'full'] as $scope) {
            $existing = $project->estimates()->where('scope', $scope)->first();
            if (! $existing || $existing->timeline === null) { // timeline null = estimasi pra-FR-15
                $estimator->compute($project, $scope);
            }
        }

        $estimates = $project->estimates()->with('lines.structureNode')->get()->map(fn ($e) => [
            'id' => $e->id,
            'scope' => $e->scope,
            'total_md' => $e->total_md,
            'baseline_md' => $e->baseline_md,
            'work_mode' => $e->work_mode,
            'range_pct' => $e->range_pct,
            'total_cost' => $e->total_cost,
            'currency' => $e->currency,
            // MD-only bila rate card kosong/semua tarif 0 — UI sembunyikan kolom biaya
            'md_only' => collect($e->rate_card_snapshot['roles'] ?? [])->every(fn ($r) => ($r['daily_rate'] ?? 0) <= 0),
            'team_composition' => $e->team_composition,
            'duration_weeks' => $e->duration_weeks,
            'timeline' => $e->timeline,
            'lines' => $e->lines->map(fn ($l) => [
                'id' => $l->id,
                'feature' => $l->structureNode?->title,
                'scope' => $l->structureNode?->scope,
                'md' => $l->md,
                'ai_md' => $l->ai_md,
                'cost' => $l->cost,
                'overridden' => $l->overridden,
                'override_reason' => $l->override_reason,
            ]),
        ]);

        return Inertia::render('estimate', [
            'project' => $project->only(['id', 'name', 'client_name', 'status', 'scope_mode']),
            'estimates' => $estimates,
            'workModes' => collect(config('spekta.estimate.work_modes'))->map(fn ($m) => $m['label']),
        ]);
    }

    public function recompute(Request $request, Project $project, Estimator $estimator)
    {
        ProjectController::authorizeProject($request, $project);

        // FR-14: switcher mode pengerjaan — persist ke blueprint supaya konsisten dengan wizard
        $data = $request->validate(['work_mode' => 'sometimes|in:conservative,ai_assisted,vibe']);
        if (isset($data['work_mode'])) {
            $project->update(['blueprint' => array_merge($project->blueprint ?? [], ['work_mode' => $data['work_mode']])]);
            $project->refresh();
        }

        foreach (['mvp', 'full'] as $scope) {
            $estimator->compute($project, $scope);
        }

        return back();
    }

    public function overrideLine(Request $request, Project $project, string $estimateId, string $lineId, Estimator $estimator)
    {
        ProjectController::authorizeProject($request, $project);
        $data = $request->validate(['md' => 'required|numeric|min:0', 'reason' => 'required|string|max:500']);
        $estimate = $project->estimates()->findOrFail($estimateId);
        $estimator->applyOverride($estimate, $lineId, (float) $data['md'], $data['reason']); // FR-14 override tercatat

        return back();
    }
}
