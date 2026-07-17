import { Head, useForm } from '@inertiajs/react';

import SpektaLayout from '@/layouts/spekta-layout';

type RoleRate = {
    role: string;
    daily_rate: number;
}

type RateCardData = {
    id: string;
    name: string;
    currency: string;
    is_default: boolean;
    roles: RoleRate[];
    margin_pct: number;
}

// ponytail: level/orang display-only per design, tidak disimpan di DB.
// Persentase alokasi datang dari prop roleSplit (config spekta.estimate.role_split) — sumber sama dengan Estimator.
const ROLE_META: Record<string, { level: string; orang: string }> = {
    FE: { level: 'Mid', orang: '2 orang' },
    BE: { level: 'Mid–Senior', orang: '2 orang' },
    QA: { level: 'Mid', orang: '1 orang' },
    PM: { level: 'Senior', orang: '0.5 orang' },
    DevOps: { level: 'Mid', orang: 'on-demand' },
};

export default function RateCards({ rateCards, roleSplit }: { rateCards: RateCardData[]; roleSplit: Record<string, number> }) {
    const card = rateCards[0];
    const { data, setData, patch, processing, recentlySuccessful } = useForm<{ name: string; margin_pct: number; roles: RoleRate[] }>({
        name: card?.name ?? '',
        margin_pct: card?.margin_pct ?? 30,
        roles: card?.roles ?? [],
    });

    const allocOf = (role: string) => {
        const pct = roleSplit[role];
        if (pct === undefined) return '—';
        const orang = ROLE_META[role]?.orang;
        return `${orang ? orang + ' · ' : ''}${Math.round(pct * 100)}%`;
    };

    // Weighted sesuai role_split engine — cocok dengan Estimator::costOf; role di luar split tidak dihitung
    const rateOf = Object.fromEntries(data.roles.map((r) => [r.role, r.daily_rate]));
    const blended = Object.entries(roleSplit).reduce((sum, [role, pct]) => sum + pct * (rateOf[role] ?? 0), 0);
    const sampleRab = Math.round((blended * 124 * (1 + data.margin_pct / 100)) / 1e6);
    const marginChips = [20, 30, 40].includes(data.margin_pct) ? [20, 30, 40] : [20, 30, 40, data.margin_pct];

    return (
        <SpektaLayout crumb="Rate Card" active="ratecard">
            <Head title="Rate Card — Spekta" />

            <div className="mb-[22px] flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 className="text-[26px] font-extrabold tracking-[-0.02em] text-gray-900">Rate Card</h1>
                    <div className="mt-1 text-sm font-medium tracking-[0.02em] text-gray-500">
                        Dasar perhitungan RAB otomatis · berlaku untuk semua blueprint workspace ini
                    </div>
                </div>
                <div className="flex items-center gap-3">
                    {recentlySuccessful && <span className="text-xs font-bold text-teal-600">Tersimpan ✓</span>}
                    <button
                        className="rounded-[10px] bg-teal-600 px-[18px] py-2.5 text-[13px] font-bold text-white hover:bg-teal-700 disabled:opacity-50"
                        disabled={processing || !card}
                        onClick={() => patch(route('ratecards.update', card.id))}
                    >
                        Simpan perubahan
                    </button>
                </div>
            </div>

            {card && (
                <div className="grid items-start gap-[18px]" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(min(560px,100%),1fr))' }}>
                    {/* tabel roles */}
                    <div className="overflow-x-auto rounded-xl border border-gray-200 bg-white">
                        <table className="w-full min-w-[540px] border-collapse text-[13px]">
                            <thead>
                                <tr>
                                    {['Role', 'Level', `Rate / man-day (${card.currency})`, 'Alokasi tipikal'].map((h, i) => (
                                        <th
                                            key={h}
                                            className={`border-b border-gray-200 bg-gray-50 px-4 py-2.5 text-[10px] font-bold tracking-[0.08em] text-gray-500 uppercase ${i === 2 ? 'text-right' : 'text-left'}`}
                                        >
                                            {h}
                                        </th>
                                    ))}
                                    <th className="border-b border-gray-200 bg-gray-50 w-10" />
                                </tr>
                            </thead>
                            <tbody>
                                {data.roles.map((r, i) => (
                                    <tr key={i} className="border-b border-gray-100 hover:bg-gray-50">
                                        <td className="px-4 py-3">
                                            <input
                                                className="w-full border-0 bg-transparent font-bold text-gray-800 focus:outline-none"
                                                value={r.role}
                                                onChange={(e) => setData('roles', data.roles.map((x, j) => (j === i ? { ...x, role: e.target.value } : x)))}
                                            />
                                        </td>
                                        <td className="px-4 py-3 font-semibold text-gray-500">{ROLE_META[r.role]?.level ?? 'Mid'}</td>
                                        <td className="px-4 py-2 text-right">
                                            <input
                                                type="text"
                                                inputMode="numeric"
                                                className="w-[150px] rounded-[10px] border-2 border-gray-200 bg-gray-50 px-[11px] py-[7px] text-right text-[12.5px] font-semibold text-gray-700 focus:border-teal-400 focus:bg-white focus:shadow-[0_0_0_3px_#F0FDFA] focus:outline-none"
                                                style={{ fontFamily: 'ui-monospace,SFMono-Regular,Menlo,monospace' }}
                                                value={r.daily_rate.toLocaleString('id-ID')}
                                                onChange={(e) => {
                                                    const num = Number(e.target.value.replace(/\D/g, ''));
                                                    setData('roles', data.roles.map((x, j) => (j === i ? { ...x, daily_rate: num } : x)));
                                                }}
                                            />
                                        </td>
                                        <td className="px-4 py-3 font-mono text-xs font-semibold text-gray-500">{allocOf(r.role)}</td>
                                        <td className="pr-3 text-right">
                                            <button
                                                className="text-sm font-bold text-gray-300 hover:text-red-500"
                                                onClick={() => setData('roles', data.roles.filter((_, j) => j !== i))}
                                            >
                                                ✕
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                                <tr>
                                    <td colSpan={5} className="px-4 py-2.5">
                                        <button
                                            className="text-xs font-bold text-gray-400 hover:text-teal-600"
                                            onClick={() => setData('roles', [...data.roles, { role: 'Role', daily_rate: 1_000_000 }])}
                                        >
                                            + Tambah peran
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    {/* panel samping */}
                    <div className="grid content-start gap-3.5" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(260px,1fr))' }}>
                        <div className="rounded-xl border border-gray-200 bg-white p-[18px]">
                            <div className="text-[15px] font-bold text-gray-800">Margin perusahaan</div>
                            <div className="mt-2.5 flex flex-wrap gap-1.5">
                                {marginChips.map((m) => (
                                    <button
                                        key={m}
                                        onClick={() => setData('margin_pct', m)}
                                        className={`rounded-full px-3.5 py-1.5 font-mono text-xs font-bold ${
                                            data.margin_pct === m
                                                ? 'border-[1.5px] border-teal-600 bg-teal-50 text-teal-800'
                                                : 'border-[1.5px] border-gray-200 bg-white text-gray-500 hover:border-teal-300'
                                        }`}
                                    >
                                        {m}%
                                    </button>
                                ))}
                            </div>
                            <div className="mt-2.5 text-[11.5px] leading-relaxed font-medium text-gray-400">
                                Margin diterapkan di atas biaya effort saat RAB &amp; proposal digenerate. Tidak pernah tampil ke klien (BR-21).
                            </div>
                        </div>
                        <div className="rounded-xl border border-gray-200 bg-white p-[18px]">
                            <div className="text-[11px] font-bold tracking-[0.08em] text-gray-500">PREVIEW DAMPAK</div>
                            <div className="mt-3 flex items-baseline justify-between text-[13px] font-semibold text-gray-600">
                                Blended rate
                                <span className="font-mono font-bold text-gray-900">Rp {Math.round(blended / 1000).toLocaleString('id-ID')} rb / MD</span>
                            </div>
                            <div className="mt-2 flex items-baseline justify-between text-[13px] font-semibold text-gray-600">
                                Contoh proyek 124 MD
                                <span className="font-mono text-base font-extrabold text-teal-800">Rp {sampleRab} jt</span>
                            </div>
                            <div className="mt-2.5 text-[11.5px] leading-relaxed font-medium text-gray-400">
                                Angka RAB di semua blueprint mengikuti rate card ini secara otomatis.
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </SpektaLayout>
    );
}
