import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';

import AssistantDrawer from '@/components/assistant-drawer';
import { confirmDialog } from '@/components/system-dialog';
import SpektaLayout from '@/layouts/spekta-layout';

// ---- skema wireframe (lihat SpecEngine::WIREFRAME_SYSTEM) ----
type Field = { el: string; label?: string; variant?: string };
type Section = {
    type: string;
    title?: string;
    subtitle?: string;
    cta?: string;
    label?: string;
    items?: string[];
    columns?: string[];
    rows?: number;
    lines?: number;
    fields?: Field[];
};
type Screen = {
    id: string;
    name: string;
    flow: string;
    device?: string;
    note?: string | null;
    sections: Section[];
};

type DocVersion = { id: string; version_no: number; source: string; created_at: string };
type WireframeDoc = {
    id: string;
    doc_key: string;
    title: string;
    version_no: number | null;
    content_md: string | null;
    versions: DocVersion[];
};

type Props = {
    project: { id: string; name: string; client_name: string | null; status: string };
    document: WireframeDoc | null;
    run: { id: string; status: string; nodes: { doc_key: string; status: string }[] } | null;
    assistant_messages: { id: string; role: string; body: string }[];
    chat_stream: string | null;
    chat_quota?: { used: number; limit: number | null; plan: string } | null;
    errors: Record<string, string>;
};

function parseWireframes(raw: string | null): { screens: Screen[]; error: string | null } {
    if (!raw) return { screens: [], error: null };
    try {
        const clean = raw.replace(/^```(json)?\s*|```\s*$/gm, '').trim();
        const data = JSON.parse(clean);
        const screens = Array.isArray(data?.screens) ? data.screens : [];
        return { screens, error: screens.length ? null : 'JSON valid tapi tidak ada "screens".' };
    } catch {
        return { screens: [], error: 'Konten WIREFRAMES bukan JSON valid — minta asisten memperbaikinya via chat.' };
    }
}

// ---- primitif low-fi ----
const Ph = ({ w = '100%', h = 8 }: { w?: string | number; h?: number }) => (
    <div className="rounded bg-gray-200" style={{ width: w, height: h }} />
);

