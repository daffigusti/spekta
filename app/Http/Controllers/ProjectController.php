<?php

namespace App\Http\Controllers;

use App\Jobs\ContradictionCheckJob;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;

class ProjectController extends Controller
{
    public function store(Request $request)
    {
        $workspace = $request->user()->currentWorkspace();

        // FR-16: proyek baru mengikuti template perusahaan default
        $project = $workspace->projects()->create([
            'name' => 'Proyek '.now()->format('d M H:i'),
            'created_by' => $request->user()->id,
            'doc_template_id' => $workspace->defaultDocTemplate()->id,
        ]);

        return to_route('projects.wizard', $project);
    }

    public function show(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);

        // Urut sesuai pipeline (PRD → … → ROADMAP), bukan alfabetis; nomor untuk sidebar
        $order = array_flip(array_keys(config('spekta.doc_pipeline')));
        // WIREFRAMES content JSON — tampil di canvas /wireframes, bukan daftar dokumen markdown
        $documents = $project->documents()->with('currentVersion')->where('doc_key', '!=', 'WIREFRAMES')->get()
            ->sortBy(fn ($d) => $order[$d->doc_key] ?? 99)->values()->map(fn ($d, $i) => [
                'id' => $d->id,
                'seq' => $i + 1,
                'doc_key' => $d->doc_key,
                'title' => $d->title,
                'status' => $d->status,
                'version_no' => $d->currentVersion?->version_no,
                'content_md' => $d->currentVersion?->content_md,
                'generated_meta' => $d->currentVersion?->generated_meta,
                // Riwayat versi hanya bahasa primer — baris varian terjemahan lama (fitur sudah dicabut) tidak ikut
                'versions' => $d->versions()->where('language', $project->primaryLanguage())->get(['id', 'version_no', 'source', 'created_at'])->map(fn ($v) => [
                    'id' => $v->id, 'version_no' => $v->version_no, 'source' => $v->source,
                    'created_at' => $v->created_at->format('d M Y H:i'),
                ]),
            ]);

        return Inertia::render('project', [
            'project' => $project->only(['id', 'name', 'client_name', 'status', 'health_score', 'scope_mode', 'complexity']),
            'documents' => $documents,
            'findings' => $project->healthFindings()->where('resolved', false)->get(),
            'run' => $project->generationRuns()->with('nodes')->latest()->first(),
            'missing_doc_keys' => array_values(array_diff(array_keys(config('spekta.doc_pipeline')), $project->documents()->pluck('doc_key')->all())),
            'share_links' => $project->shareLinks()->latest()->get()->map(fn ($l) => [
                'id' => $l->id,
                'url' => route('portal.show', $l->token),
                'approver_email' => $l->approver_email,
                'expires_at' => $l->expires_at->format('d M Y'),
                'active' => $l->isActive(),
                'approvals_count' => $l->approvals()->count(),
                'doc_count' => count($l->doc_keys),
            ]),
            'assistant_messages' => $project->assistantMessages()->latest()->limit(30)->get()->reverse()->values()
                ->map(fn ($m) => ['id' => $m->id, 'role' => $m->role, 'body' => $m->body]),
            'chat_stream' => Cache::get('chatstream:'.$project->id),
            'chat_quota' => $project->workspace->chatQuota(),
            // FR-11(f): status tombol Cek kontradiksi — running dari lock job, kuota untuk label sisa
            'contradiction' => [
                'running' => Cache::has(ContradictionCheckJob::lockKey($project->id)),
                'quota' => $project->workspace->contradictionQuota(),
            ],
            'change_requests' => $project->changeRequests()->orderByDesc('number')->get()->map(fn ($cr) => [
                'id' => $cr->id,
                'label' => $cr->label(),
                'title' => $cr->title,
                'source' => $cr->source,
                'requested_by' => $cr->requested_by,
                'status' => $cr->status,
                'delta_md' => $cr->delta_md,
                'delta_cost' => $cr->delta_cost,
                'affected_doc_keys' => $cr->affected_doc_keys,
            ]),
            'baselines' => $project->baselines()->latest('number')->get(['id', 'number', 'hash', 'approver_email', 'approved_at'])
                ->map(fn ($b) => [
                    'id' => $b->id, 'number' => $b->number, 'hash' => substr($b->hash, 0, 12),
                    'approver_email' => $b->approver_email, 'approved_at' => $b->approved_at->format('d M Y H:i'),
                ]),
        ]);
    }

    /** Canvas wireframe (FR-07 WIREFRAMES) — content JSON dirender frame low-fi per user flow. */
    public function wireframes(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);

        $doc = $project->documents()->with('currentVersion')->where('doc_key', 'WIREFRAMES')->first();

        return Inertia::render('wireframes', [
            'project' => $project->only(['id', 'name', 'client_name', 'status']),
            'document' => $doc ? [
                'id' => $doc->id,
                'doc_key' => $doc->doc_key,
                'title' => $doc->title,
                'version_no' => $doc->currentVersion?->version_no,
                'content_md' => $doc->currentVersion?->content_md,
                'versions' => $doc->versions()->get(['id', 'version_no', 'source', 'created_at'])->map(fn ($v) => [
                    'id' => $v->id, 'version_no' => $v->version_no, 'source' => $v->source,
                    'created_at' => $v->created_at->format('d M Y H:i'),
                ]),
            ] : null,
            'run' => $project->generationRuns()->with('nodes')->latest()->first(),
            'assistant_messages' => $project->assistantMessages()->latest()->limit(30)->get()->reverse()->values()
                ->map(fn ($m) => ['id' => $m->id, 'role' => $m->role, 'body' => $m->body]),
            'chat_stream' => Cache::get('chatstream:'.$project->id),
            'chat_quota' => $project->workspace->chatQuota(),
        ]);
    }

    public function update(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);
        $project->update($request->validate([
            'name' => 'required|string|max:120',
            'client_name' => 'nullable|string|max:120',
        ]));

        return back();
    }

    public function destroy(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);
        abort_if(in_array($project->status, ['shared', 'approved']), 403, 'Proyek shared/approved tidak dapat dihapus (BR-29).');
        $project->delete();

        return to_route('dashboard');
    }

    /** FR-11(f): trigger manual cek kontradiksi — dispatch job LLM, wajib guard billing (BR-05/BR-02). */
    public function checkContradictions(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);
        $workspace = $project->workspace;
        $workspace->assertAiAllowed();

        // BR-01: kuota bulanan terpisah — panggilan LLM reasoning termahal, jangan cuma gate kredit
        $quota = $workspace->contradictionQuota();
        if ($quota['limit'] !== null && $quota['used'] >= $quota['limit']) {
            return back()->withErrors([
                'contradiction' => "Kuota cek kontradiksi bulan ini habis ({$quota['used']}/{$quota['limit']}). Upgrade paket untuk menambah.",
            ]);
        }

        // Anti dobel-dispatch: lock dilepas job saat selesai/gagal; TTL 600 > timeout job 540
        if (! Cache::add(ContradictionCheckJob::lockKey($project->id), true, 600)) {
            return back(); // pemeriksaan masih berjalan
        }
        $workspace->recordContradictionCheck();
        ContradictionCheckJob::dispatch($project->id);

        return back();
    }

    public static function authorizeProject(Request $request, Project $project): void
    {
        $workspace = $request->user()->currentWorkspace();
        abort_unless($workspace && $project->workspace_id === $workspace->id, 403);
    }
}
