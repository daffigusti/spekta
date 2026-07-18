import { Head, Link, router } from '@inertiajs/react';
import DOMPurify from 'dompurify';
import { marked } from 'marked';
import { useEffect, useMemo, useState } from 'react';

import AssistantDrawer, { AssistantButton } from '@/components/assistant-drawer';
import ImpactDialog from '@/components/impact-dialog';
import MarkdownPreview from '@/components/markdown-preview';
import { confirmDialog, promptDialog } from '@/components/system-dialog';
import SpektaLayout from '@/layouts/spekta-layout';

type DocVersion = {
    id: string;
    version_no: number;
    source: string;
    created_at: string;
};

type Doc = {
    id: string;
    seq: number;
    doc_key: string;
    title: string;
    status: string;
    group: string;
    version_no: number | null;
    content_md: string | null;
    generated_meta: Record<string, unknown> | null;
    upstream: { doc_key: string; version_no: number | null }[];
    downstream: string[];
    stale: boolean;
    versions: DocVersion[];
};

type Finding = {
    id: string;
    rule_key: string;
    severity: string;
    location: string | null;
    message: string;
    suggestion: string | null;
    dimension: string;
};

type RtmRow = { fr: string; scope: string | null; cells: Record<string, boolean | null> };

type OpenQuestions = {
    skipped_questions: string[];
    assumptions: string[];
    contradictions: string[];
};

type ShareLinkData = {
    id: string;
    url: string;
    approver_email: string;
    expires_at: string;
    active: boolean;
    approvals_count: number;
    doc_count: number;
};

type BaselineData = {
    id: string;
    number: number;
    hash: string;
    approver_email: string;
    approved_at: string;
};

type ChangeRequestData = {
    id: string;
    label: string;
    title: string;
    source: string;
    requested_by: string;
    status: string;
    delta_md: number | null;
    delta_cost: number | null;
    affected_doc_keys: string[] | null;
};

type RunData = {
    id: string;
    status: string;
    nodes: { doc_key: string; status: string }[];
};

type AssistantMsg = {
    id: string;
    role: string;
    body: string;
};

type Props = {
    project: { id: string; name: string; client_name: string | null; status: string; health_score: number | null };
    documents: Doc[];
    findings: Finding[];
    health_dimensions: string[];
    rtm: RtmRow[];
    open_questions: OpenQuestions;
    share_links: ShareLinkData[];
    baselines: BaselineData[];
    change_requests: ChangeRequestData[];
    run: RunData | null;
    missing_doc_keys: string[];
    assistant_messages: AssistantMsg[];
    chat_stream: string | null;
    chat_quota?: { used: number; limit: number | null; plan: string } | null;
    // FR-11(f): running dari lock job di backend; quota kuota bulanan per plan (limit null = unlimited)
    contradiction?: { running: boolean; quota: { used: number; limit: number | null } } | null;
    errors: Record<string, string>;
};

// diff baris LCS — ponytail: DP O(n·m), cukup untuk dokumen spec; ganti Myers kalau lemot
type DiffRow = { t: ' ' | '+' | '-' | '…'; s: string };
function lineDiff(a: string[], b: string[]): DiffRow[] {
    const n = a.length;
    const m = b.length;
    const w = m + 1;
    const dp = new Int32Array((n + 1) * w);
    for (let i = n - 1; i >= 0; i--)
        for (let j = m - 1; j >= 0; j--)
            dp[i * w + j] = a[i] === b[j] ? dp[(i + 1) * w + j + 1] + 1 : Math.max(dp[(i + 1) * w + j], dp[i * w + j + 1]);
    const rows: DiffRow[] = [];
    let i = 0;
    let j = 0;
    while (i < n && j < m) {
        if (a[i] === b[j]) {
            rows.push({ t: ' ', s: a[i] });
            i++;
            j++;
        } else if (dp[(i + 1) * w + j] >= dp[i * w + j + 1]) {
            rows.push({ t: '-', s: a[i] });
            i++;
        } else {
            rows.push({ t: '+', s: b[j] });
            j++;
        }
    }
    while (i < n) rows.push({ t: '-', s: a[i++] });
    while (j < m) rows.push({ t: '+', s: b[j++] });
    return rows;
}

// runtutan baris sama yang panjang diringkas jadi "··· N baris sama ···"
function collapseContext(rows: DiffRow[]): DiffRow[] {
    const out: DiffRow[] = [];
    let run: DiffRow[] = [];
    const flush = () => {
        if (run.length > 6) {
            out.push(...run.slice(0, 2), { t: '…', s: `··· ${run.length - 4} baris sama ···` }, ...run.slice(-2));
        } else {
            out.push(...run);
        }
        run = [];
    };
    for (const r of rows) {
        if (r.t === ' ') run.push(r);
        else {
            flush();
            out.push(r);
        }
    }
    flush();
    return out;
}

function healthColor(h: number | null) {
    if (h == null) return '#9CA3AF';
    if (h >= 85) return '#059669';
    if (h >= 70) return '#D97706';
    return '#DC2626';
}

function FindingRow({ f, onFix }: { f: Finding; onFix?: () => void }) {
    const color = f.severity === 'critical' ? '#B91C1C' : f.severity === 'warning' ? '#B45309' : '#4B5563';
    return (
        <div className="group/finding flex items-start gap-1.5 text-[11.5px] font-semibold" style={{ color }} title={f.suggestion ?? ''}>
            {f.severity === 'critical' ? (
                <svg
                    width="13"
                    height="13"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="#EF4444"
                    strokeWidth="2.4"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    className="mt-px flex-none"
                >
                    <line x1="18" y1="6" x2="6" y2="18" />
                    <line x1="6" y1="6" x2="18" y2="18" />
                </svg>
            ) : f.severity === 'warning' ? (
                <svg
                    width="13"
                    height="13"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="#F59E0B"
                    strokeWidth="2.4"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    className="mt-px flex-none"
                >
                    <path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z" />
                    <line x1="12" y1="9" x2="12" y2="13" />
                    <line x1="12" y1="17" x2="12.01" y2="17" />
                </svg>
            ) : (
                <svg
                    width="13"
                    height="13"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="#9CA3AF"
                    strokeWidth="2.4"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    className="mt-px flex-none"
                >
                    <circle cx="12" cy="12" r="10" />
                    <line x1="12" y1="16" x2="12" y2="12" />
                    <line x1="12" y1="8" x2="12.01" y2="8" />
                </svg>
            )}
            <span className="min-w-0">
                {f.location && <span className="font-mono">{f.location}</span>} {f.message}
                {onFix && (
                    <button
                        type="button"
                        className="ml-1.5 inline-flex items-center gap-1 rounded-md border border-teal-200 bg-teal-50 px-1.5 py-px text-[10.5px] font-bold text-teal-700 opacity-0 transition group-hover/finding:opacity-100 hover:bg-teal-100"
                        onClick={onFix}
                    >
                        ✦ Fix di chat
                    </button>
                )}
            </span>
        </div>
    );
}

