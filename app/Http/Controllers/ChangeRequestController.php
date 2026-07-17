<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\ChangeRequestService;
use Illuminate\Http\Request;

/** FR-20 sisi internal: buat CR, isi impact (delta MD + dokumen terdampak), tolak. */
class ChangeRequestController extends Controller
{
    public function store(Request $request, Project $project, ChangeRequestService $service)
    {
        ProjectController::authorizeProject($request, $project);
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'delta_md' => 'nullable|numeric',
            'affected_doc_keys' => 'nullable|array',
            'affected_doc_keys.*' => 'string',
        ]);

        $service->create($project, $data + [
            'source' => 'team',
            'requested_by' => $request->user()->email,
        ]);

        return back();
    }

    public function update(Request $request, Project $project, string $crId, ChangeRequestService $service)
    {
        ProjectController::authorizeProject($request, $project);
        $cr = $project->changeRequests()->where('status', 'proposed')->findOrFail($crId);
        $data = $request->validate([
            'delta_md' => 'required|numeric',
            'affected_doc_keys' => 'required|array|min:1',
            'affected_doc_keys.*' => 'string',
        ]);

        $service->setImpact($cr, (float) $data['delta_md'], $data['affected_doc_keys']);

        return back();
    }

    public function reject(Request $request, Project $project, string $crId)
    {
        ProjectController::authorizeProject($request, $project);
        $cr = $project->changeRequests()->where('status', 'proposed')->findOrFail($crId);
        $cr->update(['status' => 'rejected', 'decided_by' => $request->user()->email, 'decided_at' => now()]);

        return back();
    }
}
