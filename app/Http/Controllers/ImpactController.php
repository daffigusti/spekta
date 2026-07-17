<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\ChangeRequestService;
use App\Services\GenerationPipeline;
use App\Services\SpecEngine;
use Illuminate\Http\Request;

/** FR-09/FR-10: analisa dampak + regen selektif. Stateless — hasil impact tidak disimpan (design §1). */
class ImpactController extends Controller
{
    public function analyze(Request $request, Project $project, SpecEngine $engine)
    {
        ProjectController::authorizeProject($request, $project);
        $data = $request->validate(['change_text' => 'required|string|max:5000']);

        return response()->json($engine->impact($project, $data['change_text']));
    }
}
