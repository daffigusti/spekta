<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        // User tanpa workspace (mis. dibuat via factory/OAuth) → provision otomatis
        $workspace = $request->user()->currentWorkspace()
            ?? app(\App\Services\WorkspaceProvisioner::class)->provision($request->user(), $request->user()->name);

        $projects = $workspace->projects()
            ->latest()
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'client_name' => $p->client_name,
                'status' => $p->status,
                'wizard_step' => $p->wizard_step,
                'health_score' => $p->health_score,
                'complexity' => $p->complexity,
                'doc_count' => $p->documents()->count(),
                'total_md' => (float) $p->structureNodes()->where('kind', 'feature')->sum('est_md'),
                'updated_at' => $p->updated_at->diffForHumans(),
            ]);

        return Inertia::render('dashboard', ['projects' => $projects]);
    }
}
