<?php

namespace App\Http\Controllers;

use App\Models\CreditLedger;
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

        $workspace = $project->workspace;

        // BR-05: mode read-only setelah grace period habis — analisa (panggilan LLM) diblok
        if ($workspace->subscription?->effectiveStatus() === 'readonly') {
            abort(403, 'Langganan berakhir — workspace read-only (BR-05).');
        }

        // BR-02: analisa perlu kredit tersedia, tapi TIDAK mengkonsumsi — hanya preview;
        // konsumsi baru terjadi saat regenerate() sukses.
        if ($workspace->creditBalance() < 1) {
            abort(402, 'Kredit blueprint habis. Upgrade paket atau top-up (BR-02).');
        }

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

        $workspace = $project->workspace;

        // BR-05: mode read-only setelah grace period habis — regenerasi diblok
        if ($workspace->subscription?->effectiveStatus() === 'readonly') {
            return back()->withErrors(['credits' => 'Langganan berakhir — workspace read-only (BR-05).']);
        }

        // BR-02: 1 kredit per regenerasi (dikonsumsi setelah sukses, lihat bawah)
        if ($workspace->creditBalance() < 1) {
            return back()->withErrors(['credits' => 'Kredit blueprint habis. Upgrade paket atau top-up (BR-02).']);
        }

        if ($project->generationRuns()->whereIn('status', ['queued', 'running'])->exists()) {
            return back()->withErrors(['credits' => 'Masih ada proses generate berjalan.']);
        }

        // Mulai pipeline regenerasi dengan subset doc + instruksi
        try {
            $pipeline->startRegeneration($project, $data['doc_keys'], $data['change_text']);
        } catch (\InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        // BR-02: konsumsi kredit HANYA setelah pipeline berhasil start — supaya 422 tidak membakar kredit
        CreditLedger::create([
            'workspace_id' => $workspace->id,
            'delta' => -1,
            'kind' => 'consume',
            'ref_type' => 'project',
            'ref_id' => $project->id,
            'idempotency_key' => 'consume-regen-'.$project->id.'-'.now()->timestamp,
        ]);

        return back();
    }

    /** FR-20 + FR-09: isi impact CR otomatis dari engine — tim tetap bisa koreksi via update() existing. */
    public function forChangeRequest(Request $request, Project $project, string $crId, SpecEngine $engine, ChangeRequestService $service)
    {
        ProjectController::authorizeProject($request, $project);
        $cr = $project->changeRequests()->where('status', 'proposed')->findOrFail($crId);

        $workspace = $project->workspace;

        // BR-05: mode read-only setelah grace period habis — analisa (panggilan LLM) diblok
        if ($workspace->subscription?->effectiveStatus() === 'readonly') {
            abort(403, 'Langganan berakhir — workspace read-only (BR-05).');
        }

        // BR-02: analisa perlu kredit tersedia, tapi TIDAK mengkonsumsi — hanya preview;
        // konsumsi baru terjadi saat regenerate() sukses.
        if ($workspace->creditBalance() < 1) {
            abort(402, 'Kredit blueprint habis. Upgrade paket atau top-up (BR-02).');
        }

        $impact = $engine->impact($project, trim($cr->title."\n".(string) $cr->description));
        $service->setImpact($cr, (float) $impact['delta_md'], array_column($impact['affected'], 'doc_key'));

        return back();
    }
}
