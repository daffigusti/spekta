<?php

namespace App\Http\Controllers;

use App\Models\Baseline;
use App\Models\ShareLink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;

/**
 * FR-17/18/19: portal klien — akses token + OTP email (BR-40), komentar thread,
 * approval per dokumen oleh approver utama (BR-27), baseline immutable (BR-24).
 */
class PortalController extends Controller
{
    private const SESSION_HOURS = 24; // BR-40

    public function show(Request $request, string $token)
    {
        $link = $this->resolve($token);
        $email = $this->verifiedEmail($request, $link);

        $project = $link->project;

        if (! $email) {
            return Inertia::render('portal', [
                'mode' => $request->session()->has("portal-pending:{$link->id}") ? 'otp' : 'email',
                'token' => $token,
                'workspace_name' => $project->workspace->name,
                'project_name' => $project->name,
            ]);
        }

        $order = array_flip(array_keys(config('spekta.doc_pipeline')));
        $documents = $project->documents()->with('currentVersion')
            ->whereIn('doc_key', $link->doc_keys)->get()
            ->sortBy(fn ($d) => $order[$d->doc_key] ?? 99)->values();
        $approvals = $link->approvals->keyBy('document_id');
        $comments = \App\Models\Comment::whereIn('document_id', $documents->pluck('id'))
            ->orderBy('created_at')->get();

        return Inertia::render('portal', [
            'mode' => 'portal',
            'token' => $token,
            'workspace_name' => $project->workspace->name,
            'project_name' => $project->name,
            'viewer_email' => $email,
            'is_approver' => strcasecmp($email, $link->approver_email) === 0,
            'approved_all' => $project->status === 'approved',
            'documents' => $documents->map(fn ($d) => [
                'id' => $d->id,
                'doc_key' => $d->doc_key,
                'title' => $d->title,
                'content_md' => $d->currentVersion?->content_md,
                'approved' => $approvals->has($d->id),
            ]),
            'change_requests' => $project->changeRequests()->orderByDesc('number')->get()->map(fn ($cr) => [
                'id' => $cr->id,
                'label' => $cr->label(),
                'title' => $cr->title,
                'status' => $cr->status,
                'delta_md' => $cr->delta_md,
                'delta_cost' => $cr->delta_cost,
                'impact_ready' => $cr->delta_md !== null,
            ]),
            'comments' => $comments->map(fn ($c) => [
                'id' => $c->id,
                'document_id' => $c->document_id,
                'parent_id' => $c->parent_id,
                'author_name' => $c->author_name,
                'author_type' => $c->author_type,
                'section_anchor' => $c->section_anchor,
                'body' => $c->body,
                'status' => $c->status,
                'created_at' => $c->created_at->diffForHumans(),
            ]),
        ]);
    }

    public function requestOtp(Request $request, string $token)
    {
        $link = $this->resolve($token);
        $data = $request->validate(['email' => 'required|email']);

        // BR-40: hanya approver + kontak terdaftar
        if (! $link->allowsEmail($data['email'])) {
            return back()->withErrors(['email' => 'Email tidak terdaftar di undangan proyek ini.']);
        }

        $code = (string) random_int(100000, 999999);
        Cache::put("portal-otp:{$link->id}:".strtolower($data['email']), $code, now()->addMinutes(10));
        $request->session()->put("portal-pending:{$link->id}", strtolower($data['email']));

        Mail::raw(
            "Kode akses portal {$link->project->name}: {$code}\nBerlaku 10 menit.",
            fn ($m) => $m->to($data['email'])->subject('Kode akses portal — '.$link->project->workspace->name)
        );

        return back();
    }

    public function verifyOtp(Request $request, string $token)
    {
        $link = $this->resolve($token);
        $data = $request->validate(['code' => 'required|digits:6']);

        $email = $request->session()->get("portal-pending:{$link->id}");
        $cached = $email ? Cache::get("portal-otp:{$link->id}:{$email}") : null;

        if (! $cached || ! hash_equals($cached, $data['code'])) {
            return back()->withErrors(['code' => 'Kode salah atau kedaluwarsa.']);
        }

        Cache::forget("portal-otp:{$link->id}:{$email}");
        $request->session()->forget("portal-pending:{$link->id}");
        $request->session()->put("portal-verified:{$link->id}", [
            'email' => $email,
            'until' => now()->addHours(self::SESSION_HOURS)->timestamp,
        ]);

        return back();
    }