function SectionView({ s }: { s: Section }) {
    switch (s.type) {
        case 'navbar':
            return (
                <div className="flex items-center gap-2 border-b border-gray-200 pb-2">
                    <div className="h-5 w-5 flex-none rounded bg-gray-400" />
                    {(s.items ?? []).slice(0, 5).map((it, i) => (
                        <span key={i} className={`truncate text-[10px] font-semibold ${i === 0 ? 'text-gray-700' : 'text-gray-400'}`}>
                            {it}
                        </span>
                    ))}
                </div>
            );
        case 'hero':
            return (
                <div className="flex flex-col items-center gap-2 rounded-lg border border-dashed border-gray-300 bg-gray-50 px-3 py-5 text-center">
                    <div className="text-[12px] leading-tight font-extrabold text-gray-700">{s.title ?? 'Headline'}</div>
                    {s.subtitle && <div className="text-[9.5px] font-medium text-gray-400">{s.subtitle}</div>}
                    {s.cta && <span className="rounded-md bg-gray-700 px-3 py-1 text-[9.5px] font-bold text-white">{s.cta}</span>}
                </div>
            );
        case 'form':
            return (
                <div className="rounded-lg border border-gray-200 p-2.5">
                    {s.title && <div className="mb-2 text-[10.5px] font-extrabold text-gray-700">{s.title}</div>}
                    <div className="flex flex-col gap-1.5">
                        {(s.fields ?? []).map((f, i) =>
                            f.el === 'button' ? (
                                <span
                                    key={i}
                                    className={`self-start rounded-md px-3 py-1 text-[9.5px] font-bold ${
                                        f.variant === 'primary' ? 'bg-gray-700 text-white' : 'border border-gray-400 text-gray-600'
                                    }`}
                                >
                                    {f.label ?? 'Tombol'}
                                </span>
                            ) : f.el === 'checkbox' ? (
                                <div key={i} className="flex items-center gap-1.5">
                                    <div className="h-3 w-3 flex-none rounded-[3px] border-[1.5px] border-gray-400" />
                                    <span className="text-[9.5px] font-medium text-gray-500">{f.label}</span>
                                </div>
                            ) : (
                                <div key={i}>
                                    <div className="mb-0.5 text-[9px] font-semibold text-gray-500">{f.label}</div>
                                    <div
                                        className={`w-full rounded-md border border-gray-300 bg-white ${f.el === 'textarea' ? 'h-10' : 'h-6'} ${
                                            f.el === 'select' ? 'flex items-center justify-end pr-1.5' : ''
                                        }`}
                                    >
                                        {f.el === 'select' && <span className="text-[8px] text-gray-400">▾</span>}
                                    </div>
                                </div>
                            ),
                        )}
                    </div>
                </div>
            );
        case 'table':
            return (
                <div>
                    {s.title && <div className="mb-1 text-[10.5px] font-extrabold text-gray-700">{s.title}</div>}
                    <div className="overflow-hidden rounded-md border border-gray-200">
                        <div className="flex gap-px border-b border-gray-200 bg-gray-100 px-1.5 py-1">
                            {(s.columns ?? ['A', 'B', 'C']).map((c, i) => (
                                <span key={i} className="flex-1 truncate text-[8.5px] font-bold tracking-wide text-gray-500 uppercase">
                                    {c}
                                </span>
                            ))}
                        </div>
                        {Array.from({ length: Math.min(s.rows ?? 3, 6) }).map((_, r) => (
                            <div key={r} className="flex gap-2 border-b border-gray-100 px-1.5 py-1.5 last:border-0">
                                {(s.columns ?? ['A', 'B', 'C']).map((_, c) => (
                                    <div key={c} className="flex-1">
                                        <Ph h={6} w={`${55 + ((r * 3 + c) % 4) * 12}%`} />
                                    </div>
                                ))}
                            </div>
                        ))}
                    </div>
                </div>
            );
        case 'cards':
            return (
                <div>
                    {s.title && <div className="mb-1 text-[10.5px] font-extrabold text-gray-700">{s.title}</div>}
                    <div className="grid grid-cols-2 gap-1.5">
                        {(s.items ?? ['Kartu 1', 'Kartu 2']).slice(0, 6).map((it, i) => (
                            <div key={i} className="rounded-md border border-gray-200 p-1.5">
                                <div className="relative mb-1 h-9 overflow-hidden rounded bg-gray-100">
                                    <div className="absolute inset-0" style={{ background: 'linear-gradient(to top right, transparent calc(50% - 1px), #d1d5db, transparent calc(50% + 1px)), linear-gradient(to bottom right, transparent calc(50% - 1px), #d1d5db, transparent calc(50% + 1px))' }} />
                                </div>
                                <div className="truncate text-[9px] font-bold text-gray-600">{it}</div>
                                <div className="mt-1"><Ph h={5} w="70%" /></div>
                            </div>
                        ))}
                    </div>
                </div>
            );
        case 'list':
            return (
                <div>
                    {s.title && <div className="mb-1 text-[10.5px] font-extrabold text-gray-700">{s.title}</div>}
                    <div className="flex flex-col gap-1">
                        {(s.items ?? []).slice(0, 6).map((it, i) => (
                            <div key={i} className="flex items-center gap-1.5 rounded-md border border-gray-200 px-1.5 py-1">
                                <div className="h-2 w-2 flex-none rounded-full bg-gray-300" />
                                <span className="truncate text-[9.5px] font-medium text-gray-600">{it}</span>
                            </div>
                        ))}
                    </div>
                </div>
            );
        case 'stats':
            return (
                <div className="grid grid-cols-3 gap-1.5">
                    {(s.items ?? []).slice(0, 6).map((it, i) => (
                        <div key={i} className="rounded-md border border-gray-200 p-1.5">
                            <div className="mb-1"><Ph h={10} w="50%" /></div>
                            <div className="truncate text-[8.5px] font-semibold text-gray-500">{it}</div>
                        </div>
                    ))}
                </div>
            );
        case 'text':
            return (
                <div>
                    {s.title && <div className="mb-1 text-[10.5px] font-extrabold text-gray-700">{s.title}</div>}
                    <div className="flex flex-col gap-1">
                        {Array.from({ length: Math.min(s.lines ?? 3, 8) }).map((_, i) => (
                            <Ph key={i} h={6} w={`${95 - (i % 3) * 18}%`} />
                        ))}
                    </div>
                </div>
            );
        case 'image':
            return (
                <div className="relative h-16 overflow-hidden rounded-md border border-dashed border-gray-300 bg-gray-50">
                    <div className="absolute inset-0" style={{ background: 'linear-gradient(to top right, transparent calc(50% - 1px), #d1d5db, transparent calc(50% + 1px)), linear-gradient(to bottom right, transparent calc(50% - 1px), #d1d5db, transparent calc(50% + 1px))' }} />
                    {s.label && <span className="absolute bottom-1 left-1.5 text-[8.5px] font-semibold text-gray-400">{s.label}</span>}
                </div>
            );
        case 'tabs':
            return (
                <div className="flex gap-1 border-b border-gray-200 pb-1">
                    {(s.items ?? []).slice(0, 5).map((it, i) => (
                        <span
                            key={i}
                            className={`rounded-t px-2 py-0.5 text-[9px] font-bold ${i === 0 ? 'border-b-2 border-gray-600 text-gray-700' : 'text-gray-400'}`}
                        >
                            {it}
                        </span>
                    ))}
                </div>
            );
        case 'footer':
            return (
                <div className="flex items-center justify-between border-t border-gray-200 pt-2">
                    <div className="h-4 w-4 rounded bg-gray-300" />
                    <div className="flex gap-2">
                        <Ph h={5} w={28} />
                        <Ph h={5} w={28} />
                        <Ph h={5} w={28} />
                    </div>
                </div>
            );
        default:
            return (
                <div className="rounded-md border border-dashed border-gray-300 bg-gray-50 px-2 py-3 text-center text-[9px] font-semibold text-gray-400">
                    {s.type}
                </div>
            );
    }
}

