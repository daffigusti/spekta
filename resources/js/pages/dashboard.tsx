import { Head, Link, router, usePage } from '@inertiajs/react';

import { confirmDialog } from '@/components/system-dialog';
import SpektaLayout from '@/layouts/spekta-layout';

interface ProjectCard {
    id: string;
    name: string;
    client_name: string | null;
    status: string;
    wizard_step: string;
    health_score: number | null;
    complexity: number | null;
    doc_count: number;
    total_md: number;
    updated_at: string;
}

const badges: Record<string, { label: string; cls: string; dot: string }> = {
    draft: { label: 'DRAFT', cls: 'bg-gray-100 text-gray-600', dot: 'bg-gray-400' },
    generating: { label: 'GENERATING', cls: 'bg-amber-50 text-amber-700', dot: 'bg-amber-500' },
    ready: { label: 'READY', cls: 'bg-teal-50 text-teal-700', dot: 'bg-teal-500' },
    shared: { label: 'SHARED', cls: 'bg-blue-50 text-blue-700', dot: 'bg-blue-500' },
    approved: { label: 'APPROVED', cls: 'bg-emerald-50 text-emerald-700', dot: 'bg-emerald-500' },
    archived: { label: 'ARCHIVED', cls: 'bg-gray-100 text-gray-500', dot: 'bg-gray-400' },
};

function healthColor(h: number | null) {
    if (h == null) return '#9CA3AF';
    if (h >= 85) return '#059669';
    if (h >= 70) return '#D97706';
    return '#DC2626';
}

export default function Dashboard({ projects }: { projects: ProjectCard[] }) {
    const workspace = usePage<{ workspace: { name: string } | null; [key: string]: unknown }>().props.workspace;

    return (
        <SpektaLayout crumb="Proyek" active="projects">
            <Head title="Proyek — Spekta" />

            <div className="mb-5 flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 className="text-[26px] font-extrabold tracking-tight text-gray-900">
                        Proyek <span className="text-lg font-semibold text-gray-400">({projects.length})</span>
                    </h1>
                    <div className="mt-1 text-sm font-medium tracking-[0.02em] text-gray-500">
                        Semua blueprint di workspace {workspace?.name ?? 'Anda'}
                    </div>
                </div>
                <button
                    onClick={() => router.post(route('projects.store'))}
                    className="inline-flex items-center gap-1.5 rounded-[10px] bg-teal-600 px-4.5 py-2.5 text-[13px] font-bold text-white hover:bg-teal-700"
                >
                    <svg
                        width="14"
                        height="14"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        strokeWidth="2.4"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                    >
                        <line x1="12" y1="5" x2="12" y2="19" />
                        <line x1="5" y1="12" x2="19" y2="12" />
                    </svg>
                    Proyek baru
                </button>
            </div>

            <div className="grid gap-4" style={{ gridTemplateColumns: 'repeat(auto-fill,minmax(280px,1fr))' }}>
                {projects.map((p) => {
                    const b = badges[p.status] ?? badges.draft;
                    // draft & generating → wizard (progress + live stream); selebihnya → workspace dokumen
                    const href =
                        (p.status === 'draft' && p.wizard_step !== 'done') || p.status === 'generating'
                            ? route('projects.wizard', p.id)
                            : route('projects.show', p.id);
                    return (
                        <Link
                            key={p.id}
                            href={href}
                            className="group rounded-xl border border-gray-200 bg-white p-[18px] transition hover:-translate-y-px hover:shadow-lg"
                        >
                            <div className="flex items-start justify-between gap-2.5">
                                <div className="text-sm leading-tight font-bold text-gray-800">{p.name}</div>
                                <div className="flex flex-none items-center gap-1.5">
                                    <span
                                        className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[10px] font-extrabold tracking-wide ${b.cls}`}
                                    >
                                        <span className={`h-1.5 w-1.5 rounded-full ${b.dot}`} />
                                        {b.label}
                                    </span>
                                    {/* BR-29: shared/approved tidak bisa dihapus — tombol disembunyikan */}
                                    {!['shared', 'approved'].includes(p.status) && (
                                        <button
                                            type="button"
                                            title="Hapus proyek"
                                            className="hidden h-6 w-6 items-center justify-center rounded-md text-gray-300 group-hover:flex hover:bg-red-50 hover:text-red-500"
                                            onClick={async (e) => {
                                                e.preventDefault();
                                                e.stopPropagation();
                                                if (await confirmDialog(`Hapus proyek "${p.name}" beserta semua dokumennya?`)) {
                                                    router.delete(route('projects.destroy', p.id));
                                                }
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
                                                <path d="M3 6h18" />
                                                <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6" />
                                                <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                                            </svg>
                                        </button>
                                    )}
                                </div>
                            </div>
                            <div className="mt-1 text-xs font-medium text-gray-400">
                                {p.client_name ?? 'Tanpa klien'} · {p.updated_at}
                            </div>
                            <div className="mt-3.5 flex flex-wrap items-center justify-between gap-2 border-t border-gray-100 pt-3 text-xs">
                                <div className="flex items-center gap-1.5 font-semibold text-gray-500">
                                    <svg
                                        width="14"
                                        height="14"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke={healthColor(p.health_score)}
                                        strokeWidth="2.2"
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                    >
                                        <path d="M22 12h-4l-3 9L9 3l-3 9H2" />
                                    </svg>
                                    Health{' '}
                                    <span className="font-mono font-bold" style={{ color: healthColor(p.health_score) }}>
                                        {p.health_score ?? '—'}
                                    </span>
                                </div>
                                <div className="font-mono text-xs font-semibold text-gray-600">
                                    {p.doc_count} docs · {p.total_md} MD
                                </div>
                            </div>
                        </Link>
                    );
                })}

                <button
                    onClick={() => router.post(route('projects.store'))}
                    className="flex min-h-[130px] flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-gray-200 text-[13px] font-semibold text-gray-400 hover:border-teal-400 hover:bg-teal-50 hover:text-teal-700"
                >
                    <svg
                        width="22"
                        height="22"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        strokeWidth="2.2"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                    >
                        <path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3z" />
                        <path d="M19 10v2a7 7 0 0 1-14 0v-2" />
                        <line x1="12" y1="19" x2="12" y2="22" />
                    </svg>
                    Buat dari transkrip meeting
                </button>
            </div>
        </SpektaLayout>
    );
}
