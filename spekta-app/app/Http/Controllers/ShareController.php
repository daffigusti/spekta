<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\ShareLink;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** FR-17 sisi internal: buat & cabut share-link portal klien. */
class ShareController extends Controller
{
    public function store(Request $request, Project $project)
    {
        ProjectController::authorizeProject($request, $project);
        // BR-30: empat mata — hanya Owner/Admin yang boleh menandai internal review & share
        $role = $project->workspace->members()->where('user_id', $request->user()->id)->value('role');
        abort_unless(in_array($role, ['owner', 'admin'], true), 403, 'Hanya Owner/Admin yang dapat share ke klien.');
        $data = $request->validate([
            'approver_email' => 'required|email',
            'contact_emails' => 'nullable|array|max:4', // BR-40: total maks 5 kontak termasuk approver
            'contact_emails.*' => 'email',
            'doc_keys' => 'required|array|min:1',
            'doc_keys.*' => 'string',
            'expires_days' => 'nullable|integer|min:1|max:90',
            'internal_review_done' => 'accepted', // BR-30: empat mata sebelum share
        ]);

        abort_unless($project->documents()->exists(), 422, 'Belum ada dokumen untuk dibagikan.');

        $link = $project->shareLinks()->create([
            'token' => Str::random(48),
            'approver_email' => $data['approver_email'],
            'contact_emails' => $data['contact_emails'] ?? [],
            'doc_keys' => $data['doc_keys'],
            'expires_at' => now()->addDays($data['expires_days'] ?? 30),
            'created_by' => $request->user()->id,
        ]);

        $project->update(['status' => 'shared']); // FR-19: Draft → Shared

        AuditLog::create([
            'workspace_id' => $project->workspace_id,
            'actor_id' => $request->user()->id,
            'action' => 'share_link.created',
            'entity_type' => 'share_link',
            'entity_id' => $link->id,
        ]);

        return back();
    }

    /** BR-28: revoke tidak menghapus komentar/approval yang sudah terjadi. */
    public function revoke(Request $request, Project $project, ShareLink $link)
    {
        ProjectController::authorizeProject($request, $project);
        abort_unless($link->project_id === $project->id, 404);

        $link->update(['revoked_at' => now()]);

        AuditLog::create([
            'workspace_id' => $project->workspace_id,
            'actor_id' => $request->user()->id,
            'action' => 'share_link.revoked',
            'entity_type' => 'share_link',
            'entity_id' => $link->id,
        ]);

        return back();
    }
}