function ScreenFrame({ screen, selected, onSelect }: { screen: Screen; selected: boolean; onSelect: () => void }) {
    const mobile = screen.device === 'mobile';
    return (
        <div
            onClick={(e) => {
                e.stopPropagation();
                onSelect();
            }}
            className={`flex-none cursor-pointer overflow-hidden rounded-xl border-2 bg-white shadow-[0_4px_16px_rgba(15,23,42,.08)] transition-shadow ${
                selected ? 'border-teal-500 shadow-[0_4px_20px_rgba(13,148,136,.25)]' : 'border-gray-200 hover:border-gray-300'
            }`}
            style={{ width: mobile ? 220 : 340 }}
        >
            <div className="flex items-center gap-1.5 border-b border-gray-200 bg-gray-50 px-3 py-2">
                <span className="h-2 w-2 rounded-full bg-gray-300" />
                <span className="h-2 w-2 rounded-full bg-gray-300" />
                <span className="min-w-0 flex-1 truncate text-center text-[10px] font-bold text-gray-600">{screen.name}</span>
                <span className="rounded bg-gray-200 px-1.5 py-px text-[8px] font-extrabold tracking-wide text-gray-500 uppercase">
                    {mobile ? 'Mobile' : 'Desktop'}
                </span>
            </div>
            <div className="flex flex-col gap-2.5 p-3">
                {(screen.sections ?? []).map((s, i) => (
                    <SectionView key={i} s={s} />
                ))}
            </div>
            {screen.note && (
                <div className="border-t border-dashed border-amber-300 bg-amber-50 px-3 py-1.5 text-[9px] font-semibold text-amber-800">
                    {screen.note}
                </div>
            )}
        </div>
    );
}

