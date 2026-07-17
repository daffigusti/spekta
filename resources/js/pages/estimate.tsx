import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

import { promptDialog } from '@/components/system-dialog';
import SpektaLayout from '@/layouts/spekta-layout';

type Line = {
    id: string;
    feature: string | null;
    scope: string | null;
    md: number;
    cost: number;
    overridden: boolean;
    override_reason: string | null;
};

type TimelineEntry = {
    label: string;
    start_week: number;
    weeks: number;
    md: number;
    kind: string;
};

type EstimateData = {
    id: string;
    scope: string;
    total_md: number;
    range_pct: number;
    total_cost: number;
    currency: string;
    team_composition: { role: string; md: number }[] | null;
    duration_weeks: number | null;
    timeline: TimelineEntry[] | null;
    lines: Line[];
};

type Props = {
    project: { id: string; name: string; client_name: string | null };
    estimates: EstimateData[];
};

const fmtIdr = (n: number) =>
    n >= 1_000_000_000 ? `Rp ${(n / 1_000_000_000).toFixed(1)} M` : n >= 1_000_000 ? `Rp ${Math.round(n / 1_000_000)} jt` : `Rp ${Math.round(n / 1000)} rb`;

export default function EstimatePage({ project, estimates }: Props) {
    const [scope, setScope] = useState<'mvp' | 'full'>('full');
    const est = estimates.find((e) => e.scope === scope);
    const other = estimates.find((e) => e.scope !== scope);

    const override = async (line: Line) => {
        const md = await promptDialog(`Override MD untuk "${line.feature}" (sekarang ${line.md} MD):`, String(line.md));
        if (!md || isNaN(Number(md))) return;
        const reason = await promptDialog('Alasan override (tercatat, FR-14):');
        if (!reason) return;
        router.patch(route('projects.estimate.override', [project.id, est!.id, line.id]), { md: Number(md), reason }, { preserveScroll: true });
    };

    const team = est?.team_composition?.map((t) => `${t.md} ${t.role}`).join(' · ');

    return (
        <SpektaLayout crumb={`${project.name} · Estimasi`} active="projects">
            <Head title={`${project.name} — Estimasi & RAB`} />

            <div className="mb-4">
                <Link href={route('projects.show', project.id)} className="text-xs font-semibold text-gray-400 hover:text-teal-800">
                    ← Workspace · {project.name}
                </Link>
                <div className="mt-1.5 flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 className="text-[26px] font-extrabold tracking-[-0.02em] text-gray-900">Estimasi &amp; RAB</h1>
                        <div className="mt-1 text-sm font-medium tracking-[0.02em] text-gray-500">
                            {project.name} · dihitung dari struktur fitur + rate card workspace
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2.5">
                        <button
                            className="inline-flex items-center gap-[7px] rounded-[10px] border border-gray-200 bg-white px-4 py-2.5 text-[13px] font-bold text-gray-700 hover:bg-gray-50"
                            onClick={() => router.post(route('projects.estimate.recompute', project.id), {}, { preserveScroll: true })}
                        >
                            ↻ Hitung ulang
                        </button>
                        <a
                            href={route('projects.export', [project.id, 'rab']) + `?scope=${scope}`}
                            className="inline-flex items-center gap-[7px] rounded-[10px] border border-gray-200 bg-white px-4 py-2.5 text-[13px] font-bold text-gray-700 hover:bg-gray-50"
                        >
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                                <polyline points="7 10 12 15 17 10" />
                                <line x1="12" y1="15" x2="12" y2="3" />
                            </svg>
                            RAB Excel
                        </a>
                        <a
                            href={route('projects.export', [project.id, 'proposal']) + `?scope=${scope}`}
                            className="inline-flex items-center gap-[7px] rounded-[10px] border-2 border-teal-600 bg-white px-4 py-2 text-[13px] font-bold text-teal-800 hover:bg-teal-50"
                        >
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                                <polyline points="14 2 14 8 20 8" />
                            </svg>
                            Generate Proposal
                        </a>
                    </div>
                </div>
            </div>

            {est && (
                <>
                    <div className="mb-4 grid gap-4" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(240px,1fr))' }}>
                        <div className="rounded-xl border border-gray-200 bg-white p-[18px]">
                            <div className="text-[11px] font-bold tracking-[0.08em] text-gray-500">TOTAL EFFORT</div>
                            <div className="mt-1.5 font-mono text-2xl font-extrabold tracking-tight text-gray-900">
                                {est.total_md} <span className="text-[13px] font-semibold text-gray-500">man-days</span>
                            </div>
                            <div className="mt-1 text-[11.5px] font-medium text-gray-400">
                                ±{est.range_pct}% confidence: {Math.round(est.total_md * (1 - est.range_pct / 100))}–{Math.round(est.total_md * (1 + est.range_pct / 100))} MD
                            </div>
                        </div>
                        <div className="rounded-xl border border-gray-200 bg-white p-[18px]">
                            <div className="text-[11px] font-bold tracking-[0.08em] text-gray-500">ESTIMASI BIAYA (RAB)</div>
                            <div className="mt-1.5 font-mono text-2xl font-extrabold tracking-tight text-teal-800">{fmtIdr(est.total_cost)}</div>
                            <div className="mt-1 text-[11.5px] font-medium text-gray-400">rate card workspace · margin tidak tampil ke klien (BR-21)</div>
                        </div>
                        <div className="rounded-xl border border-gray-200 bg-white p-[18px]">
                            <div className="text-[11px] font-bold tracking-[0.08em] text-gray-500">DURASI</div>
                            <div className="mt-1.5 font-mono text-2xl font-extrabold tracking-tight text-gray-900">
                                {est.duration_weeks} <span className="text-[13px] font-semibold text-gray-500">minggu</span>
                            </div>
                            <div className="mt-1 truncate text-[11.5px] font-medium text-gray-400">{team ? `Tim (MD): ${team}` : 'termasuk buffer UAT 15% (BR-20)'}</div>
                        </div>
                    </div>

                    <div className="mb-4 overflow-x-auto rounded-xl border border-gray-200 bg-white">
                        <table className="w-full min-w-[640px] border-collapse text-[13px]">
                            <thead>
                                <tr>
                                    <th className="border-b border-gray-200 bg-gray-50 px-4 py-2.5 text-left text-[10px] font-bold tracking-[0.08em] text-gray-500 uppercase">Modul / Fitur</th>
                                    <th className="w-[100px] border-b border-gray-200 bg-gray-50 px-4 py-2.5 text-right text-[10px] font-bold tracking-[0.08em] text-gray-500 uppercase">Effort (MD)</th>
                                    <th className="w-[110px] border-b border-gray-200 bg-gray-50 px-4 py-2.5 text-right text-[10px] font-bold tracking-[0.08em] text-gray-500 uppercase">Biaya</th>
                                    <th className="w-[90px] border-b border-gray-200 bg-gray-50 px-3 py-2.5 text-center text-[10px] font-bold tracking-[0.08em] text-gray-500 uppercase">Prioritas</th>
                                    <th className="w-[80px] border-b border-gray-200 bg-gray-50" />
                                </tr>
                            </thead>
                            <tbody>
                                {est.lines.map((l) => (
                                    <tr key={l.id} className="border-b border-gray-100 hover:bg-gray-50">
                                        <td className="px-4 py-3 font-bold text-gray-800">
                                            {l.feature}
                                            {l.overridden && (
                                                <span className="ml-2 rounded-full bg-blue-100 px-2 py-0.5 text-[10px] font-bold text-blue-700" title={l.override_reason ?? ''}>
                                                    OVERRIDE
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-right font-mono font-semibold text-gray-700">{l.md}</td>
                                        <td className="px-4 py-3 text-right font-mono font-semibold text-gray-700">{fmtIdr(l.cost)}</td>
                                        <td className="px-3 py-3 text-center">
                                            <span
                                                className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[10px] font-extrabold ${
                                                    l.scope === 'mvp' ? 'bg-teal-100 text-teal-800' : 'bg-gray-100 text-gray-500'
                                                }`}
                                            >
                                                <span className={`h-1.5 w-1.5 rounded-full ${l.scope === 'mvp' ? 'bg-teal-600' : 'bg-gray-400'}`} />
                                                {l.scope === 'mvp' ? 'MVP' : 'FULL'}
                                            </span>
                                        </td>
                                        <td className="px-3 py-3 text-right">
                                            <button className="text-xs font-bold text-gray-400 hover:text-teal-600" onClick={() => override(l)}>
                                                Override
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {est.timeline && est.timeline.length > 0 && (
                        <div className="mb-4 rounded-xl border border-gray-200 bg-white p-[18px]">
                            <div className="mb-1.5 text-[15px] font-bold text-gray-800">Timeline (FR-15)</div>
                            {(() => {
                                const total = est.timeline!.reduce((a, t) => Math.max(a, t.start_week + t.weeks), 0);
                                return est.timeline!.map((t) => (
                                    <div key={t.label} className="mt-[9px] flex items-center gap-3 text-xs font-semibold text-gray-600">
                                        <span className="w-[190px] flex-none truncate">{t.label}</span>
                                        <div className="relative h-[18px] flex-1 rounded-md border border-gray-100 bg-gray-50">
                                            <div
                                                className={`absolute top-0.5 bottom-0.5 rounded ${t.kind === 'buffer' ? 'bg-amber-400' : 'bg-teal-600'}`}
                                                style={{
                                                    left: `${(t.start_week / total) * 100}%`,
                                                    width: `${Math.max((t.weeks / total) * 100, 2)}%`,
                                                }}
                                            />
                                        </div>
                                        <span className="w-[88px] flex-none text-right font-mono text-[11px] text-gray-400">
                                            {t.weeks} mgg · {t.md} MD
                                        </span>
                                    </div>
                                ));
                            })()}
                            <div className="mt-2.5 text-[11px] font-medium text-gray-400">
                                Fase berurutan · slot UAT & buffer 15% di akhir · terhitung ulang otomatis saat scope/estimasi berubah
                            </div>
                        </div>
                    )}

                    <div className="flex flex-wrap items-center gap-2 text-[12.5px] font-semibold text-gray-500">
                        Skenario:
                        {(['full', 'mvp'] as const).map((m) => {
                            const e = m === scope ? est : other;
                            const activeChip = scope === m;
                            const label =
                                m === 'full'
                                    ? 'Full scope'
                                    : e
                                      ? `MVP saja — ${fmtIdr(e.total_cost)} · ${e.duration_weeks} mgg`
                                      : 'MVP saja';
                            return (
                                <button
                                    key={m}
                                    onClick={() => setScope(m)}
                                    className={`rounded-full border-[1.5px] px-[13px] py-[5px] text-xs font-bold ${
                                        activeChip ? 'border-teal-600 bg-teal-50 text-teal-800' : 'border-gray-200 bg-white text-gray-500 hover:border-teal-300'
                                    }`}
                                >
                                    {label}
                                </button>
                            );
                        })}
                    </div>
                </>
            )}
        </SpektaLayout>
    );
}
