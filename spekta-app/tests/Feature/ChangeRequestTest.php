<?php

namespace Tests\Feature;

use App\Models\ChangeRequest;
use App\Models\Project;
use App\Models\ShareLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ChangeRequestTest extends TestCase
{
    use RefreshDatabase;

    private ShareLink $link;

    /** Proyek approved + baseline v1 + sesi portal approver aktif. */
    private function approvedProject(): Project
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
            'name' => 'Kasir Pintar', 'kind' => 'idea',
            'raw_text' => 'Aplikasi kasir multi-cabang dengan pembayaran QRIS. Laporan penjualan harian. Manajemen stok dengan notifikasi.',
        ]);
        $project->refresh();
        $this->post("/projects/{$project->id}/wizard/understanding", [
            'roles' => [], 'features' => $project->understanding->features, 'complexity' => 1, 'assumptions' => [],
        ]);
        $this->post("/projects/{$project->id}/wizard/interview/finish", ['skip_all' => true]);
        $this->post("/projects/{$project->id}/wizard/structure/confirm", ['scope_mode' => 'mvp']);
        $this->post("/projects/{$project->id}/wizard/stack/confirm");
        $this->post("/projects/{$project->id}/generate");
        $this->get("/projects/{$project->id}/estimate"); // hitung estimasi + timeline

        $this->post("/projects/{$project->id}/share", [
            'approver_email' => 'budi@majujaya.co.id',
            'doc_keys' => $project->documents()->pluck('doc_key')->all(),
            'internal_review_done' => true,
        ]);
        $this->link = ShareLink::firstOrFail();
        $this->post("/portal/{$this->link->token}/request-otp", ['email' => 'budi@majujaya.co.id']);
        $code = Cache::get("portal-otp:{$this->link->id}:budi@majujaya.co.id");
        $this->post("/portal/{$this->link->token}/verify", ['code' => $code]);
        $this->post("/portal/{$this->link->token}/approve-all");

        return $project->refresh();
    }

    public function test_client_cr_flow_creates_new_baseline(): void
    {
        $project = $this->approvedProject();
        $this->assertSame(1, $project->baselines()->count());

        // BR-25: edit dokumen ter-baseline diblok tanpa CR
        $prd = $project->documents()->where('doc_key', 'PRD')->first();
        $this->post("/documents/{$prd->id}/versions", ['content_md' => 'Edit liar tanpa CR'])
            ->assertForbidden();

        // Klien mengajukan CR dari portal
        $this->post("/portal/{$this->link->token}/change-requests", [
            'title' => 'Tambah dukungan e-wallet OVO & Dana',
        ])->assertSessionHasNoErrors();
        $cr = ChangeRequest::firstOrFail();
        $this->assertSame(1, $cr->number);
        $this->assertSame('CR-001', $cr->label());
        $this->assertSame('client', $cr->source);

        // Approve sebelum impact review → 422 (BR-26 butuh delta)
        $this->post("/portal/{$this->link->token}/change-requests/{$cr->id}/decide", ['decision' => 'approved'])
            ->assertStatus(422);

        // Tim isi impact: delta 8 MD, dokumen terdampak PRD + REQUIREMENTS
        $this->patch("/projects/{$project->id}/change-requests/{$cr->id}", [
            'delta_md' => 8, 'affected_doc_keys' => ['PRD', 'REQUIREMENTS'],
        ])->assertSessionHasNoErrors();
        $cr->refresh();
        $this->assertGreaterThan(0, $cr->delta_cost); // dari blended rate + margin

        // BR-25: dokumen tercakup CR proposed kini boleh diedit
        $this->post("/documents/{$prd->id}/versions", ['content_md' => $prd->currentVersion->content_md."\n\nRevisi per CR-001."])
            ->assertSessionHasNoErrors();

        // Approver klien setujui → baseline v2, baseline lama tetap (BR-26)
        $this->post("/portal/{$this->link->token}/change-requests/{$cr->id}/decide", ['decision' => 'approved'])
            ->assertSessionHasNoErrors();
        $cr->refresh();
        $this->assertSame('approved', $cr->status);
        $this->assertNotNull($cr->baseline_id);

        $this->assertSame(2, $project->baselines()->count());
        $b1 = $project->baselines()->where('number', 1)->first();
        $b2 = $project->baselines()->where('number', 2)->first();
        $this->assertSame('CR-001', $b2->snapshot['change_request']);
        // Selisih RAB antar baseline = delta CR (dasar penagihan, BR-26)
        $this->assertEqualsWithDelta($cr->delta_cost, $b2->snapshot['total_cost'] - $b1->snapshot['total_cost'], 1.0);
    }

    public function test_reject_paths_and_team_cr(): void
    {
        $project = $this->approvedProject();

        // Tim buat CR internal dengan delta langsung
        $this->post("/projects/{$project->id}/change-requests", [
            'title' => 'Refactor modul laporan', 'delta_md' => 4,
        ])->assertSessionHasNoErrors();
        $cr = ChangeRequest::firstOrFail();
        $this->assertSame('team', $cr->source);
        $this->assertNotNull($cr->delta_cost);

        // Approver klien menolak → tidak ada baseline baru
        $this->post("/portal/{$this->link->token}/change-requests/{$cr->id}/decide", ['decision' => 'rejected']);
        $this->assertSame('rejected', $cr->fresh()->status);
        $this->assertSame(1, $project->baselines()->count());

        // CR kedua nomor lanjut
        $this->post("/projects/{$project->id}/change-requests", ['title' => 'CR kedua']);
        $this->assertSame('CR-002', ChangeRequest::where('number', 2)->first()->label());

        // Kontak non-approver tidak boleh memutuskan
        $this->post("/portal/{$this->link->token}/request-otp", ['email' => 'budi@majujaya.co.id']); // sesi tetap approver — buat sesi kontak baru butuh link berbeda; cukup uji tanpa sesi
        $this->flushSession();
        $this->post("/portal/{$this->link->token}/change-requests/{$cr->id}/decide", ['decision' => 'approved'])
            ->assertForbidden();
    }
}
