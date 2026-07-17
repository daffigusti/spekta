<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['spekta.midtrans.server_key' => 'SB-test-server-key']);
        Http::fake([
            'app.sandbox.midtrans.com/*' => Http::response([
                'token' => 'snap-token-123',
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/snap-token-123',
            ], 201),
        ]);
    }

    private function user(): User
    {
        $this->post('/register', [
            'name' => 'Muammar K', 'company' => 'AmanahCorp',
            'email' => 'owner@amanah.co.id',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ]);

        return User::firstOrFail();
    }

    private function notify(Payment $payment, string $status = 'settlement'): \Illuminate\Testing\TestResponse
    {
        $gross = number_format($payment->amount, 2, '.', '');
        $sig = hash('sha512', $payment->order_id.'200'.$gross.'SB-test-server-key');

        return $this->postJson('/midtrans/notify', [
            'order_id' => $payment->order_id,
            'status_code' => '200',
            'gross_amount' => $gross,
            'signature_key' => $sig,
            'transaction_status' => $status,
            'fraud_status' => 'accept',
        ]);
    }

    public function test_subscription_checkout_and_settlement_activates_plan(): void
    {
        $user = $this->user();
        $workspace = $user->currentWorkspace();
        $this->assertSame(2.0, $workspace->creditBalance()); // free grant awal

        // Checkout Pro → Snap dipanggil, redirect ke halaman bayar
        $this->actingAs($user)->post('/billing/checkout', ['kind' => 'subscription', 'plan' => 'pro'])
            ->assertRedirect('https://app.sandbox.midtrans.com/snap/v4/redirection/snap-token-123'); // Inertia::location
        $payment = Payment::firstOrFail();
        $this->assertSame('pending', $payment->status);
        $this->assertSame(399000.0, $payment->amount); // BR-01
        Http::assertSent(fn ($req) => str_contains($req->url(), '/snap/v1/transactions'));

        // Webhook settlement → plan aktif + kredit 25 (BR-01/BR-04)
        $this->notify($payment)->assertOk();
        $payment->refresh();
        $this->assertSame('paid', $payment->status);
        $sub = $workspace->subscription->fresh();
        $this->assertSame('pro', $sub->plan);
        $this->assertSame('active', $sub->effectiveStatus());
        $this->assertSame(2.0 + 25.0, $workspace->creditBalance());

        // Notifikasi ganda → idempoten, kredit tidak dobel
        $this->notify($payment)->assertOk();
        $this->assertSame(27.0, $workspace->creditBalance());
    }

    public function test_topup_team_seats_signature_and_failure(): void
    {
        $user = $this->user();
        $workspace = $user->currentWorkspace();

        // Top-up 5 kredit → 5 × 75rb (BR-03: 12 bulan)
        $this->actingAs($user)->post('/billing/checkout', ['kind' => 'topup', 'credits' => 5]);
        $topup = Payment::where('kind', 'topup')->firstOrFail();
        $this->assertSame(375000.0, $topup->amount);
        $this->notify($topup);
        $this->assertSame(7.0, $workspace->creditBalance());
        $ledger = $workspace->creditLedger()->where('kind', 'topup')->first();
        $this->assertTrue($ledger->expires_at->greaterThan(now()->addMonths(11)));

        // Team dipaksa min 3 seat (BR-01)
        $this->post('/billing/checkout', ['kind' => 'subscription', 'plan' => 'team', 'seats' => 1]);
        $team = Payment::where('plan', 'team')->firstOrFail();
        $this->assertSame(3, $team->seats);
        $this->assertSame(3 * 249000.0, $team->amount);

        // Signature salah ditolak
        $this->postJson('/midtrans/notify', [
            'order_id' => $team->order_id, 'status_code' => '200',
            'gross_amount' => '747000.00', 'signature_key' => 'palsu',
            'transaction_status' => 'settlement',
        ])->assertForbidden();

        // Gagal bayar → status failed, paket & data tidak berubah (BR-05)
        $gross = number_format($team->amount, 2, '.', '');
        $this->postJson('/midtrans/notify', [
            'order_id' => $team->order_id, 'status_code' => '200', 'gross_amount' => $gross,
            'signature_key' => hash('sha512', $team->order_id.'200'.$gross.'SB-test-server-key'),
            'transaction_status' => 'deny',
        ])->assertOk();
        $this->assertSame('failed', $team->fresh()->status);
        $this->assertSame('free', $workspace->subscription->fresh()->plan);
    }

    public function test_readonly_after_grace_blocks_generate(): void
    {
        config(['spekta.llm.driver' => 'stub']);
        $user = $this->user();
        $workspace = $user->currentWorkspace();

        // Langganan starter kedaluwarsa 10 hari lalu → readonly (BR-05: grace 7 hari)
        $workspace->subscription->update([
            'plan' => 'starter',
            'period_end' => now()->subDays(10)->toDateString(),
        ]);
        $this->assertSame('readonly', $workspace->subscription->fresh()->effectiveStatus());

        $this->actingAs($user)->post('/projects');
        $project = \App\Models\Project::firstOrFail();
        $this->post("/projects/{$project->id}/wizard/input", [
            'name' => 'P', 'kind' => 'idea',
            'raw_text' => str_repeat('Aplikasi manajemen gudang dengan barcode dan laporan. ', 3),
        ]);
        $project->refresh();
        $this->post("/projects/{$project->id}/wizard/understanding", [
            'roles' => [], 'features' => $project->understanding->features, 'complexity' => 1, 'assumptions' => [],
        ]);
        $this->post("/projects/{$project->id}/wizard/interview/finish", ['skip_all' => true]);
        $this->post("/projects/{$project->id}/wizard/structure/confirm", ['scope_mode' => 'mvp']);
        $this->post("/projects/{$project->id}/wizard/stack/confirm");

        $this->post("/projects/{$project->id}/generate")->assertSessionHasErrors('credits');

        // Grace (3 hari lewat) → masih boleh
        $workspace->subscription->update(['period_end' => now()->subDays(3)->toDateString()]);
        $this->assertSame('grace', $workspace->subscription->fresh()->effectiveStatus());
        $this->post("/projects/{$project->id}/generate")->assertSessionHasNoErrors();
    }
}
