<?php

namespace App\Http\Controllers;

use App\Models\Document;
use Illuminate\Http\Request;

class DocumentController extends Controller
{
    // Baca dokumen selesai saat run masih jalan (wizard step generate)
    public function showByKey(Request $request, \App\Models\Project $project, string $docKey)
    {
        ProjectController::authorizeProject($request, $project);
        $document = $project->documents()->where('doc_key', $docKey)->firstOrFail();

        return response()->json([
            'doc_key' => $docKey,
            'content_md' => $document->currentVersion?->content_md ?? '',
        ]);
    }

    // FR-08: edit manual → versi baru dengan atribusi
    public function storeVersion(Request $request, Document $document)
    {
        ProjectController::authorizeProject($request, $document->project);
        $data = $request->validate(['content_md' => 'required|string']);

        // BR-25: dokumen ter-baseline hanya boleh diubah lewat CR yang mencakupnya
        abort_unless(
            app(\App\Services\ChangeRequestService::class)->editAllowed($document->project, $document->doc_key),
            403,
            'Proyek sudah di-approve — perubahan wajib lewat Change Request yang mencakup dokumen ini (BR-25).'
        );

        $version = $document->versions()->create([
            'version_no' => ($document->versions()->max('version_no') ?? 0) + 1,
            'content_md' => $data['content_md'],
            'source' => 'user', // BR-53: dibedakan di version history
            'created_by' => $request->user()->id,
        ]);
        $document->update(['current_version_id' => $version->id]);

        app(\App\Services\SpecHealthValidator::class)->run($document->project); // BR-15

        return back();
    }

    /** Pulihkan versi lama — non-destruktif: isi lama disalin jadi versi baru. */
    public function restoreVersion(Request $request, Document $document, int $versionNo)
    {
        ProjectController::authorizeProject($request, $document->project);
        abort_unless(
            app(\App\Services\ChangeRequestService::class)->editAllowed($document->project, $document->doc_key),
            403,
            'Proyek sudah di-approve — perubahan wajib lewat Change Request yang mencakup dokumen ini (BR-25).'
        );

        $old = $document->versions()->where('version_no', $versionNo)->firstOrFail();
        $version = $document->versions()->create([
            'version_no' => ($document->versions()->max('version_no') ?? 0) + 1,
            'content_md' => $old->content_md,
            'source' => 'user',
            'created_by' => $request->user()->id,
        ]);
        $document->update(['current_version_id' => $version->id]);

        app(\App\Services\SpecHealthValidator::class)->run($document->project); // BR-15

        return back();
    }

    public function showVersion(Request $request, Document $document, int $versionNo)
    {
        ProjectController::authorizeProject($request, $document->project);
        $version = $document->versions()->where('version_no', $versionNo)->firstOrFail();

        return response()->json($version->only(['version_no', 'content_md', 'source', 'created_at']));
    }
}
