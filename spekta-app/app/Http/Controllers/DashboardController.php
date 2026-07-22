<?php

namespace App\Http\Controllers;

use App\Models\Document;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        // Null tidak mungkin: middleware EnsureWorkspace provision otomatis
        $workspace = $request->user()->currentWorkspace();

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

    // Pencarian global header (⌘K): proyek + dokumen dalam workspace aktif
    public function search(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['projects' => [], 'documents' => []]);
        }

        $workspace = $request->user()->currentWorkspace();
        $like = '%'.$q.'%';

        $projects = $workspace->projects()
            ->where(fn ($w) => $w->whereLike('name', $like)->orWhereLike('client_name', $like))
            ->latest()
            ->limit(8)
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'client_name' => $p->client_name,
                'url' => route('projects.show', $p),
            ]);

        $documents = Document::query()
            ->whereHas('project', fn ($w) => $w->where('workspace_id', $workspace->id))
            ->where(fn ($w) => $w->whereLike('title', $like)->orWhereLike('doc_key', $like))
            ->with('project:id,name')
            ->latest('updated_at')
            ->limit(8)
            ->get()
            ->map(fn ($d) => [
                'id' => $d->id,
                'title' => $d->title,
                'doc_key' => $d->doc_key,
                'project_name' => $d->project->name,
                'url' => route('projects.documents.show', [$d->project_id, $d->doc_key]),
            ]);

        return response()->json(['projects' => $projects, 'documents' => $documents]);
    }
}
