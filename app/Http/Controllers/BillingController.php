<?php

namespace App\Http\Controllers;

use App\Services\MidtransBilling;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** FR-23: halaman billing + checkout Snap + webhook notifikasi. */
class BillingController extends Controller
{
    public function index(Request $request)
    {
        $workspace = $request->user()->currentWorkspace();
        $sub = $workspace->subscription;

        return Inertia::render('billing', [
            'plan' => $sub->plan,
            'plan_status' => $sub->effectiveStatus(),
            'period_end' => $sub->period_end?->format('d M Y'),
            'seats' => $sub->seats,
            'credits' => $workspace->creditBalance(),
            'plans' => collect(config('spekta.plans'))->map(fn ($p, $key) => [
                'key' => $key,
                'label' => $p['label'],
                'price_idr' => $p['price_idr'] ?? $p['price_idr_per_seat'],
                'per_seat' => isset($p['price_idr_per_seat']),
                'min_seats' => $p['min_seats'] ?? 1,
                'blueprints' => $p['blueprints_per_month'],
                'chats' => $p['ai_chats_per_month'],
                'members' => $p['members'] ?? null,
                'client_portal' => $p['client_portal'] ?? true,
            ])->values(),
            'topup' => config('spekta.topup'),
            'payments' => $workspace->payments()->latest()->limit(20)->get()
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'order_id' => $p->order_id,
                    'kind' => $p->kind,
                    'plan' => $p->plan,
                    'credits' => $p->credits,
                    'amount' => $p->amount,
                    'status' => $p->status,
                    'redirect_url' => $p->status === 'pending' ? $p->redirect_url : null,
                    'created_at' => $p->created_at->format('d M Y H:i'),
                ]),
        ]);
    }

    public function checkout(Request $request, MidtransBilling $billing)
    {
        $workspace = $request->user()->currentWorkspace();
        $data = $request->validate([
            'kind' => 'required|in:subscription,topup',
            'plan' => 'required_if:kind,subscription|nullable|in:starter,pro,team',
            'seats' => 'nullable|integer|min:1|max:100',
            'credits' => 'required_if:kind,topup|nullable|integer|min:1|max:100',
        ]);

        if ($data['kind'] === 'subscription') {
            $cfg = config("spekta.plans.{$data['plan']}");
            $seats = $data['plan'] === 'team' ? max((int) ($data['seats'] ?? 3), $cfg['min_seats']) : 1;
            $amount = ($cfg['price_idr'] ?? $cfg['price_idr_per_seat']) * ($data['plan'] === 'team' ? $seats : 1);
            $attrs = ['kind' => 'subscription', 'plan' => $data['plan'], 'seats' => $seats, 'amount' => $amount];
        } else {
            $credits = (int) $data['credits'];
            $attrs = ['kind' => 'topup', 'credits' => $credits, 'amount' => $credits * config('spekta.topup.price_per_credit_idr')];
        }

        $payment = $billing->checkout($workspace, $request->user()->email, $attrs);

        return Inertia::location($payment->redirect_url); // ke halaman pembayaran Snap
    }

    /** Webhook Midtrans — tanpa auth, verifikasi signature di service. */
    public function notify(Request $request, MidtransBilling $billing)
    {
        $billing->handleNotification($request->all());

        return response()->json(['ok' => true]);
    }
}