    public function comment(Request $request, string $token)
    {
        $link = $this->resolve($token);
        $email = $this->verifiedEmail($request, $link) ?? abort(403);
        $data = $request->validate([
            'document_id' => 'required|uuid',
            'body' => 'required|string|max:5000',
            'parent_id' => 'nullable|uuid',
            'section_anchor' => 'nullable|string|max:255',
        ]);

        $document = $link->project->documents()->whereIn('doc_key', $link->doc_keys)->findOrFail($data['document_id']);

        $document->comments()->create([
            'project_id' => $link->project_id,
            'share_link_id' => $link->id,
            'parent_id' => $data['parent_id'] ?? null,
            'author_name' => strstr($email, '@', true) ?: $email,
            'author_email' => $email,
            'author_type' => 'client',
            'section_anchor' => $data['section_anchor'] ?? null,
            'body' => $data['body'],
        ]);

        return back();
    }

    /** FR-19: approve satu dokumen — hanya approver utama (BR-27). */
    public function approveDocument(Request $request, string $token)
    {
        $link = $this->resolve($token);
        $email = $this->verifiedEmail($request, $link) ?? abort(403);
        abort_unless(strcasecmp($email, $link->approver_email) === 0, 403, 'Hanya approver utama yang dapat menyetujui.');

        $data = $request->validate(['document_id' => 'required|uuid']);
        $link->project->documents()->whereIn('doc_key', $link->doc_keys)->findOrFail($data['document_id']);

        $link->approvals()->firstOrCreate(
            ['document_id' => $data['document_id']],
            ['approved_by' => $email, 'approved_at' => now()]
        );

        return back();
    }

    /** BR-24: approve semua → baseline immutable dengan hash. */
    public function approveAll(Request $request, string $token)
    {
        $link = $this->resolve($token);
        $email = $this->verifiedEmail($request, $link) ?? abort(403);
        abort_unless(strcasecmp($email, $link->approver_email) === 0, 403, 'Hanya approver utama yang dapat menyetujui.');

        $project = $link->project;
        $documents = $project->documents()->with('currentVersion')->whereIn('doc_key', $link->doc_keys)->get();

        foreach ($documents as $d) {
            $link->approvals()->firstOrCreate(
                ['document_id' => $d->id],
                ['approved_by' => $email, 'approved_at' => now()]
            );
        }

        $estimate = $project->estimates()->where('scope', $project->scope_mode ?: 'full')->first()
            ?? $project->estimates()->first();

        $snapshot = [
            'documents' => $documents->map(fn ($d) => [
                'doc_key' => $d->doc_key,
                'version_id' => $d->currentVersion?->id,
                'version_no' => $d->currentVersion?->version_no,
            ])->all(),
            'total_cost' => $estimate?->total_cost,
            'total_md' => $estimate?->total_md,
            'timeline' => $estimate?->timeline,
            'assumptions' => $project->assumptions(),
        ];

        Baseline::create([
            'project_id' => $project->id,
            'number' => ($project->baselines()->max('number') ?? 0) + 1,
            'snapshot' => $snapshot,
            'hash' => hash('sha256', json_encode($snapshot)),
            'approver_email' => $email,
            'approved_at' => now(),
        ]);

        $project->update(['status' => 'approved']);

        return back();
    }

    /** FR-20: klien mengajukan CR dari portal (BR-25 — perubahan pasca-approval lewat CR). */
    public function proposeChangeRequest(Request $request, string $token)
    {
        $link = $this->resolve($token);
        $email = $this->verifiedEmail($request, $link) ?? abort(403);
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
        ]);

        app(\App\Services\ChangeRequestService::class)->create($link->project, $data + [
            'source' => 'client',
            'requested_by' => $email,
        ]);

        return back();
    }

    /** BR-26: approver klien memutuskan CR — approve membentuk baseline baru. */
    public function decideChangeRequest(Request $request, string $token, string $crId)
    {
        $link = $this->resolve($token);
        $email = $this->verifiedEmail($request, $link) ?? abort(403);
        abort_unless(strcasecmp($email, $link->approver_email) === 0, 403, 'Hanya approver utama.');

        $data = $request->validate(['decision' => 'required|in:approved,rejected']);
        $cr = $link->project->changeRequests()->where('status', 'proposed')->findOrFail($crId);

        if ($data['decision'] === 'rejected') {
            $cr->update(['status' => 'rejected', 'decided_by' => $email, 'decided_at' => now()]);
        } else {
            abort_if($cr->delta_md === null, 422, 'Impact review belum diisi tim — belum bisa di-approve.');
            app(\App\Services\ChangeRequestService::class)->approve($cr, $email);
        }

        return back();
    }

    private function resolve(string $token): ShareLink
    {
        $link = ShareLink::where('token', $token)->firstOrFail();
        abort_unless($link->isActive(), 410, 'Link sudah dicabut atau kedaluwarsa.');

        return $link;
    }

    private function verifiedEmail(Request $request, ShareLink $link): ?string
    {
        $s = $request->session()->get("portal-verified:{$link->id}");
        if (! $s || $s['until'] < now()->timestamp) {
            return null;
        }

        return $s['email'];
    }
}
