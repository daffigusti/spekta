<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

/** FR-16 / FR-24: template proposal, dokumen & portal per workspace (logo, warna, format). */
class TemplateController extends Controller
{
    /** ponytail: config default per kind — dipakai saat firstOrCreate index & update. */
    private function defaults(string $kind): array
    {
        return match ($kind) {
            'proposal' => ['primary_color' => '#0D9488', 'accent_color' => '#F59E0B', 'page_format' => 'A4', 'footer_text' => null, 'show_cover' => true],
            'document' => ['heading_numbering' => true, 'include_toc' => true, 'language' => 'id'],
            'portal' => ['theme_color' => '#0D9488', 'welcome_text' => null],
            default => [],
        };
    }

    private function memberFor(Request $request, Workspace $workspace): ?WorkspaceMember
    {
        return $workspace->members()->where('user_id', $request->user()->id)->first();
    }

    public function index(Request $request)
    {
        $workspace = $request->user()->currentWorkspace();

        $templates = collect(['proposal', 'document', 'portal'])->map(function ($kind) use ($workspace) {
            $tpl = $workspace->docTemplates()->firstOrCreate(
                ['kind' => $kind],
                ['config' => $this->defaults($kind)],
            );

            return [
                'id' => $tpl->id,
                'kind' => $tpl->kind,
                'config' => $tpl->config ?? [],
                'file_url' => $tpl->file_url,
            ];
        })->values();

        $member = $this->memberFor($request, $workspace);

        return Inertia::render('templates', [
            'templates' => $templates,
            'branding' => ['logo_url' => $workspace->logo_url, 'name' => $workspace->name],
            'canManage' => in_array($member?->role, ['owner', 'admin']),
        ]);
    }

    public function update(Request $request, string $kind)
    {
        abort_unless(in_array($kind, ['proposal', 'document', 'portal']), 404);

        $workspace = $request->user()->currentWorkspace();
        $member = $this->memberFor($request, $workspace);
        abort_unless(in_array($member?->role, ['owner', 'admin']), 403);

        $data = $request->validate([
            'config' => 'sometimes|array',
            'config.primary_color' => ['sometimes', 'nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'config.accent_color' => ['sometimes', 'nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'config.theme_color' => ['sometimes', 'nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'config.page_format' => ['sometimes', 'in:A4,Letter'],
            'config.language' => ['sometimes', 'in:id,en'],
            'config.footer_text' => ['sometimes', 'nullable', 'string', 'max:500'],
            'config.welcome_text' => ['sometimes', 'nullable', 'string', 'max:500'],
            'config.show_cover' => ['sometimes', 'boolean'],
            'config.heading_numbering' => ['sometimes', 'boolean'],
            'config.include_toc' => ['sometimes', 'boolean'],
            // SVG ditolak: bisa memuat <script> — stored XSS bila diserve dari origin app
            'logo' => ['sometimes', 'nullable', 'image', 'max:2048', 'mimes:jpg,jpeg,png,webp'],
        ]);

        $tpl = $workspace->docTemplates()->firstOrCreate(
            ['kind' => $kind],
            ['config' => $this->defaults($kind)],
        );

        if (array_key_exists('config', $data)) {
            $incoming = $data['config'];
            // multipart mengirim boolean sebagai '1'/'0' — normalisasi supaya tersimpan sebagai bool
            foreach (['show_cover', 'heading_numbering', 'include_toc'] as $boolKey) {
                if (array_key_exists($boolKey, $incoming)) {
                    $incoming[$boolKey] = filter_var($incoming[$boolKey], FILTER_VALIDATE_BOOLEAN);
                }
            }
            $tpl->config = array_merge($tpl->config ?? [], $incoming);
            $tpl->save();
        }

        // Logo workspace (dipakai proposal & portal per FR-16)
        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->store('logos', 'public');
            $workspace->update(['logo_url' => Storage::url($path)]);
        }

        AuditLog::create([
            'workspace_id' => $workspace->id,
            'actor_id' => $request->user()->id,
            'action' => 'template.updated',
            'entity_type' => 'doc_template',
            'entity_id' => $tpl->id,
        ]);

        return back();
    }
}
