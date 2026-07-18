<?php

namespace App\Services;

use App\Models\CreditLedger;
use App\Models\Payment;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * FR-23 + BR-01..BR-05: checkout Midtrans Snap, webhook notifikasi, aktivasi paket & kredit.
 * ponytail: HTTP langsung ke Snap API (2 endpoint) — tanpa SDK. Stripe/USD = Fase 5.
 */
class MidtransBilling
{
    /** Buat transaksi Snap → Payment berisi redirect_url. */
    public function checkout(Workspace $workspace, string $email, array $attrs): Payment
    {
        if (config('spekta.midtrans.server_key') === '') {
            abort(503, 'Midtrans belum dikonfigurasi — isi MIDTRANS_SERVER_KEY & MIDTRANS_CLIENT_KEY di .env (lihat env.spekta.example).');
        }

        $orderId = 'SPK-'.strtoupper(Str::random(12));

        $payment = Payment::create($attrs + [
            'workspace_id' => $workspace->id,
            'order_id' => $orderId,
        ]);

        $res = Http::withBasicAuth(config('spekta.midtrans.server_key'), '')
            ->acceptJson()
            ->post(config('spekta.midtrans.base_url').'/snap/v1/transactions', [
                'transaction_details' => ['order_id' => $orderId, 'gross_amount' => (int) $payment->amount],
                'customer_details' => ['email' => $email],
                'item_details' => [[
                    'id' => $payment->kind === 'topup' ? 'topup-'.$payment->credits : 'plan-'.$payment->plan,
                    'price' => (int) $payment->amount,
                    'quantity' => 1,
                    'name' => $payment->kind === 'topup'
                        ? "Top-up {$payment->credits} kredit blueprint"
                        : 'Paket '.ucfirst($payment->plan).($payment->kind === 'subscription' && $payment->plan === 'team' ? " × {$payment->seats} seat" : '').' — 1 bulan',
                ]],
            ])->throw()->json();

        $payment->update(['snap_token' => $res['token'] ?? null, 'redirect_url' => $res['redirect_url'] ?? null]);

        return $payment;
    }

    /** Webhook Midtrans: verifikasi signature sha512, idempoten, aktivasi saat settlement/capture. */
    public function handleNotification(array $payload): void
    {
        $orderId = $payload['order_id'] ?? '';
        $statusCode = $payload['status_code'] ?? '';
        $gross = $payload['gross_amount'] ?? '';
        $expected = hash('sha512', $orderId.$statusCode.$gross.config('spekta.midtrans.server_key'));

        if (! hash_equals($expected, $payload['signature_key'] ?? '')) {
            abort(403, 'Signature tidak valid.');
        }

        $payment = Payment::where('order_id', $orderId)->firstOrFail();
        $payment->update(['raw_notification' => $payload]);

        $status = $payload['transaction_status'] ?? '';
        $fraud = $payload['fraud_status'] ?? 'accept';

        if (in_array($status, ['settlement', 'capture']) && $fraud === 'accept') {
            $this->activate($payment);
        } elseif (in_array($status, ['deny', 'cancel', 'failure'])) {
            $payment->update(['status' => 'failed']); // BR-05: data tidak dihapus
        } elseif ($status === 'expire') {
            $payment->update(['status' => 'expired']);
        }
    }

    private function activate(Payment $payment): void
    {
        if ($payment->status === 'paid') {
            return; // idempoten — Midtrans bisa kirim notifikasi berulang
        }
        $payment->update(['status' => 'paid', 'paid_at' => now()]);
        $workspace = $payment->workspace;

        if ($payment->kind === 'topup') {
            // BR-03: kredit top-up berlaku 12 bulan
            CreditLedger::create([
                'workspace_id' => $workspace->id,
                'delta' => $payment->credits,
                'kind' => 'topup',
                'ref_type' => 'payment',
                'ref_id' => $payment->id,
                'expires_at' => now()->addMonths(12),
                'idempotency_key' => 'topup-'.$payment->order_id,
            ]);

            return;
        }

        // BR-04: upgrade berlaku seketika. ponytail: prorata & auto-renew scheduler belum — bayar manual per bulan.
        $workspace->subscription->update([
            'plan' => $payment->plan,
            'seats' => $payment->seats,
            'period_start' => now()->toDateString(),
            'period_end' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);

        $quota = config("spekta.plans.{$payment->plan}.blueprints_per_month");
        if ($quota !== null) {
            // BR-03: kredit paket berlaku 1 bulan, tidak rollover
            CreditLedger::create([
                'workspace_id' => $workspace->id,
                'delta' => $quota,
                'kind' => 'plan_grant',
                'ref_type' => 'payment',
                'ref_id' => $payment->id,
                'expires_at' => now()->addMonth(),
                'idempotency_key' => 'plan-grant-'.$payment->order_id,
            ]);
        }
    }
}
