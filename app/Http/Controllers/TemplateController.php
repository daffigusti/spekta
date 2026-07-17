<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\DocTemplate;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * FR-16 / FR-24: template perusahaan sebagai preset bernama — set dokumen, bahasa,
 * tone, & opsi proposal. Multi per workspace, tepat satu default; blueprint baru
 * mengikuti template default.
 */
class TemplateController extends Controller
{
    private function memberFor(Request $request, Workspace $workspace): ?WorkspaceMember
    {
        return $workspace->members()->where('user_id', $request->user()->id)->first();
    }

    /** Doc key valid = kunci graph pipeline (satu sumber kebenaran, FR-07). */
    private function docKindOptions(): array
    {
        return array_keys(config('spekta.doc_pipeline'));
    }

    public function index(Request $request)
    {
        $workspace = $request->user()->currentWorkspace();
        $workspace->defaultDocTemplate(); // pastikan default ada (onboarding)

        $templates = $workspace->docTemplates()
            ->withCount('projects')
            ->orderByDesc('is_default')
            ->orderBy('created_at')
            ->get()
            ->map(fn (DocTemplate $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'is_default' => $t->is_default,
                'doc_kinds' => $t->doc_kinds ?? [],
                'language' => $t->language,
                'tone' => $t->tone,
                'config' => $t->config ?? [],
                'projects_count' => $t->projects_count,
                'updated_at' => $t->updated_at?->toIso8601String(),
            ]);

        $member = $this->memberFor($request, $workspace);

        return Inertia::render('templates', [
            'templates' => $templates,
            'docKindOptions' => $this->docKindOptions(),
            'logoUrl' => $workspace->logo_url,
            'canManage' => in_array($member?->role, ['owner', 'admin']),
        ]);
    }

    public function store(Request $request)
    {
        $workspace = $request->user()->currentWorkspace();
        $member = $this->memberFor($request, $workspace);
        abort_unless(in_array($member?->role, ['owner', 'admin']), 403);

        $data = $this->validated($request);

        $template = $workspace->docTemplates()->create([
            'name' => $data['name'],
            'is_default' => false,
            'doc_kinds' => $data['doc_kinds'],
            'language' => $data['language'] ?? 'id',
            'tone' => $data['tone'] ?? 'formal',
            'config' => ['white_label' => $data['config']['white_label'] ?? false],
        ]);

        $this->maybeUploadLogo($request, $workspace);

        AuditLog::create([
            'workspace_id' => $workspace->id,
            'actor_id' => $request->user()->id,
            'action' => 'template.created',
            'entity_type' => 'doc_template',
            'entity_id' => $template->id,
        ]);

        return back();
    }

    public function update(Request $request, string $templateId)
    {
        $workspace = $request->user()->currentWorkspace();
        $member = $this->memberFor($request, $workspace);
        abort_unless(in_array($member?->role, ['owner', 'admin']), 403);

        $template = $workspace->docTemplates()->findOrFail($templateId);
        $data = $this->validated($request, partial: true);

        if (array_key_exists('name', $data)) {
            $template->name = $data['name'];
        }
        if (array_key_exists('doc_kinds', $data)) {
            $template->doc_kinds = $data['doc_kinds'];
        }
        if (array_key_exists('language', $data)) {
            $template->language = $data['language'];
        }
        if (array_key_exists('tone', $data)) {
            $template->tone = $data['tone'];
        }
        if (array_key_exists('config', $data) && array_key_exists('white_label', $data['config'])) {
            // multipart mengirim boolean sebagai '1'/'0' — normalisasi supaya tersimpan sebagai bool
            $template->config = array_merge($template->config ?? [], [
                'white_label' => filter_var($data['config']['white_label'], FILTER_VALIDATE_BOOLEAN),
            ]);
        }
        $template->save();

        $this->maybeUploadLogo($request, $workspace);

        AuditLog::create([
            'workspace_id' => $workspace->id,
            'actor_id' => $request->user()->id,
            'action' => 'template.updated',
            'entity_type' => 'doc_template',
            'entity_id' => $template->id,
        ]);

        return back();
    }

    public function setDefault(Request $request, string $templateId)
    {
        $workspace = $request->user()->currentWorkspace();
        $member = $this->memberFor($request, $workspace);
        abort_unless(in_array($member?->role, ['owner', 'admin']), 403);

        $template = $workspace->docTemplates()->findOrFail($templateId);

        DB::transaction(function () use ($workspace, $template) {
            $workspace->docTemplates()->where('is_default', true)->update(['is_default' => false]);
            $template->update(['is_default' => true]);
        });

        AuditLog::create([
            'workspace_id' => $workspace->id,
            'actor_id' => $request->user()->id,
            'action' => 'template.default_changed',
            'entity_type' => 'doc_template',
            'entity_id' => $template->id,
        ]);

        return back();
    }

    public function destroy(Request $request, string $templateId)
    {
        $workspace = $request->user()->currentWorkspace();
        $member = $this->memberFor($request, $workspace);
        abort_unless(in_array($member?->role, ['owner', 'admin']), 403);

        $template = $workspace->docTemplates()->findOrFail($templateId);
        if ($template->is_default) {
            return back()->withErrors([
                'template' => 'Template default tidak bisa dihapus — jadikan template lain default dulu.',
            ]);
        }

        // Proyek yang memakai template ini otomatis doc_template_id-nya di-null-kan oleh FK
        $template->delete();

        AuditLog::create([
            'workspace_id' => $workspace->id,
            'actor_id' => $request->user()->id,
            'action' => 'template.deleted',
            'entity_type' => 'doc_template',
            'entity_id' => $templateId,
        ]);

        return back();
    }

    /** Validasi field template — semua sometimes saat partial (update). */
    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$req, 'string', 'max:100'],
            'doc_kinds' => [$req, 'array', 'min:1'],
            'doc_kinds.*' => ['string', Rule::in($this->docKindOptions())],
            'language' => ['sometimes', 'in:id,en'],
            'tone' => ['sometimes', 'in:formal,formal_rfc,casual'],
            'config' => ['sometimes', 'array'],
            'config.white_label' => ['sometimes'],
            // SVG ditolak: bisa memuat <script> — stored XSS bila diserve dari origin app
            'logo' => ['sometimes', 'nullable', 'image', 'max:2048', 'mimes:jpg,jpeg,png,webp'],
        ]);
    }

    /** Logo workspace (dipakai proposal & portal per FR-16). */
    private function maybeUploadLogo(Request $request, Workspace $workspace): void
    {
        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->store('logos', 'public');
            $workspace->update(['logo_url' => Storage::url($path)]);
        }
    }
}