export default function ProjectPage({
    project,
    documents,
    findings,
    health_dimensions = [],
    rtm = [],
    open_questions = { skipped_questions: [], assumptions: [], contradictions: [] },
    share_links = [],
    baselines = [],
    change_requests = [],
    run,
    missing_doc_keys = [],
    assistant_messages = [],
    chat_stream = null,
    chat_quota = null,
    contradiction = null,
    errors = {},
}: Props) {
    const [activeKey, setActiveKey] = useState(documents[0]?.doc_key ?? '');
    const [mode, setMode] = useState<'preview' | 'raw' | 'edit' | 'diff' | 'rtm'>('preview');
    const [draft, setDraft] = useState('');
    const [chatOpen, setChatOpen] = useState(false);
    const [chatPrefill, setChatPrefill] = useState<string | null>(null);
    // FR-09/FR-10: dialog "Usulkan perubahan" — analisa dampak lalu regenerate dokumen terpilih
    const [impactOpen, setImpactOpen] = useState(false);
    // modal pilih dokumen lanjutan — default tidak ada yang tercentang
    const [genModal, setGenModal] = useState(false);
    const [genSel, setGenSel] = useState<Set<string>>(new Set());
    const toggleGenSel = (k: string) =>
        setGenSel((prev) => {
            const next = new Set(prev);
            if (next.has(k)) next.delete(k);
            else next.add(k);
            return next;
        });
    // sibuk selama stream aktif atau pesan terakhir masih dari user (balasan belum tersimpan)
    const lastMsg = assistant_messages[assistant_messages.length - 1];
    const chatBusy = chat_stream != null || lastMsg?.role === 'user';
    const doc = documents.find((d) => d.doc_key === activeKey);

    // poll progres run generate (banner + daftar dokumen bertambah live)
    const runActive = run != null && ['queued', 'running'].includes(run.status);
    useEffect(() => {
        if (!runActive) return;
        const t = setInterval(() => router.reload({ only: ['run', 'documents', 'missing_doc_keys', 'project', 'findings'] }), 2500);
        return () => clearInterval(t);
    }, [runActive]);

    // FR-11(f): poll selama job cek kontradiksi jalan — selesai saat lock dilepas (running false)
    const contraRunning = contradiction?.running ?? false;
    useEffect(() => {
        if (!contraRunning) return;
        const t = setInterval(() => router.reload({ only: ['contradiction', 'findings', 'project'] }), 2500);
        return () => clearInterval(t);
    }, [contraRunning]);

    const html = useMemo(() => (doc?.content_md ? DOMPurify.sanitize(marked.parse(doc.content_md) as string) : ''), [doc?.content_md]);

    const health = project.health_score;
    const okFindings = findings.length === 0;
    // Breakdown Spec Health per dimensi (config health_dimensions); "Lainnya" hanya muncul bila ada rule tanpa mapping
    const dimensionGroups = [...health_dimensions, 'Lainnya']
        .map((name) => ({ name, items: findings.filter((f) => f.dimension === name) }))
        .filter((g) => g.name !== 'Lainnya' || g.items.length > 0);
    // Sidebar per grup dokumen (config doc_groups) — urutan grup mengikuti kemunculan pertama di pipeline
    const sidebarGroups = [...new Set(documents.map((d) => d.group))].map((name) => ({
        name,
        docs: documents.filter((d) => d.group === name),
    }));
    // Open questions tergroup — hanya grup berisi yang tampil
    const openQuestionGroups = [
        { name: 'Interview dilewati', items: open_questions.skipped_questions },
        { name: 'Asumsi belum dikonfirmasi', items: open_questions.assumptions },
        { name: 'Kontradiksi input', items: open_questions.contradictions },
    ].filter((g) => g.items.length > 0);
    const contraExhausted = contradiction?.quota.limit != null && contradiction.quota.used >= contradiction.quota.limit;

    const startEdit = () => {
        setDraft(doc?.content_md ?? '');
        setMode('edit');
    };

    const saveEdit = () => {
        if (!doc) return;
        router.post(route('documents.versions.store', doc.id), { content_md: draft }, { onSuccess: () => setMode('preview') });
    };

    const tabs: { key: 'preview' | 'raw'; label: string }[] = [
        { key: 'preview', label: 'Rich Preview' },
        { key: 'raw', label: 'Raw Markdown' },
    ];

    // ---- diff antar versi (FR-08) ----
    const [diffBase, setDiffBase] = useState<number | null>(null);
    const [diffOldText, setDiffOldText] = useState<string | null>(null);
    const baseNo = diffBase ?? Math.max((doc?.version_no ?? 2) - 1, 1);
    useEffect(() => setDiffBase(null), [activeKey]);
    useEffect(() => {
        if (mode !== 'diff' || !doc) return;
        setDiffOldText(null);
        fetch(route('documents.versions.show', [doc.id, baseNo]), { headers: { Accept: 'application/json' } })
            .then((r) => r.json())
            .then((d) => setDiffOldText(d.content_md ?? ''));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [mode, doc?.id, baseNo]);
    const diffRows = useMemo(
        () =>
            mode === 'diff' && doc?.content_md != null && diffOldText != null
                ? collapseContext(lineDiff(diffOldText.split('\n'), doc.content_md.split('\n')))
                : null,
        [mode, diffOldText, doc?.content_md],
    );

    return (
        <SpektaLayout crumb={project.name} active="projects">
            <Head title={`${project.name} — Dokumen`} />

            <div className="mb-[18px] flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 className="group flex items-center gap-2 text-[22px] font-extrabold tracking-[-0.02em] text-gray-900">
                        {project.name}
                        <button
                            type="button"
                            title="Ubah nama proyek"
                            className="text-gray-300 opacity-0 transition group-hover:opacity-100 hover:text-teal-700"
                            onClick={async () => {
                                const name = await promptDialog('Nama proyek:', project.name);
                                if (name?.trim() && name.trim() !== project.name)
                                    router.patch(route('projects.update', project.id), { name: name.trim() }, { preserveScroll: true });
                            }}
                        >
                            <svg
                                width="15"
                                height="15"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                strokeWidth="2.2"
                                strokeLinecap="round"
                                strokeLinejoin="round"
                            >
                                <path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z" />
                            </svg>
                        </button>
                    </h1>
                    <div className="group/client mt-1 flex items-center gap-1.5 text-[13px] font-medium text-gray-500">
                        <span>
                            Klien: {project.client_name ?? '—'} · {documents.length} dokumen ·{' '}
                            <span className="font-mono font-semibold">v{doc?.version_no ?? 1}</span>
                        </span>
                        <button
                            type="button"
                            title="Ubah nama klien"
                            className="text-gray-300 opacity-0 transition group-hover/client:opacity-100 hover:text-teal-700"
                            onClick={async () => {
                                const client = await promptDialog('Nama klien (kosongkan untuk hapus):', project.client_name ?? '');
                                if (client !== null && client.trim() !== (project.client_name ?? ''))
                                    router.patch(
                                        route('projects.update', project.id),
                                        { name: project.name, client_name: client.trim() || null },
                                        { preserveScroll: true },
                                    );
                            }}
                        >
                            <svg
                                width="13"
                                height="13"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                strokeWidth="2.2"
                                strokeLinecap="round"
                                strokeLinejoin="round"
                            >
                                <path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z" />
                            </svg>
                        </button>
                    </div>
                </div>
                <div className="flex flex-wrap items-center gap-2.5">
                    <Link
                        href={route('projects.structure', project.id)}
                        className="inline-flex items-center gap-1.5 rounded-[10px] border border-gray-200 bg-white px-4 py-2 text-[13px] font-bold text-gray-700 hover:bg-gray-50"
                    >
                        <svg
                            width="15"
                            height="15"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            strokeWidth="2.2"
                            strokeLinecap="round"
                            strokeLinejoin="round"
                        >
                            <rect x="3" y="3" width="7" height="7" rx="1" />
                            <rect x="14" y="14" width="7" height="7" rx="1" />
                            <path d="M10 6.5h4a2 2 0 0 1 2 2V14" />
                            <path d="M14 17.5h-4a2 2 0 0 1-2-2V10" />
                        </svg>
                        Struktur
                    </Link>
                    <Link
                        href={route('projects.wireframes', project.id)}
                        className="inline-flex items-center gap-1.5 rounded-[10px] border border-gray-200 bg-white px-4 py-2 text-[13px] font-bold text-gray-700 hover:bg-gray-50"
                    >
                        <svg
                            width="15"
                            height="15"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            strokeWidth="2.2"
                            strokeLinecap="round"
                            strokeLinejoin="round"
                        >
                            <rect x="3" y="3" width="18" height="18" rx="2" />
                            <line x1="3" y1="9" x2="21" y2="9" />
                            <line x1="9" y1="21" x2="9" y2="9" />
                        </svg>
                        Wireframe
                    </Link>
                    <Link
                        href={route('projects.stack', project.id)}
                        className="inline-flex items-center gap-1.5 rounded-[10px] border border-gray-200 bg-white px-4 py-2 text-[13px] font-bold text-gray-700 hover:bg-gray-50"
                    >
                        <svg
                            width="15"
                            height="15"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            strokeWidth="2.2"
                            strokeLinecap="round"
                            strokeLinejoin="round"
                        >
                            <ellipse cx="12" cy="5" rx="9" ry="3" />
                            <path d="M3 5v14a9 3 0 0 0 18 0V5" />
                            <path d="M3 12a9 3 0 0 0 18 0" />
                        </svg>
                        Stack
                    </Link>
                    <Link
                        href={route('projects.estimate', project.id)}
                        className="inline-flex items-center gap-1.5 rounded-[10px] border border-gray-200 bg-white px-4 py-2 text-[13px] font-bold text-gray-700 hover:bg-gray-50"
                    >
                        Estimasi &amp; RAB
                    </Link>
                    <button
                        type="button"
                        className="inline-flex items-center gap-1.5 rounded-[10px] border border-gray-200 bg-white px-4 py-2 text-[13px] font-bold text-gray-700 hover:bg-gray-50"
                        onClick={() => setImpactOpen(true)}
                    >
                        Usulkan perubahan
                    </button>
                    <button
                        className="inline-flex items-center gap-1.5 rounded-[10px] border-2 border-teal-600 bg-white px-4 py-2 text-[13px] font-bold text-teal-800 hover:bg-teal-50"
                        onClick={async () => {
                            // ponytail: prompt-flow, form modal nanti kalau perlu
                            const email = await promptDialog('Email approver utama klien (BR-27):');
                            if (!email) return;
                            if (!(await confirmDialog('Internal review selesai? (BR-30 — wajib sebelum share)'))) return;
                            router.post(
                                route('projects.share', project.id),
                                { approver_email: email, doc_keys: documents.map((d) => d.doc_key), internal_review_done: true },
                                { preserveScroll: true },
                            );
                        }}
                    >
                        <svg
                            width="15"
                            height="15"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            strokeWidth="2.2"
                            strokeLinecap="round"
                            strokeLinejoin="round"
                        >
                            <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z" />
                            <circle cx="12" cy="12" r="3" />
                        </svg>
                        Share ke klien
                    </button>
                </div>
            </div>

            {run && run.status !== 'done' && (
                <div className="mb-3.5 rounded-[10px] border border-amber-200 bg-amber-50/70 px-3.5 py-2.5 text-xs">
                    <div className="flex flex-wrap items-center gap-3">
                        <span className="font-bold text-amber-800">
                            {run.status === 'paused' ? 'Generate terhenti' : 'Sedang generate'} —{' '}
                            {run.nodes.filter((n) => n.status === 'done').length}/{run.nodes.length} dokumen
                        </span>
                        <span className="min-w-0 truncate font-mono text-amber-700">
                            {(() => {
                                const writing = run.nodes.find((n) => n.status === 'running');
                                return writing
                                    ? `menulis ${writing.doc_key}.md…`
                                    : run.nodes
                                          .filter((n) => n.status !== 'done')
                                          .map((n) => n.doc_key)
                                          .join(' · ');
                            })()}
                        </span>
                        <span className="ml-auto flex gap-3">
                            <Link href={route('projects.wizard', project.id)} className="font-bold text-teal-700 hover:text-teal-900">
                                Lihat progres live →
                            </Link>
                            {run.status === 'paused' && (
                                <button
                                    className="font-bold text-amber-800 hover:text-amber-950"
                                    onClick={() => router.post(route('projects.generate.resume', project.id), {}, { preserveScroll: true })}
                                >
                                    Lanjutkan (node gagal saja)
                                </button>
                            )}
                        </span>
                    </div>
                    <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-amber-200/60">
                        <div
                            className="h-full rounded-full bg-teal-600 transition-all duration-500"
                            style={{ width: `${(run.nodes.filter((n) => n.status === 'done').length / Math.max(run.nodes.length, 1)) * 100}%` }}
                        />
                    </div>
                </div>
            )}

            {(share_links.length > 0 || baselines.length > 0) && (
                <div className="mb-3.5 flex flex-col gap-1.5">
                    {share_links.map((l) => (
                        <div
                            key={l.id}
                            className={`flex flex-wrap items-center gap-2.5 rounded-[10px] border px-3.5 py-2 text-xs ${l.active ? 'border-teal-200 bg-teal-50/60' : 'border-gray-200 bg-gray-50 opacity-60'}`}
                        >
                            <span className="font-bold text-teal-800">Portal klien</span>
                            <span className="min-w-0 truncate font-mono text-gray-600">{l.url}</span>
                            <span className="text-gray-400">
                                approver {l.approver_email} · {l.approvals_count}/{l.doc_count} disetujui · s/d {l.expires_at}
                            </span>
                            <span className="ml-auto flex gap-2">
                                {l.active && (
                                    <>
                                        <button
                                            className="font-bold text-teal-700 hover:text-teal-900"
                                            onClick={() => navigator.clipboard.writeText(l.url)}
                                        >
                                            Salin
                                        </button>
                                        <button
                                            className="font-bold text-gray-400 hover:text-red-600"
                                            onClick={async () =>
                                                (await confirmDialog('Cabut link? Komentar & approval tetap tersimpan (BR-28).')) &&
                                                router.delete(route('projects.share.revoke', [project.id, l.id]), { preserveScroll: true })
                                            }
                                        >
                                            Cabut
                                        </button>
                                    </>
                                )}
                                {!l.active && <span className="text-gray-400">dicabut / kedaluwarsa</span>}
                            </span>
                        </div>
                    ))}
                    {baselines.map((b) => (
                        <div
                            key={b.id}
                            className="flex flex-wrap items-center gap-2.5 rounded-[10px] border border-emerald-200 bg-emerald-50/60 px-3.5 py-2 text-xs"
                        >
                            <span className="font-bold text-emerald-800">Baseline v{b.number}</span>
                            <span className="font-mono text-gray-500">#{b.hash}</span>
                            <span className="text-gray-400">
                                disetujui {b.approver_email} · {b.approved_at} · immutable (BR-24)
                            </span>
                        </div>
                    ))}
                    {change_requests.map((cr) => (
                        <div
                            key={cr.id}
                            className={`flex flex-wrap items-center gap-2.5 rounded-[10px] border px-3.5 py-2 text-xs ${
                                cr.status === 'approved'
                                    ? 'border-emerald-200 bg-emerald-50/60'
                                    : cr.status === 'rejected'
                                      ? 'border-gray-200 bg-gray-50 opacity-60'
                                      : 'border-amber-200 bg-amber-50/60'
                            }`}
                        >
                            <span className="font-mono font-bold text-gray-800">{cr.label}</span>
                            <span className="font-semibold text-gray-700">{cr.title}</span>
                            <span className="text-gray-400">
                                {cr.source === 'client' ? 'dari klien' : 'internal'} · {cr.requested_by}
                                {cr.delta_md !== null && (
                                    <>
                                        {' '}
                                        · Δ <span className="font-mono">{cr.delta_md} MD</span> ·{' '}
                                        <span className="font-mono">Rp {Math.round((cr.delta_cost ?? 0) / 1e6)} jt</span>
                                    </>
                                )}
                            </span>
                            <span className="ml-auto flex items-center gap-2">
                                <span
                                    className={`rounded-full px-2 py-0.5 text-[10px] font-extrabold uppercase ${
                                        cr.status === 'approved'
                                            ? 'bg-emerald-100 text-emerald-800'
                                            : cr.status === 'rejected'
                                              ? 'bg-gray-200 text-gray-500'
                                              : 'bg-amber-100 text-amber-800'
                                    }`}
                                >
                                    {cr.status}
                                </span>
                                {cr.status === 'proposed' && (
                                    <>
                                        <button
                                            className="font-bold text-teal-700 hover:text-teal-900"
                                            onClick={async () => {
                                                const md = await promptDialog(
                                                    `Impact review ${cr.label} — delta MD (± angka):`,
                                                    String(cr.delta_md ?? ''),
                                                );
                                                if (md === null || isNaN(Number(md))) return;
                                                const docs = await promptDialog(
                                                    'Dokumen terdampak (pisah koma):',
                                                    (cr.affected_doc_keys ?? []).join(',') || 'PRD,REQUIREMENTS',
                                                );
                                                if (!docs) return;
                                                router.patch(
                                                    route('projects.cr.update', [project.id, cr.id]),
                                                    {
                                                        delta_md: Number(md),
                                                        affected_doc_keys: docs
                                                            .split(',')
                                                            .map((s) => s.trim())
                                                            .filter(Boolean),
                                                    },
                                                    { preserveScroll: true },
                                                );
                                            }}
                                        >
                                            Isi impact
                                        </button>
                                        <button
                                            className="font-bold text-teal-700 hover:text-teal-900"
                                            onClick={() =>
                                                router.post(route('projects.cr.impact-ai', [project.id, cr.id]), {}, { preserveScroll: true })
                                            }
                                        >
                                            ✦ Impact AI
                                        </button>
                                        <button
                                            className="font-bold text-gray-400 hover:text-red-600"
                                            onClick={() =>
                                                router.post(route('projects.cr.reject', [project.id, cr.id]), {}, { preserveScroll: true })
                                            }
                                        >
                                            Tolak
                                        </button>
                                    </>
                                )}
                            </span>
                        </div>
                    ))}
                    {project.status === 'approved' && (
                        <button
                            className="self-start rounded-[10px] border border-dashed border-gray-300 px-3.5 py-2 text-xs font-bold text-gray-400 hover:border-amber-400 hover:text-amber-700"
                            onClick={async () => {
                                const title = await promptDialog('Judul Change Request:');
                                if (!title) return;
                                const md = await promptDialog('Delta MD (boleh kosong, isi saat impact review):');
                                router.post(
                                    route('projects.cr.store', project.id),
                                    {
                                        title,
                                        delta_md: md && !isNaN(Number(md)) ? Number(md) : null,
                                    },
                                    { preserveScroll: true },
                                );
                            }}
                        >
                            + Change Request (BR-25)
                        </button>
                    )}
                </div>
            )}

            <div className="overflow-x-auto">
                <div className="flex min-h-[560px] min-w-[1000px] overflow-hidden rounded-xl border border-gray-200 bg-white">
                    {/* pane kiri: daftar dokumen */}
                    <div className="flex w-[228px] flex-none flex-col border-r border-gray-200 bg-gray-50 p-3.5">
                        <div className="mb-2 flex items-center justify-between">
                            <span className="text-[11px] font-bold tracking-[0.08em] text-gray-500">DOKUMEN</span>
                            <span className="rounded-full border border-gray-200 bg-white px-2 py-px font-mono text-[10px] font-bold text-gray-500">
                                {documents.length} file
                            </span>
                        </div>
                        <div className="flex flex-1 flex-col gap-px overflow-auto">
                            {sidebarGroups.map((g) => (
                                <div key={g.name} className="flex flex-col gap-px">
                                    <div className="mt-2.5 mb-0.5 px-2 text-[9.5px] font-bold tracking-[0.1em] text-gray-400 uppercase first:mt-0">
                                        {g.name}
                                    </div>
                                    {g.docs.map((d) => {
                                        const activeDoc = d.doc_key === activeKey;
                                        const edited = d.versions.some((v) => v.source === 'user');
                                        const warn = findings.some((f) => f.location?.includes(d.doc_key));
                                        return (
                                            <button
                                                key={d.doc_key}
                                                onClick={() => {
                                                    setActiveKey(d.doc_key);
                                                    setMode('preview');
                                                }}
                                                className={`flex items-center gap-[7px] rounded-[7px] px-2 py-1.5 text-left text-[12.5px] ${
                                                    activeDoc ? 'bg-teal-50 font-bold text-teal-800' : 'font-medium text-gray-600 hover:bg-teal-50/60'
                                                }`}
                                            >
                                                <svg
                                                    width="13"
                                                    height="13"
                                                    viewBox="0 0 24 24"
                                                    fill="none"
                                                    stroke="currentColor"
                                                    strokeWidth="2.2"
                                                    strokeLinecap="round"
                                                    strokeLinejoin="round"
                                                    className="flex-none opacity-60"
                                                >
                                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                                                    <polyline points="14 2 14 8 20 8" />
                                                </svg>
                                                <span className="truncate">
                                                    <span className="font-mono text-gray-400">{String(d.seq).padStart(2, '0')}_</span>
                                                    {d.doc_key}.md
                                                </span>
                                                {edited && (
                                                    <span className="ml-auto rounded-[5px] bg-red-100 px-1.5 py-0.5 text-[8.5px] font-extrabold tracking-wide text-red-800">
                                                        EDITED
                                                    </span>
                                                )}
                                                {!edited && d.stale && (
                                                    <span
                                                        className="ml-auto rounded-[5px] bg-amber-100 px-1.5 py-0.5 text-[8.5px] font-extrabold tracking-wide text-amber-800"
                                                        title="Dokumen upstream punya versi lebih baru — pertimbangkan regenerate"
                                                    >
                                                        STALE
                                                    </span>
                                                )}
                                                {!edited && !d.stale && warn && (
                                                    <svg
                                                        width="12"
                                                        height="12"
                                                        viewBox="0 0 24 24"
                                                        fill="none"
                                                        stroke="#F59E0B"
                                                        strokeWidth="2.4"
                                                        strokeLinecap="round"
                                                        strokeLinejoin="round"
                                                        className="ml-auto flex-none"
                                                    >
                                                        <path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z" />
                                                        <line x1="12" y1="9" x2="12" y2="13" />
                                                        <line x1="12" y1="17" x2="12.01" y2="17" />
                                                    </svg>
                                                )}
                                            </button>
                                        );
                                    })}
                                </div>
                            ))}
                        </div>
                        {missing_doc_keys.length > 0 && !(run && run.status !== 'done') && (
                            <button
                                onClick={() => {
                                    setGenSel(new Set());
                                    setGenModal(true);
                                }}
                                className="mt-2.5 flex w-full items-center justify-center gap-1.5 rounded-[10px] border-2 border-dashed border-teal-300 bg-teal-50 py-2 text-xs font-bold text-teal-800 hover:bg-teal-100"
                                title={missing_doc_keys.join(', ')}
                            >
                                ✦ Generate dokumen lanjutan
                            </button>
                        )}
                        {errors.credits && <div className="mt-1.5 text-[11px] font-semibold text-red-600">{errors.credits}</div>}
                        <div className="mt-2.5 border-t border-gray-200 pt-3">
                            <a
                                href={route('projects.export', [project.id, 'zip'])}
                                className="flex w-full items-center justify-center gap-[7px] rounded-[10px] bg-teal-600 py-2 text-xs font-bold text-white hover:bg-teal-700"
                            >
                                <svg
                                    width="13"
                                    height="13"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    strokeWidth="2.2"
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                >
                                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                                    <polyline points="7 10 12 15 17 10" />
                                    <line x1="12" y1="15" x2="12" y2="3" />
                                </svg>
                                Export ZIP
                            </a>
                            <div className="mt-1.5 text-center font-mono text-[10px] leading-normal font-semibold text-gray-400">
                                Markdown lengkap · semua dokumen
                            </div>
                            <a
                                href={route('projects.export', [project.id, 'agent_pack'])}
                                className="mt-2 flex w-full items-center justify-center rounded-[10px] border border-gray-200 bg-white py-2 text-xs font-bold text-gray-700 hover:bg-gray-100"
                            >
                                Agent pack
                            </a>
                            <div className="mt-1.5 text-center font-mono text-[10px] leading-normal font-semibold text-gray-400">
                                CLAUDE.md · .cursorrules · AGENTS.md
                            </div>
                            <a
                                href={route('projects.export', [project.id, 'pdf'])}
                                className="mt-2 flex w-full items-center justify-center rounded-[10px] border border-gray-200 bg-white py-2 text-xs font-bold text-gray-700 hover:bg-gray-100"
                            >
                                Export PDF
                            </a>
                            <div className="mt-1.5 text-center font-mono text-[10px] leading-normal font-semibold text-gray-400">
                                Satu PDF · semua dokumen
                            </div>
                        </div>
                    </div>

                    {/* pane tengah: konten */}
                    <div className="min-w-0 flex-1 px-5 py-4">
                        <div className="mb-3.5 flex gap-1.5">
                            {tabs.map((t) => (
                                <button
                                    key={t.key}
                                    onClick={() => setMode(t.key)}
                                    className={`rounded-[10px] border-2 px-3.5 py-[7px] text-[12.5px] font-bold ${
                                        mode === t.key
                                            ? 'border-teal-600 bg-teal-50 text-teal-800'
                                            : 'border-gray-200 bg-white text-gray-500 hover:border-teal-300'
                                    }`}
                                >
                                    {t.label}
                                </button>
                            ))}
                            {doc && doc.versions.length > 1 && (
                                <button
                                    onClick={() => setMode('diff')}
                                    className={`rounded-[10px] border-2 px-3.5 py-[7px] text-[12.5px] font-bold ${
                                        mode === 'diff'
                                            ? 'border-teal-600 bg-teal-50 text-teal-800'
                                            : 'border-gray-200 bg-white text-gray-500 hover:border-teal-300'
                                    }`}
                                >
                                    Diff v{baseNo} → v{doc.version_no}
                                </button>
                            )}
                            <button
                                onClick={startEdit}
                                className={`rounded-[10px] border-2 px-3.5 py-[7px] text-[12.5px] font-bold ${
                                    mode === 'edit'
                                        ? 'border-teal-600 bg-teal-50 text-teal-800'
                                        : 'border-gray-200 bg-white text-gray-500 hover:border-teal-300'
                                }`}
                            >
                                Edit
                            </button>
                            {rtm.length > 0 && (
                                <button
                                    onClick={() => setMode('rtm')}
                                    className={`rounded-[10px] border-2 px-3.5 py-[7px] text-[12.5px] font-bold ${
                                        mode === 'rtm'
                                            ? 'border-teal-600 bg-teal-50 text-teal-800'
                                            : 'border-gray-200 bg-white text-gray-500 hover:border-teal-300'
                                    }`}
                                >
                                    Traceability
                                </button>
                            )}

                            {doc?.generated_meta && (
                                <span
                                    className="ml-auto self-center rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-extrabold text-amber-700"
                                    title={`model: ${doc.generated_meta.model}`}
                                >
                                    ✦ AI
                                </span>
                            )}
                        </div>

                        <div className="text-lg font-extrabold text-gray-900">
                            {mode === 'rtm' ? 'Requirement Traceability Matrix' : (doc?.title ?? doc?.doc_key)}
                        </div>
                        {mode === 'rtm' && (
                            <div className="mt-0.5 text-xs font-medium text-gray-400">
                                FR dari PRD × keterlacakan di dokumen turunan · ✓ disebut · ✗ belum · — dokumen belum ada
                            </div>
                        )}
                        {mode !== 'rtm' && (
                            <div className="mt-0.5 font-mono text-xs font-medium text-gray-400">
                                {doc?.doc_key}.md · v{doc?.version_no ?? 1} · {doc?.versions[doc.versions.length - 1]?.created_at ?? ''}
                            </div>
                        )}
                        {mode !== 'rtm' && doc && (doc.upstream.length > 0 || doc.downstream.length > 0) && (
                            <div className="mt-1 text-[11px] font-semibold text-gray-400">
                                {doc.upstream.length > 0 && (
                                    <span>
                                        Diturunkan dari:{' '}
                                        <span className="font-mono text-gray-500">
                                            {doc.upstream.map((u) => `${u.doc_key} v${u.version_no ?? 1}`).join(', ')}
                                        </span>
                                    </span>
                                )}
                                {doc.upstream.length > 0 && doc.downstream.length > 0 && ' · '}
                                {doc.downstream.length > 0 && (
                                    <span>
                                        Mempengaruhi: <span className="font-mono text-gray-500">{doc.downstream.join(', ')}</span>
                                    </span>
                                )}
                            </div>
                        )}
                        {mode !== 'rtm' && doc?.stale && (
                            <div className="mt-1 text-[11px] font-bold text-amber-600">
                                ⚠ Dokumen upstream berubah setelah versi ini dibuat — pertimbangkan regenerate via "Usulkan perubahan".
                            </div>
                        )}

                        <div className="mt-3.5 overflow-auto" style={{ maxHeight: 'calc(100vh - 330px)' }}>
                            {mode === 'preview' && (
                                <MarkdownPreview
                                    html={html}
                                    className="prose prose-sm prose-headings:font-extrabold prose-headings:tracking-tight max-w-none"
                                />
                            )}
                            {mode === 'raw' && (
                                <pre className="rounded-[10px] border border-gray-100 bg-gray-50 p-4 font-mono text-xs leading-[1.7] font-medium whitespace-pre-wrap text-gray-700">
                                    {doc?.content_md}
                                </pre>
                            )}
                            {mode === 'rtm' && (
                                <div className="overflow-x-auto">
                                    <table className="w-full min-w-[560px] border-collapse text-[12px]">
                                        <thead>
                                            <tr className="border-b-2 border-gray-200 text-left text-[10.5px] font-bold tracking-[0.06em] text-gray-500">
                                                <th className="py-2 pr-3">FR</th>
                                                <th className="px-2 py-2">SCOPE</th>
                                                {Object.keys(rtm[0]?.cells ?? {}).map((k) => (
                                                    <th key={k} className="px-2 py-2 text-center font-mono">
                                                        {k}
                                                    </th>
                                                ))}
                                                <th className="py-2 pl-2">STATUS</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {rtm.map((r) => {
                                                const vals = Object.values(r.cells);
                                                const complete = vals.every((v) => v !== false);
                                                return (
                                                    <tr key={r.fr} className="border-b border-gray-100">
                                                        <td className="py-2 pr-3 font-mono font-bold text-gray-800">{r.fr}</td>
                                                        <td className="px-2 py-2 font-semibold text-gray-500">{r.scope ?? '—'}</td>
                                                        {vals.map((v, i) => (
                                                            <td key={i} className="px-2 py-2 text-center font-bold">
                                                                {v == null ? (
                                                                    <span className="text-gray-300">—</span>
                                                                ) : v ? (
                                                                    <span className="text-emerald-600">✓</span>
                                                                ) : (
                                                                    <span className="text-red-500">✗</span>
                                                                )}
                                                            </td>
                                                        ))}
                                                        <td className="py-2 pl-2">
                                                            <span
                                                                className={`rounded-[5px] px-1.5 py-0.5 text-[9.5px] font-extrabold tracking-wide ${
                                                                    complete ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'
                                                                }`}
                                                            >
                                                                {complete ? 'LENGKAP' : 'KURANG'}
                                                            </span>
                                                        </td>
                                                    </tr>
                                                );
                                            })}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                            {mode === 'diff' && doc && (
                                <div>
                                    <div className="mb-2.5 flex items-center gap-2 text-[12px] font-semibold text-gray-500">
                                        Bandingkan
                                        <select
                                            className="rounded-lg border border-gray-200 bg-white px-2 py-1 font-mono text-[11.5px] font-semibold text-gray-700"
                                            value={baseNo}
                                            onChange={(e) => setDiffBase(Number(e.target.value))}
                                        >
                                            {doc.versions
                                                .filter((v) => v.version_no !== doc.version_no)
                                                .map((v) => (
                                                    <option key={v.id} value={v.version_no}>
                                                        v{v.version_no} · {v.source}
                                                    </option>
                                                ))}
                                        </select>
                                        → <span className="font-mono font-bold text-gray-700">v{doc.version_no} (saat ini)</span>
                                    </div>
                                    {diffRows == null ? (
                                        <div className="text-[12.5px] font-medium text-gray-400">Memuat diff…</div>
                                    ) : (
                                        <div className="overflow-hidden rounded-[10px] border border-gray-100 font-mono text-xs leading-[1.9] font-medium">
                                            {diffRows.map((r, idx) =>
                                                r.t === '…' ? (
                                                    <div key={idx} className="bg-white px-4 py-0.5 text-center text-gray-300">
                                                        {r.s}
                                                    </div>
                                                ) : (
                                                    <div
                                                        key={idx}
                                                        className={`px-4 whitespace-pre-wrap ${
                                                            r.t === '+'
                                                                ? 'bg-green-50 text-green-800'
                                                                : r.t === '-'
                                                                  ? 'bg-red-50 text-red-800'
                                                                  : 'bg-gray-50 text-gray-500'
                                                        }`}
                                                    >
                                                        {r.t === ' ' ? '  ' : r.t + ' '}
                                                        {r.s}
                                                    </div>
                                                ),
                                            )}
                                        </div>
                                    )}
                                </div>
                            )}
                            {mode === 'edit' && (
                                <div>
                                    <textarea
                                        className="min-h-[400px] w-full rounded-lg border border-gray-300 p-4 font-mono text-[12.5px] focus:border-teal-500 focus:outline-none"
                                        value={draft}
                                        onChange={(e) => setDraft(e.target.value)}
                                    />
                                    <div className="mt-3 flex justify-end gap-2">
                                        <button
                                            className="rounded-lg px-4 py-2 text-[13px] font-bold text-gray-500 hover:bg-gray-100"
                                            onClick={() => setMode('preview')}
                                        >
                                            Batal
                                        </button>
                                        <button
                                            className="rounded-lg bg-teal-600 px-4 py-2 text-[13px] font-bold text-white hover:bg-teal-700"
                                            onClick={saveEdit}
                                        >
                                            Simpan sebagai v{(doc?.version_no ?? 0) + 1}
                                        </button>
                                    </div>
                                </div>
                            )}
                        </div>

                        {doc && doc.versions.length > 1 && mode !== 'edit' && (
                            <div className="mt-3 flex flex-wrap items-center gap-2 border-t border-gray-100 pt-2 text-[11px] text-gray-400">
                                Riwayat:
                                {doc.versions.map((v) => (
                                    <span
                                        key={v.id}
                                        className={`group inline-flex items-center gap-1 rounded-full border px-2 py-0.5 font-mono ${v.source === 'user' ? 'border-blue-200 text-blue-600' : 'border-amber-200 text-amber-600'}`}
                                    >
                                        v{v.version_no} · {v.source}
                                        {v.version_no !== doc.version_no && (
                                            <button
                                                title={`Pulihkan v${v.version_no} (disalin jadi v${(doc.version_no ?? 0) + 1})`}
                                                className="hidden text-gray-400 group-hover:inline hover:text-teal-700"
                                                onClick={async () => {
                                                    if (
                                                        !(await confirmDialog(
                                                            `Pulihkan v${v.version_no}? Isi lama disalin jadi versi baru v${(doc.version_no ?? 0) + 1}.`,
                                                        ))
                                                    )
                                                        return;
                                                    router.post(
                                                        route('documents.versions.restore', [doc.id, v.version_no]),
                                                        {},
                                                        { preserveScroll: true, only: ['documents', 'project', 'findings', 'errors'] },
                                                    );
                                                }}
                                            >
                                                <svg
                                                    width="11"
                                                    height="11"
                                                    viewBox="0 0 24 24"
                                                    fill="none"
                                                    stroke="currentColor"
                                                    strokeWidth="2.4"
                                                    strokeLinecap="round"
                                                    strokeLinejoin="round"
                                                >
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

                    {/* pane kanan: spec health */}
                    <div className="flex w-[290px] flex-none flex-col border-l border-gray-200 bg-gray-50 p-4">
                        <div className="text-[11px] font-bold tracking-[0.08em] text-gray-500">SPEC HEALTH</div>
                        <div className="mt-2.5 flex items-center gap-3.5">
                            <div
                                className="flex h-[72px] w-[72px] flex-none items-center justify-center rounded-full"
                                style={{ background: `conic-gradient(${healthColor(health)} ${health ?? 0}%, #E5E7EB 0)` }}
                            >
                                <div className="flex h-14 w-14 items-center justify-center rounded-full bg-gray-50 font-mono text-[19px] font-extrabold text-gray-900">
                                    {health ?? '—'}
                                </div>
                            </div>
                            <div className="min-w-0 text-[11.5px] font-semibold text-gray-600">
                                {okFindings ? 'Spec konsisten — tidak ada temuan.' : `${findings.length} temuan lintas dokumen`}
                            </div>
                        </div>
                        <div className="mt-2.5 flex flex-col gap-1">
                            {dimensionGroups.map((g) => (
                                <div key={g.name} className="flex items-center justify-between text-[11.5px] font-semibold text-gray-600">
                                    <span>{g.name}</span>
                                    {g.items.length === 0 ? (
                                        <svg
                                            width="12"
                                            height="12"
                                            viewBox="0 0 24 24"
                                            fill="none"
                                            stroke="#16A34A"
                                            strokeWidth="2.8"
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                        >
                                            <polyline points="20 6 9 17 4 12" />
                                        </svg>
                                    ) : (
                                        <span
                                            className="font-mono text-[11px] font-extrabold"
                                            style={{
                                                color: g.items.some((f) => f.severity === 'critical')
                                                    ? '#DC2626'
                                                    : g.items.some((f) => f.severity === 'warning')
                                                      ? '#D97706'
                                                      : '#4B5563',
                                            }}
                                        >
                                            {g.items.length}
                                        </span>
                                    )}
                                </div>
                            ))}
                        </div>
                        <button
                            type="button"
                            disabled={contraRunning || contraExhausted}
                            className="mt-2.5 w-full rounded-lg border border-teal-200 bg-teal-50 px-3 py-2 text-[12px] font-bold text-teal-700 hover:bg-teal-100 disabled:cursor-not-allowed disabled:opacity-60"
                            onClick={() => router.post(route('projects.health.contradictions', project.id), {}, { preserveScroll: true })}
                        >
                            {contraRunning ? 'Memeriksa kontradiksi…' : 'Cek kontradiksi'}
                        </button>
                        {contradiction?.quota.limit != null && (
                            <div className={`mt-1 text-center text-[10.5px] font-semibold ${contraExhausted ? 'text-amber-600' : 'text-gray-400'}`}>
                                {contraExhausted
                                    ? 'Kuota cek bulan ini habis — upgrade paket'
                                    : `Sisa kuota bulan ini: ${contradiction.quota.limit - contradiction.quota.used}/${contradiction.quota.limit}`}
                            </div>
                        )}
                        {errors.contradiction && <div className="mt-1 text-[10.5px] font-semibold text-red-600">{errors.contradiction}</div>}
                        <div className="mt-3.5 flex max-h-[180px] flex-col gap-2 overflow-auto border-t border-gray-200 pt-3.5">
                            {okFindings && (
                                <div className="flex items-start gap-1.5 text-[11.5px] font-semibold text-gray-600">
                                    <svg
                                        width="13"
                                        height="13"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="#16A34A"
                                        strokeWidth="2.4"
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        className="mt-px flex-none"
                                    >
                                        <polyline points="20 6 9 17 4 12" />
                                    </svg>
                                    Semua FR tertelusuri di REQUIREMENTS, ROADMAP &amp; TESTING
                                </div>
                            )}
                            {dimensionGroups
                                .filter((g) => g.items.length > 0)
                                .map((g) => (
                                    <div key={g.name} className="flex flex-col gap-2">
                                        <div className="text-[10px] font-bold tracking-[0.08em] text-gray-400 uppercase">{g.name}</div>
                                        {g.items.map((f) => (
                                            <FindingRow
                                                key={f.id}
                                                f={f}
                                                onFix={() => {
                                                    // Sebut nama dokumen di pesan → isinya ikut masuk konteks AI
                                                    const docName = f.location?.split(' / ')[0] ?? activeKey;
                                                    setChatPrefill(
                                                        `Perbaiki temuan spec health di ${docName}.md: ${f.message}.` +
                                                            (f.suggestion ? ` ${f.suggestion}` : ''),
                                                    );
                                                    setChatOpen(true);
                                                }}
                                            />
                                        ))}
                                    </div>
                                ))}
                        </div>

                        {!okFindings && findings.length > 1 && (
                            <button
                                type="button"
                                className="mt-3 w-full rounded-lg border border-teal-200 bg-teal-50 px-3 py-2 text-[12px] font-bold text-teal-700 hover:bg-teal-100"
                                onClick={() => {
                                    const docs = [...new Set(findings.map((f) => f.location?.split(' / ')[0] ?? activeKey))];
                                    const list = findings
                                        .map(
                                            (f, i) =>
                                                `${i + 1}. ${f.location?.split(' / ')[0] ?? activeKey}.md — ${f.message}.${f.suggestion ? ` ${f.suggestion}` : ''}`,
                                        )
                                        .join('\n');
                                    setChatPrefill(
                                        `Perbaiki SEMUA temuan spec health berikut (revisi ${docs.map((d) => `${d}.md`).join(', ')} — kerjakan satu dokumen per jawaban, saya akan ketik "lanjut"):\n${list}`,
                                    );
                                    setChatOpen(true);
                                }}
                            >
                                ✦ Fix semua temuan di chat ({findings.length})
                            </button>
                        )}

                        {/* Open questions: derived dari interview skip + asumsi + kontradiksi input — belum dikonfirmasi klien */}
                        {openQuestionGroups.length > 0 && (
                            <div className="mt-3.5 border-t border-gray-200 pt-3.5">
                                <div className="text-[11px] font-bold tracking-[0.08em] text-gray-500">
                                    OPEN QUESTIONS ({openQuestionGroups.reduce((n, g) => n + g.items.length, 0)})
                                </div>
                                <div className="mt-2 flex max-h-[150px] flex-col gap-2 overflow-auto">
                                    {openQuestionGroups.map((g) => (
                                        <div key={g.name} className="flex flex-col gap-1.5">
                                            <div className="text-[10px] font-bold tracking-[0.08em] text-gray-400 uppercase">{g.name}</div>
                                            {g.items.map((q, i) => (
                                                <button
                                                    key={i}
                                                    type="button"
                                                    className="text-left text-[11.5px] font-semibold text-gray-600 hover:text-teal-700"
                                                    title="Klik untuk buat pertanyaan klarifikasi di chat"
                                                    onClick={() => {
                                                        setChatPrefill(`Buat pertanyaan klarifikasi untuk klien terkait: ${q}`);
                                                        setChatOpen(true);
                                                    }}
                                                >
                                                    · {q}
                                                </button>
                                            ))}
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}

                        {/* FR-09 subset: asisten chat spec — panel drawer kanan */}
                        <div className="mt-3.5 border-t border-gray-200 pt-3.5">
                            <AssistantButton busy={chatBusy} onOpen={() => setChatOpen(true)} />
                        </div>
                    </div>
                </div>
            </div>

            {/* modal pilih dokumen lanjutan */}
            {genModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center">
                    <div className="absolute inset-0 bg-gray-900/30" onClick={() => setGenModal(false)} />
                    <div className="relative w-full max-w-[420px] rounded-2xl bg-white p-5 shadow-2xl">
                        <div className="text-[15px] font-extrabold text-gray-900">Generate dokumen lanjutan</div>
                        <div className="mt-0.5 text-[12px] font-medium text-gray-400">Pilih dokumen yang mau digenerate — 1 kredit per run.</div>

                        <label className="mt-3.5 flex cursor-pointer items-center gap-2.5 border-b border-gray-100 pb-2.5 text-[12.5px] font-bold text-gray-700">
                            <input
                                type="checkbox"
                                className="h-4 w-4 accent-teal-600"
                                checked={genSel.size === missing_doc_keys.length && missing_doc_keys.length > 0}
                                onChange={(e) => setGenSel(e.target.checked ? new Set(missing_doc_keys) : new Set())}
                            />
                            Pilih semua ({missing_doc_keys.length})
                        </label>
                        <div className="max-h-[300px] overflow-y-auto">
                            {missing_doc_keys.map((k) => (
                                <label
                                    key={k}
                                    className="flex cursor-pointer items-center gap-2.5 py-2 text-[13px] font-semibold text-gray-700 hover:bg-gray-50"
                                >
                                    <input
                                        type="checkbox"
                                        className="h-4 w-4 accent-teal-600"
                                        checked={genSel.has(k)}
                                        onChange={() => toggleGenSel(k)}
                                    />
                                    <span className="font-mono text-[12.5px]">{k}.md</span>
                                </label>
                            ))}
                        </div>

                        <div className="mt-4 flex justify-end gap-2">
                            <button
                                className="rounded-lg px-4 py-2 text-[13px] font-bold text-gray-500 hover:bg-gray-100"
                                onClick={() => setGenModal(false)}
                            >
                                Batal
                            </button>
                            <button
                                disabled={genSel.size === 0}
                                className="rounded-lg bg-teal-600 px-4 py-2 text-[13px] font-bold text-white hover:bg-teal-700 disabled:opacity-40"
                                onClick={() => {
                                    router.post(
                                        route('projects.generate.missing', project.id),
                                        { doc_keys: [...genSel] },
                                        { preserveScroll: true, onSuccess: () => setGenModal(false) },
                                    );
                                }}
                            >
                                ✦ Generate {genSel.size} dokumen — 1 kredit
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* FR-09/FR-10: dialog "Usulkan perubahan" — analisa dampak + regenerate dokumen terpilih */}
            <ImpactDialog projectId={project.id} open={impactOpen} onClose={() => setImpactOpen(false)} creditsError={errors.credits} />

            {/* drawer chat asisten dari kanan */}
            <AssistantDrawer
                open={chatOpen}
                onClose={() => {
                    setChatOpen(false);
                    setChatPrefill(null);
                }}
                initialMessage={chatPrefill}
                projectId={project.id}
                projectName={project.name}
                contextLabel={`${activeKey}.md`}
                docKey={activeKey}
                applyTargets={documents}
                messages={assistant_messages}
                stream={chat_stream}
                quota={chat_quota}
                error={errors.assistant}
            />
        </SpektaLayout>
    );
}
