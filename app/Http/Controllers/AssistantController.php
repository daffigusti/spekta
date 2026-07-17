<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\Request;

/** FR-09 (subset chat) + kuota AI chat per paket (BR-01). */
class AssistantController extends Controller
{
    public function chat(Request $request, Project $project)
    {
        ProjectController::authorizeProject($request, $project);
        $data = $request->validate([
            'message' => 'required|string|max:2000',
            'doc_key' => 'nullable|string|max:40',
            'screen' => 'nullable|string|max:120', // layar wireframe terpilih di canvas
        ]);

        // BR-01: kuota chat per bulan sesuai paket (null = unlimited)
        $workspace = $project->workspace;
        $plan = $workspace->subscription?->plan ?? 'free';
        $quota = config("spekta.plans.{$plan}.ai_chats_per_month");
        if ($quota !== null) {
            $used = \App\Models\AssistantMessage::whereIn('project_id', $workspace->projects()->pluck('id'))
                ->where('role', 'user')->where('created_at', '>=', now()->startOfMonth())->count();
            if ($used >= $quota) {
                return back()->withErrors(['assistant' => "Kuota AI chat bulan ini habis ({$quota}/bln paket ".ucfirst($plan).'). Upgrade untuk lanjut.']);
            }
        }

        $project->assistantMessages()->create(['role' => 'user', 'body' => $data['message']]);
        \App\Jobs\AssistantChatJob::dispatch($project, $data['message'], $data['doc_key'] ?? null, $data['screen'] ?? null);

        return back();
    }
}
