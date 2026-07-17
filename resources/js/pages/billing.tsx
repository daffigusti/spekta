import { Head, router } from '@inertiajs/react';

import { promptDialog } from '@/components/system-dialog';
import SpektaLayout from '@/layouts/spekta-layout';

type PlanCard = {
    key: string;
    label: string;
    price_idr: number;
    per_seat: boolean;
    min_seats: number;
    blueprints: number | null;
    chats: number | null;
    members: number | null;
    client_portal: boolean;
};

type PaymentRow = {
    id: string;
    order_id: string;
    kind: string;
    plan: string | null;
    credits: number | null;
    amount: number;
    status: string;
    redirect_url: string | null;
    created_at: string;
};

type Props = {
    plan: string;
    plan_status: string;
    period_end: string | null;
    seats: number;
    credits: number;
    plans: PlanCard[];
    topup: { price_per_credit_idr: number; packs: number[] };
    payments: PaymentRow[];
};

const fmt = (n: number) => 'Rp ' + Math.round(n / 1000).toLocaleString('id-ID') + 'rb';

const statusCls: Record<string, string> = {
    paid: 'bg-emerald-100 text-emerald-800',
    pending: 'bg-amber-100 text-amber-800',
    failed: 'bg-red-100 text-red-700',
    expired: 'bg-gray-200 text-gray-500',
};

export default function Billing({ plan, plan_status, period_end, credits, plans, topup, payments }: Props) {
    const checkout = (payload: { kind: string; plan?: string; seats?: number; credits?: number }) => router.post(route('billing.checkout'), payload);

    return (
        <SpektaLayout crumb="Billing" active="settings">
            <Head title="Billing — Spekta" />

            <div className="mx-auto max-w-[980px]">
                <h1 className="text-[26px] font-extrabold tracking-[-0.02em] text-gray-900">Billing</h1>
                <div className="mt-1 text-sm font-medium text-gray-500">
                    Paket <b className="font-bold text-gray-800 capitalize">{plan}</b>
                    {period_end && <> · aktif s/d {period_end}</>} · sisa kredit <span className="font-mono font-bold">{credits}</span>
                    {plan_status === 'grace' && (
                        <span className="ml-2 rounded-full bg-amber-100 px-2.5 py-0.5 text-[10px] font-extrabold text-amber-800">GRACE PERIOD 7 HARI (BR-05)</span>
                    )}
                    {plan_status === 'readonly' && (
                        <span className="ml-2 rounded-full bg-red-100 px-2.5 py-0.5 text-[10px] font-extrabold text-red-700">READ-ONLY — perbarui pembayaran</span>
                    )}
                </div>

                {/* paket per BR-01 */}
                <div className="mt-6 grid gap-4" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(210px,1fr))' }}>
                    {plans.map((p) => {
                        const current = p.key === plan;
                        return (
                            <div key={p.key} className={`rounded-xl border bg-white p-[18px] ${current ? 'border-2 border-teal-600' : 'border-gray-200'}`}>
                                <div className="flex items-center justify-between">
                                    <div className="text-[15px] font-extrabold text-gray-900">{p.label}</div>
                                    {current && <span className="rounded-full bg-teal-100 px-2 py-0.5 text-[9px] font-extrabold text-teal-800">AKTIF</span>}
                                </div>
                                <div className="mt-1.5 font-mono text-xl font-extrabold text-gray-900">
                                    {p.price_idr === 0 ? 'Rp 0' : fmt(p.price_idr)}
                                    <span className="text-[11px] font-semibold text-gray-400">{p.per_seat ? ' /seat/bln' : ' /bln'}</span>
                                </div>
                                <ul className="mt-3 flex flex-col gap-1 text-xs font-medium text-gray-600">
                                    <li>✓ {p.blueprints === null ? 'Blueprint unlimited (per seat)' : `${p.blueprints} blueprint/bln`}</li>
                                    <li>✓ {p.chats === null ? 'AI chat unlimited' : `${p.chats} AI chat/bln`}</li>
                                    <li>✓ {p.members === null ? `Min ${p.min_seats} seat` : `${p.members} anggota`}</li>
                                    <li className={p.client_portal ? '' : 'text-gray-300'}>{p.client_portal ? '✓' : '✕'} Portal klien + estimator</li>
                                </ul>
                                {p.key !== 'free' && !current && (
                                    <button
                                        className="mt-4 w-full rounded-[10px] bg-teal-600 py-2 text-xs font-bold text-white hover:bg-teal-700"
                                        onClick={async () =>
                                            checkout({
                                                kind: 'subscription',
                                                plan: p.key,
                                                seats: p.per_seat
                                                    ? Number((await promptDialog(`Jumlah seat (min ${p.min_seats}):`, String(p.min_seats))) ?? p.min_seats)
                                                    : 1,
                                            })
                                        }
                                    >
                                        {p.per_seat ? 'Pilih Team' : `Upgrade ke ${p.label}`}
                                    </button>
                                )}
                            </div>
                        );
                    })}
                </div>

                {/* top-up BR-03 */}
                <div className="mt-5 rounded-xl border border-gray-200 bg-white p-[18px]">
                    <div className="text-[15px] font-bold text-gray-800">Top-up kredit blueprint</div>
                    <div className="mt-1 text-xs font-medium text-gray-400">
                        {fmt(topup.price_per_credit_idr)}/kredit · berlaku 12 bulan · dipakai setelah kredit paket habis (BR-03)
                    </div>
                    <div className="mt-3 flex flex-wrap gap-2">
                        {topup.packs.map((n) => (
                            <button
                                key={n}
                                className="rounded-[10px] border-2 border-gray-200 px-4 py-2 text-[13px] font-bold text-gray-700 hover:border-teal-400 hover:text-teal-800"
                                onClick={() => checkout({ kind: 'topup', credits: n })}
                            >
                                {n} kredit — {fmt(n * topup.price_per_credit_idr)}
                            </button>
                        ))}
                    </div>
                </div>

                {/* riwayat */}
                {payments.length > 0 && (
                    <div className="mt-5 overflow-x-auto rounded-xl border border-gray-200 bg-white">
                        <table className="w-full min-w-[560px] text-[13px]">
                            <thead>
                                <tr>
                                    {['Order', 'Item', 'Jumlah', 'Status', 'Tanggal', ''].map((h) => (
                                        <th key={h} className="border-b border-gray-200 bg-gray-50 px-4 py-2.5 text-left text-[10px] font-bold tracking-[0.08em] text-gray-500 uppercase">
                                            {h}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {payments.map((p) => (
                                    <tr key={p.id} className="border-b border-gray-100">
                                        <td className="px-4 py-2.5 font-mono text-xs text-gray-500">{p.order_id}</td>
                                        <td className="px-4 py-2.5 font-semibold text-gray-700">
                                            {p.kind === 'topup' ? `Top-up ${p.credits} kredit` : `Paket ${p.plan}`}
                                        </td>
                                        <td className="px-4 py-2.5 font-mono font-semibold text-gray-700">{fmt(p.amount)}</td>
                                        <td className="px-4 py-2.5">
                                            <span className={`rounded-full px-2 py-0.5 text-[10px] font-extrabold uppercase ${statusCls[p.status] ?? ''}`}>{p.status}</span>
                                        </td>
                                        <td className="px-4 py-2.5 text-xs text-gray-400">{p.created_at}</td>
                                        <td className="px-4 py-2.5">
                                            {p.redirect_url && (
                                                <a href={p.redirect_url} className="text-xs font-bold text-teal-700 hover:text-teal-900">
                                                    Bayar →
                                                </a>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </SpektaLayout>
    );
}
