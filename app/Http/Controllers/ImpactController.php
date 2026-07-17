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

    public function regenerate(Request $request, Project $project, GenerationPipeline $pipeline, ChangeRequestService $crs)
    {
        // Autentikasi & otorisasi proyek
        ProjectController::authorizeProject($request, $project);

        // Validasi input: change_text (instruksi regenerasi) dan doc_keys (dokumen target)
        $data = $request->validate([
            'change_text' => 'required|string|max:5000',
            'doc_keys' => 'required|array|min:1',
            'doc_keys.*' => 'string',
        ]);

        // BR-25: dokumen ter-baseline hanya boleh berubah lewat CR yang mencakupnya.
        // Cek setiap doc_key apakah editAllowed() berdasarkan project status + CR coverage.
        foreach ($data['doc_keys'] as $key) {
            abort_unless($crs->editAllowed($project, $key), 403,
                "Proyek sudah di-approve — regenerasi $key wajib lewat Change Request (BR-25).");
        }

        // Mulai pipeline regenerasi dengan subset doc + instruksi
        try {
            $pipeline->startRegeneration($project, $data['doc_keys'], $data['change_text']);
        } catch (\InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return back();
    }
}
