import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import { promptDialog } from '@/components/system-dialog';
import WorkspaceLayout from '@/layouts/workspace-layout';
import { type Node } from '@/pages/wizard';

type Props = {
    project: { id: string; name: string; client_name: string | null; status: string; wizard_step: string; scope_mode: string };
    nodes: Node[];
};

const STATUSES = [
    { key: 'todo', label: 'To Do', dot: 'bg-gray-400' },
    { key: 'doing', label: 'In Progress', dot: 'bg-amber-500' },
    { key: 'done', label: 'Done', dot: 'bg-teal-600' },
] as const;

type TaskRow = {
    task: Node;
    parent: Node | null; // sub-fitur (atau fitur bila task langsung di bawah fitur)
    feature: Node | null;
    phase: Node | null;
};

// Baris CSV di-escape penuh: koma, kutip, newline
const csvCell = (v: string | number | null | undefined) => {
    const s = String(v ?? '');
    return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
};

const downloadBlob = (content: string, mime: string, filename: string) => {
    const url = URL.createObjectURL(new Blob([content], { type: mime }));
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.click();
    URL.revokeObjectURL(url);
};

export default function Tasks({ project, nodes }: Props) {
    const byId = new Map(nodes.map((n) => [n.id, n]));
    const up = (n: Node | null, kind: string): Node | null => {
        let cur = n;
        while (cur && cur.kind !== kind) cur = cur.parent_id ? (byId.get(cur.parent_id) ?? null) : null;
        return cur;
    };

    const rows: TaskRow[] = nodes
        .filter((n) => n.kind === 'task' && n.scope !== 'parked')
        .map((t) => ({
            task: t,
            parent: t.parent_id ? (byId.get(t.parent_id) ?? null) : null,
            feature: up(t, 'feature'),
            phase: up(t, 'phase'),
        }));

    const [view, setView] = useState<'list' | 'kanban'>(() => (localStorage.getItem('spekta.tasks.view') === 'kanban' ? 'kanban' : 'list'));
    useEffect(() => localStorage.setItem('spekta.tasks.view', view), [view]);
    const [expanded, setExpanded] = useState<string | null>(null);

    const statusOf = (t: Node) => t.status ?? 'todo';
    const setStatus = (t: Node, status: string) =>
        router.patch(route('wizard.nodes.update', [project.id, t.id]), { status }, { preserveScroll: true });

    const addTask = async (parent: Node) => {
        const title = await promptDialog(`Judul task baru di "${parent.title}":`);
        if (title)
            router.post(route('wizard.nodes.store', project.id), { parent_id: parent.id, kind: 'task', title, est_md: 1 }, { preserveScroll: true });
    };

    const parkTask = (t: Node) => router.delete(route('wizard.nodes.destroy', [project.id, t.id]), { preserveScroll: true });

    // Export — kolom kompatibel import ClickUp/Notion (CSV) + tree terstruktur (JSON)
    const exportCsv = () => {
        const header = 'Task Name,Description,Status,Estimate (MD),Phase,Feature,Subfeature';
        const lines = rows.map((r) =>
            [
                r.task.title,
                r.task.description,
                statusOf(r.task),
                r.task.est_md,
                r.phase?.title,
                r.feature?.title,
                r.parent?.kind === 'subfeature' ? r.parent.title : '',
            ]
                .map(csvCell)
                .join(','),
        );
        downloadBlob([header, ...lines].join('\n'), 'text/csv;charset=utf-8', `${project.name} - tasks.csv`);
    };

    const exportJson = () => {
        const tree = rows.map((r) => ({
            title: r.task.title,
            description: r.task.description,
            status: statusOf(r.task),
            est_md: Number(r.task.est_md),
            phase: r.phase?.title ?? null,
            feature: r.feature?.title ?? null,
            subfeature: r.parent?.kind === 'subfeature' ? r.parent.title : null,
        }));
        downloadBlob(JSON.stringify({ project: project.name, tasks: tree }, null, 2), 'application/json', `${project.name} - tasks.json`);
    };

    // grouping list view: fase → fitur → (sub-fitur) → tasks
    const phases = nodes.filter((n) => n.kind === 'phase');
    const featuresOf = (pid: string) => nodes.filter((n) => n.parent_id === pid && n.kind === 'feature' && n.scope !== 'parked');
    const subsOf = (fid: string) => nodes.filter((n) => n.parent_id === fid && n.kind === 'subfeature' && n.scope !== 'parked');
    const tasksOf = (pid: string) => nodes.filter((n) => n.parent_id === pid && n.kind === 'task' && n.scope !== 'parked');

    const statusBadge = (t: Node) => (
        <select
            value={statusOf(t)}
            onChange={(e) => setStatus(t, e.target.value)}
            className="rounded-md border border-gray-200 bg-white px-1.5 py-0.5 text-[11px] font-bold text-gray-600 focus:border-teal-400 focus:outline-none"
        >
            {STATUSES.map((s) => (
                <option key={s.key} value={s.key}>
                    {s.label}
                </option>
            ))}
        </select>
    );

    const taskRow = (t: Node) => (
        <div key={t.id} className="rounded-lg border border-gray-100 bg-white px-3 py-2">
            <div className="flex items-center gap-2.5">
                <span className={`h-2 w-2 flex-none rounded-full ${STATUSES.find((s) => s.key === statusOf(t))?.dot}`} />
                <button
                    className="min-w-0 flex-1 truncate text-left text-[13px] font-semibold text-gray-800 hover:text-teal-700"
                    onClick={() => setExpanded(expanded === t.id ? null : t.id)}
                >
                    {t.title}
                </button>
                <span className="font-mono text-[11px] text-gray-400">{Number(t.est_md).toFixed(0)} MD</span>
                {statusBadge(t)}
                <button className="text-[11px] font-bold text-gray-300 hover:text-red-600" title="Parkir task" onClick={() => parkTask(t)}>
                    ✕
                </button>
            </div>
            {expanded === t.id && (
                <div className="mt-1.5 pl-[18px] text-[12.5px] leading-relaxed text-gray-500">
                    {t.description || <span className="italic">Belum ada deskripsi.</span>}
                </div>
            )}
        </div>
    );

    return (
        <WorkspaceLayout>
            <Head title={`Tasks — ${project.name}`} />
            <div className="mx-auto w-full max-w-5xl px-6 py-6">
                <div className="mb-5 flex flex-wrap items-center gap-3">
                    <Link href={route('projects.show', project.id)} className="text-[13px] font-bold text-gray-500 hover:text-teal-700">
                        ← {project.name}
                    </Link>
                    <span className="text-gray-300">/</span>
                    <h1 className="text-[15px] font-extrabold text-gray-900">Tasks</h1>
                    <span className="font-mono text-[12px] text-gray-400">{rows.length} task</span>
                    <div className="ml-auto flex items-center gap-2">
                        <div className="flex overflow-hidden rounded-lg border border-gray-200 shadow-sm">
                            {(['list', 'kanban'] as const).map((v) => (
                                <button
                                    key={v}
                                    onClick={() => setView(v)}
                                    className={`px-3 py-1.5 text-xs font-extrabold uppercase ${view === v ? 'bg-teal-600 text-white' : 'bg-white text-gray-500'}`}
                                >
                                    {v === 'list' ? 'List' : 'Kanban'}
                                </button>
                            ))}
                        </div>
                        <button
                            className="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-bold text-gray-600 shadow-sm hover:border-teal-300"
                            onClick={exportCsv}
                        >
                            Export CSV
                        </button>
                        <button
                            className="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-bold text-gray-600 shadow-sm hover:border-teal-300"
                            onClick={exportJson}
                        >
                            Export JSON
                        </button>
                    </div>
                </div>

                {rows.length === 0 && (
                    <div className="rounded-xl border border-dashed border-gray-200 bg-white p-8 text-center text-[13px] text-gray-400">
                        Belum ada task. Task digenerate AI saat menyusun struktur, atau tambah manual dari struktur proyek.
                    </div>
                )}

                {view === 'list' ? (
                    <div className="space-y-6">
                        {phases.map((p) => {
                            const feats = featuresOf(p.id).filter((f) => subsOf(f.id).some((s) => tasksOf(s.id).length) || tasksOf(f.id).length);
                            if (!feats.length) return null;
                            return (
                                <div key={p.id}>
                                    <h2 className="mb-2 text-[13px] font-extrabold tracking-wide text-gray-500 uppercase">{p.title}</h2>
                                    <div className="space-y-4">
                                        {feats.map((f) => (
                                            <div key={f.id} className="rounded-xl border border-gray-200 bg-gray-50/60 p-3">
                                                <div className="mb-2 text-[13px] font-bold text-gray-800">{f.title}</div>
                                                <div className="space-y-3">
                                                    {subsOf(f.id)
                                                        .filter((s) => tasksOf(s.id).length)
                                                        .map((s) => (
                                                            <div key={s.id}>
                                                                <div className="mb-1 flex items-center justify-between">
                                                                    <span className="text-[11.5px] font-semibold text-gray-500">{s.title}</span>
                                                                    <button
                                                                        className="text-[11px] font-bold text-teal-700 hover:text-teal-900"
                                                                        onClick={() => addTask(s)}
                                                                    >
                                                                        + Task
                                                                    </button>
                                                                </div>
                                                                <div className="space-y-1">{tasksOf(s.id).map(taskRow)}</div>
                                                            </div>
                                                        ))}
                                                    {tasksOf(f.id).length > 0 && (
                                                        <div className="space-y-1">{tasksOf(f.id).map(taskRow)}</div>
                                                    )}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                ) : (
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                        {STATUSES.map((s) => {
                            const cards = rows.filter((r) => statusOf(r.task) === s.key);
                            return (
                                <div key={s.key} className="rounded-xl border border-gray-200 bg-gray-50/60 p-3">
                                    <div className="mb-2.5 flex items-center gap-2">
                                        <span className={`h-2 w-2 rounded-full ${s.dot}`} />
                                        <span className="text-[12px] font-extrabold tracking-wide text-gray-600 uppercase">{s.label}</span>
                                        <span className="font-mono text-[11px] text-gray-400">{cards.length}</span>
                                    </div>
                                    <div className="space-y-2">
                                        {cards.map((r) => (
                                            <div key={r.task.id} className="rounded-lg border border-gray-200 bg-white p-2.5 shadow-sm">
                                                <div className="text-[12.5px] font-semibold text-gray-800">{r.task.title}</div>
                                                {r.task.description && (
                                                    <div className="mt-0.5 line-clamp-2 text-[11.5px] leading-snug text-gray-500">
                                                        {r.task.description}
                                                    </div>
                                                )}
                                                <div className="mt-1.5 flex items-center gap-2">
                                                    <span className="min-w-0 flex-1 truncate text-[10.5px] font-medium text-gray-400">
                                                        {[r.feature?.title, r.parent?.kind === 'subfeature' ? r.parent.title : null]
                                                            .filter(Boolean)
                                                            .join(' › ')}
                                                    </span>
                                                    <span className="font-mono text-[10.5px] text-gray-400">
                                                        {Number(r.task.est_md).toFixed(0)} MD
                                                    </span>
                                                    {statusBadge(r.task)}
                                                </div>
                                            </div>
                                        ))}
                                        {cards.length === 0 && <div className="py-4 text-center text-[11.5px] text-gray-300 italic">Kosong</div>}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                )}
            </div>
        </WorkspaceLayout>
    );
}
