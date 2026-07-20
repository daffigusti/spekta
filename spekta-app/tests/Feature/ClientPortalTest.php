<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Project;
use App\Models\ShareLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ClientPortalTest extends TestCase
{
    use RefreshDatabase;

    private function readyProject(): Project
    {
        config(['spekta.llm.driver' => 'stub']);
        $this->post('/register', [
            'name' => 'Muammar K', 'company' => 'AmanahCorp',
            'email' => 'owner@amanah.co.id',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ]);
        $this->actingAs(User::firstOrFail())->post('/projects');
        $project = Project::firstOrFail();

        $this->post("/projects/{$project->id}/wizard/input", [
            'name' => 'Kasir Pintar', 'client_name' => 'PT Maju Jaya', 'kind' => 'idea',
            'raw_text' => 'Aplikasi kasir multi-cabang dengan pembayaran QRIS. Laporan penjualan harian. Manajemen stok dengan notifikasi.',
        ]);
        $project->refresh();
        $this->post("/projects/{$project->id}/wizard/understanding", [
            'roles' => [], 'features' => $project->understanding->features, 'complexity' => 1, 'assumptions' => [],
        ]);
        $this->post("/projects/{$project->id}/wizard/interview/finish", ['skip_all' => true]);
        $this->post("/projects/{$project->id}/wizard/structure/confirm", ['scope_mode' => 'mvp']);
        $this->post("/projects/{$project->id}/wizard/stack/confirm");
        $this->post("/projects/{$project->id}/generate"); // sync queue di test

        return $project->refresh();
    }

    private function shareAndVerify(Project $project, string $email = 'budi@majujaya.co.id'): ShareLink
    {
        $this->post("/projects/{$project->id}/share", [
            'approver_email' => 'budi@majujaya.co.id',
            'contact_emails' => ['rina@majujaya.co.id'],
            'doc_keys' => $project->documents()->pluck('doc_key')->all(),
            'internal_review_done' => true,
        ])->assertSessionHasNoErrors();

        $link = ShareLink::firstOrFail();

        // OTP flow
        $this->post("/portal/{$link->token}/request-otp", ['email' => $email])->assertSessionHasNoErrors();
        $code = Cache::get("portal-otp:{$link->id}:".strtolower($email));
        $this->assertNotNull($code);
        $this->post("/portal/{$link->token}/verify", ['code' => $code])->assertSessionHasNoErrors();

        return $link;
    }

    public function test_share_otp_comment_approve_baseline_flow(): void
    {
        $project = $this->readyProject();
        $link = $this->shareAndVerify($project);

        $this->assertSame('shared', $project->fresh()->status); // FR-19 status flow

        // Portal tampil dengan dokumen ter-share
        $res = $this->get("/portal/{$link->token}");
        $res->assertOk();

        // Email tak terdaftar ditolak (BR-40)
        $this->post("/portal/{$link->token}/request-otp", ['email' => 'hacker@evil.com'])
            ->assertSessionHasErrors('email');

        // FR-18: komentar + balasan thread
        $doc = $project->documents()->first();
        $this->post("/portal/{$link->token}/comments", ['document_id' => $doc->id, 'body' => 'Tolong tambah dukungan OVO.'])
            ->assertSessionHasNoErrors();
        $root = Comment::firstOrFail();
        $this->post("/portal/{$link->token}/comments", ['document_id' => $doc->id, 'body' => 'Setuju.', 'parent_id' => $root->id]);
        $this->assertSame(1, $root->replies()->count());

        // FR-19: approve satu dokumen
        $this->post("/portal/{$link->token}/approve", ['document_id' => $doc->id])->assertSessionHasNoErrors();
        $this->assertSame(1, $link->approvals()->count());

        // BR-24: approve semua → baseline immutable + hash + status approved
        $this->post("/portal/{$link->token}/approve-all")->assertSessionHasNoErrors();
        $project->refresh();
        $this->assertSame('approved', $project->status);
        $baseline = $project->baselines()->firstOrFail();
        $this->assertSame(1, $baseline->number);
        $this->assertSame(64, strlen($baseline->hash));
        $this->assertNotEmpty($baseline->snapshot['documents']);
        $this->assertSame(hash('sha256', json_encode($baseline->snapshot)), $baseline->hash);

        // BR-29: proyek approved tidak bisa dihapus
        $this->delete("/projects/{$project->id}")->assertForbidden();
    }

    public function test_member_role_cannot_share(): void
    {
        $project = $this->readyProject();

        // BR-30: member biasa (bukan Owner/Admin) tidak boleh share ke klien
        $member = User::factory()->create(['current_workspace_id' => $project->workspace_id]);
        $project->workspace->members()->create(['user_id' => $member->id, 'role' => 'member']);

        $this->actingAs($member)->post("/projects/{$project->id}/share", [
            'approver_email' => 'budi@majujaya.co.id',
            'doc_keys' => $project->documents()->pluck('doc_key')->all(),
            'internal_review_done' => true,
        ])->assertForbidden();
        $this->assertSame(0, ShareLink::count());
    }

    public function test_non_approver_contact_cannot_approve_but_can_comment(): void
    {
        $project = $this->readyProject();
        $link = $this->shareAndVerify($project, 'rina@majujaya.co.id'); // kontak, bukan approver

        $doc = $project->documents()->first();
        $this->post("/portal/{$link->token}/comments", ['document_id' => $doc->id, 'body' => 'Pertanyaan soal timeline.'])
            ->assertSessionHasNoErrors(); // BR-27: komentar boleh

        $this->post("/portal/{$link->token}/approve", ['document_id' => $doc->id])->assertForbidden(); // approve tidak
        $this->post("/portal/{$link->token}/approve-all")->assertForbidden();
    }

    public function test_revoked_link_blocked_but_comments_retained(): void
    {
        $project = $this->readyProject();
        $link = $this->shareAndVerify($project);

        $doc = $project->documents()->first();
        $this->post("/portal/{$link->token}/comments", ['document_id' => $doc->id, 'body' => 'Komentar sebelum revoke.']);

        $this->delete("/projects/{$project->id}/share/{$link->id}"); // revoke

        $this->get("/portal/{$link->token}")->assertStatus(410); // akses mati
        $this->assertSame(1, Comment::count()); // BR-28: jejak tetap

        // OTP salah ditolak
        $link2 = ShareLink::create([
            'project_id' => $project->id, 'token' => str_repeat('a', 48),
            'approver_email' => 'budi@majujaya.co.id', 'doc_keys' => ['PRD'],
            'expires_at' => now()->addDays(30), 'created_by' => User::first()->id,
        ]);
        $this->post("/portal/{$link2->token}/request-otp", ['email' => 'budi@majujaya.co.id']);
        $this->post("/portal/{$link2->token}/verify", ['code' => '000000'])->assertSessionHasErrors('code');
    }
}