export default function WireframesPage({ project, document: doc, run, assistant_messages = [], chat_stream = null, errors = {} }: Props) {
    const [chatOpen, setChatOpen] = useState(false);
    const [selectedId, setSelectedId] = useState<string | null>(null);
    const [view, setView] = useState({ x: 48, y: 48, k: 0.85 });
    const canvasRef = useRef<HTMLDivElement>(null);
    const dragRef = useRef<{ px: number; py: number } | null>(null);

    const { screens, error: parseError } = useMemo(() => parseWireframes(doc?.content_md ?? null), [doc?.content_md]);

    // grup per flow, urutan kemunculan dipertahankan (urutan array = urutan langkah)
    const flows = useMemo(() => {
        const m = new Map<string, Screen[]>();
        for (const s of screens) {
            const key = s.flow || 'Flow utama';
            if (!m.has(key)) m.set(key, []);
            m.get(key)!.push(s);
        }
        return [...m.entries()];
    }, [screens]);

    const selected = screens.find((s) => s.id === selectedId) ?? null;

    const lastMsg = assistant_messages[assistant_messages.length - 1];
    const chatBusy = chat_stream != null || lastMsg?.role === 'user';

    // wheel: pan · cmd/ctrl+wheel: zoom ke arah kursor — listener non-passive agar bisa preventDefault
    useEffect(() => {
        const el = canvasRef.current;
        if (!el) return;
        const onWheel = (e: WheelEvent) => {
            e.preventDefault();
            setView((v) => {
                if (e.ctrlKey || e.metaKey) {
                    const k = Math.min(2, Math.max(0.2, v.k * (1 - e.deltaY * 0.01)));
                    const rect = el.getBoundingClientRect();
                    const mx = e.clientX - rect.left;
                    const my = e.clientY - rect.top;
                    return { k, x: mx - ((mx - v.x) * k) / v.k, y: my - ((my - v.y) * k) / v.k };
                }
                return { ...v, x: v.x - e.deltaX, y: v.y - e.deltaY };
            });
        };
        el.addEventListener('wheel', onWheel, { passive: false });
        return () => el.removeEventListener('wheel', onWheel);
    }, []);

    // poll saat generate WIREFRAMES masih jalan
    const runActive = run != null && ['queued', 'running'].includes(run.status);
    useEffect(() => {
        if (!runActive) return;
        const t = setInterval(() => router.reload({ only: ['run', 'document'] }), 2500);
        return () => clearInterval(t);
    }, [runActive]);

    const zoom = (dir: 1 | -1) =>
        setView((v) => {
            const k = Math.min(2, Math.max(0.2, v.k * (dir === 1 ? 1.2 : 1 / 1.2)));
            const el = canvasRef.current;
            if (!el) return { ...v, k };
            const mx = el.clientWidth / 2;
            const my = el.clientHeight / 2;
            return { k, x: mx - ((mx - v.x) * k) / v.k, y: my - ((my - v.y) * k) / v.k };
        });

    const wireframeNode = run?.nodes.find((n) => n.doc_key === 'WIREFRAMES');

    return (
        <SpektaLayout crumb={project.name} active="projects">
            <Head title={`Wireframe — ${project.name}`} />
            {/* -m-7 batalkan padding <main>; 60px = tinggi topbar */}
            <div className="-m-7 flex h-[calc(100vh-60px)] flex-col">
                <div className="flex flex-none flex-wrap items-center gap-3 border-b border-gray-200 bg-white px-6 py-3">
                    <Link href={route('projects.show', project.id)} className="text-[13px] font-bold text-gray-500 hover:text-teal-700">
                        ← {project.name}
                    </Link>
                    <span className="text-gray-300">/</span>
                    <h1 className="text-[15px] font-extrabold text-gray-900">Wireframe</h1>
                    {doc && (
                        <span className="font-mono text-[11px] font-semibold text-gray-400">
                            v{doc.version_no ?? 1} · {screens.length} layar · {flows.length} flow
                        </span>
                    )}
                    <div className="ml-auto flex items-center gap-2">
                        <div className="flex items-center overflow-hidden rounded-[10px] border border-gray-200 bg-white">
                            <button className="px-2.5 py-1.5 text-[13px] font-bold text-gray-600 hover:bg-gray-50" onClick={() => zoom(-1)}>
                                −
                            </button>
                            <span className="border-x border-gray-200 px-2 py-1.5 font-mono text-[11px] font-bold text-gray-500">
                                {Math.round(view.k * 100)}%
                            </span>
                            <button className="px-2.5 py-1.5 text-[13px] font-bold text-gray-600 hover:bg-gray-50" onClick={() => zoom(1)}>
                                +
                            </button>
                        </div>
                        <button
                            className="rounded-[10px] border border-gray-200 bg-white px-3 py-1.5 text-[12px] font-bold text-gray-600 hover:bg-gray-50"
                            onClick={() => setView({ x: 48, y: 48, k: 0.85 })}
                        >
                            Reset
                        </button>
                        <button
                            onClick={() => setChatOpen(true)}
                            className="inline-flex items-center gap-1.5 rounded-[10px] border-2 border-teal-200 bg-teal-50 px-3.5 py-1.5 text-[12.5px] font-bold text-teal-800 hover:bg-teal-100"
                        >
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#0D9488" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
                                <path d="m12 3-1.9 5.8a2 2 0 0 1-1.3 1.3L3 12l5.8 1.9a2 2 0 0 1 1.3 1.3L12 21l1.9-5.8a2 2 0 0 1 1.3-1.3L21 12l-5.8-1.9a2 2 0 0 1-1.3-1.3L12 3z" />
                            </svg>
                            Revisi via AI
                            {chatBusy && (
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#0D9488" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round" className="animate-spin">
                                    <path d="M21 12a9 9 0 1 1-6.219-8.56" />
                                </svg>
                            )}
                        </button>
                    </div>
                </div>

                {/* canvas pan/zoom, grid dot */}
                <div
                    ref={canvasRef}
                    className="relative min-h-0 flex-1 cursor-grab overflow-hidden active:cursor-grabbing"
                    style={{
                        backgroundColor: '#F8FAFC',
                        backgroundImage: 'radial-gradient(#CBD5E1 1px, transparent 1px)',
                        backgroundSize: `${22 * view.k}px ${22 * view.k}px`,
                        backgroundPosition: `${view.x}px ${view.y}px`,
                    }}
                    onPointerDown={(e) => {
                        dragRef.current = { px: e.clientX, py: e.clientY };
                        (e.target as HTMLElement).setPointerCapture?.(e.pointerId);
                    }}
                    onPointerMove={(e) => {
                        if (!dragRef.current) return;
                        const { px, py } = dragRef.current;
                        dragRef.current = { px: e.clientX, py: e.clientY };
                        setView((v) => ({ ...v, x: v.x + e.clientX - px, y: v.y + e.clientY - py }));
                    }}
                    onPointerUp={() => (dragRef.current = null)}
                    onClick={() => setSelectedId(null)}
                >
                    {doc && screens.length > 0 && (
                        <div style={{ transform: `translate(${view.x}px, ${view.y}px) scale(${view.k})`, transformOrigin: '0 0' }}>
                            <div className="flex w-max flex-col gap-12 p-4">
                                {flows.map(([flow, list]) => (
                                    <div key={flow}>
                                        <div className="mb-3 flex items-center gap-2">
                                            <span className="rounded-full bg-teal-600 px-3 py-1 text-[11px] font-extrabold tracking-wide text-white">
                                                {flow}
                                            </span>
                                            <span className="font-mono text-[10.5px] font-semibold text-gray-400">{list.length} layar</span>
                                        </div>
                                        <div className="flex items-start gap-0">
                                            {list.map((s, i) => (
                                                <div key={s.id ?? i} className="flex items-start">
                                                    {i > 0 && (
                                                        <div className="flex w-10 flex-none items-center justify-center self-stretch">
                                                            <svg width="26" height="14" viewBox="0 0 26 14" fill="none" stroke="#94A3B8" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" style={{ marginTop: 90 }}>
                                                                <line x1="1" y1="7" x2="21" y2="7" />
                                                                <polyline points="16 2 21 7 16 12" />
                                                            </svg>
                                                        </div>
                                                    )}
                                                    <ScreenFrame
                                                        screen={s}
                                                        selected={selectedId === s.id}
                                                        onSelect={() => setSelectedId(s.id)}
                                                    />
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}

                    {/* state kosong / error parse / sedang generate */}
                    {(!doc || screens.length === 0) && (
                        <div className="flex h-full items-center justify-center">
                            <div className="max-w-md rounded-xl border border-gray-200 bg-white px-8 py-8 text-center shadow-sm">
                                {runActive && wireframeNode?.status !== 'done' ? (
                                    <>
                                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#0D9488" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" className="mx-auto animate-spin">
                                            <path d="M21 12a9 9 0 1 1-6.219-8.56" />
                                        </svg>
                                        <div className="mt-3 text-[14px] font-extrabold text-gray-900">Sedang generate wireframe…</div>
                                        <div className="mt-1 text-[12.5px] font-medium text-gray-500">
                                            AI menggambar layar low-fi per user flow. Halaman ini refresh otomatis.
                                        </div>
                                    </>
                                ) : parseError ? (
                                    <>
                                        <div className="text-[14px] font-extrabold text-red-700">Wireframe tidak bisa dirender</div>
                                        <div className="mt-1 text-[12.5px] font-medium text-gray-500">{parseError}</div>
                                        <button
                                            onClick={() => setChatOpen(true)}
                                            className="mt-4 rounded-[10px] bg-teal-600 px-4 py-2 text-[13px] font-bold text-white hover:bg-teal-700"
                                        >
                                            Perbaiki via AI chat
                                        </button>
                                    </>
                                ) : (
                                    <>
                                        <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="#9CA3AF" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" className="mx-auto">
                                            <rect x="3" y="3" width="18" height="18" rx="2" />
                                            <line x1="3" y1="9" x2="21" y2="9" />
                                            <line x1="9" y1="21" x2="9" y2="9" />
                                        </svg>
                                        <div className="mt-3 text-[14px] font-extrabold text-gray-900">Belum ada wireframe</div>
                                        <div className="mt-1 text-[12.5px] font-medium text-gray-500">
                                            AI akan menggambar wireframe low-fi untuk tiap user flow — klien memvalidasi gambar, bukan teks.
                                        </div>
                                        <button
                                            onClick={async () => {
                                                if (!(await confirmDialog('Generate wireframe + dokumen pipeline lain yang belum ada? Memakai 1 kredit.'))) return;
                                                router.post(route('projects.generate.missing', project.id), {}, { preserveScroll: true });
                                            }}
                                            className="mt-4 rounded-[10px] bg-teal-600 px-4 py-2 text-[13px] font-bold text-white hover:bg-teal-700"
                                        >
                                            ✦ Generate wireframe
                                        </button>
                                        {errors.credits && <div className="mt-2 text-[11.5px] font-semibold text-red-600">{errors.credits}</div>}
                                    </>
                                )}
                            </div>
                        </div>
                    )}

                    {/* hint layar terpilih */}
                    {selected && (
                        <div
                            className="absolute bottom-4 left-1/2 flex -translate-x-1/2 items-center gap-2.5 rounded-full border border-gray-200 bg-white px-4 py-2 shadow-lg"
                            onClick={(e) => e.stopPropagation()}
                            onPointerDown={(e) => e.stopPropagation()}
                        >
                            <span className="text-[12px] font-bold text-gray-700">{selected.name}</span>
                            <span className="text-gray-300">·</span>
                            <span className="text-[11.5px] font-medium text-gray-400">{selected.flow}</span>
                            <button
                                onClick={() => setChatOpen(true)}
                                className="rounded-full bg-teal-600 px-3 py-1 text-[11px] font-bold text-white hover:bg-teal-700"
                            >
                                Revisi layar ini via AI
                            </button>
                        </div>
                    )}
                </div>

                {/* riwayat versi + restore */}
                {doc && doc.versions.length > 1 && (
                    <div className="flex flex-none flex-wrap items-center gap-2 border-t border-gray-200 bg-white px-6 py-2 text-[11px] text-gray-400">
                        Riwayat:
                        {doc.versions.map((v) => (
                            <span
                                key={v.id}
                                className={`group inline-flex items-center gap-1 rounded-full border px-2 py-0.5 font-mono ${
                                    v.source === 'user' ? 'border-blue-200 text-blue-600' : 'border-amber-200 text-amber-600'
                                }`}
                            >
                                v{v.version_no} · {v.source}
                                {v.version_no !== doc.version_no && (
                                    <button
                                        title={`Pulihkan v${v.version_no}`}
                                        className="hidden text-gray-400 group-hover:inline hover:text-teal-700"
                                        onClick={async () => {
                                            if (!(await confirmDialog(`Pulihkan v${v.version_no}? Isi lama disalin jadi versi baru v${(doc.version_no ?? 0) + 1}.`)))
                                                return;
                                            router.post(route('documents.versions.restore', [doc.id, v.version_no]), {}, { preserveScroll: true, only: ['document', 'errors'] });
                                        }}
                                    >
                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round">
                                            <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8" />
                                            <path d="M3 3v5h5" />
                                        </svg>
                                    </button>
                                )}
                            </span>
                        ))}
                    </div>
                )}
            </div>

            <AssistantDrawer
                open={chatOpen}
                onClose={() => setChatOpen(false)}
                projectId={project.id}
                projectName={project.name}
                contextLabel={selected ? `WIREFRAMES · layar "${selected.name}"` : 'WIREFRAMES'}
                docKey="WIREFRAMES"
                screen={selected?.name ?? null}
                applyTargets={doc ? [{ id: doc.id, doc_key: doc.doc_key, content_md: doc.content_md }] : []}
                messages={assistant_messages}
                stream={chat_stream}
                quota={chat_quota}
                error={errors.assistant}
                reloadOnApply={['document', 'errors']}
                placeholder={selected ? `Revisi layar "${selected.name}"… (Enter)` : 'Minta revisi wireframe… (Enter)'}
                emptyHint={'Minta perubahan wireframe — mis. "Tambah kolom No. Telepon di form registrasi" atau "Tambah layar konfirmasi setelah checkout".'}
            />
        </SpektaLayout>
    );
}
