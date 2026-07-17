import { Head, router, useForm } from '@inertiajs/react';
import DOMPurify from 'dompurify';
import { marked } from 'marked';
import { useMemo, useState } from 'react';

import { promptDialog } from '@/components/system-dialog';

type PortalDoc = {
    id: string;
    doc_key: string;
    title: string;
    content_md: string | null;
    approved: boolean;
};

type PortalComment = {
    id: string;
    document_id: string;
    parent_id: string | null;
    author_name: string;
    author_type: string;
    section_anchor: string | null;
    body: string;
    status: string;
    created_at: string;
};

type PortalCr = {
    id: string;
    label: string;
    title: string;
    status: string;
    delta_md: number | null;
    delta_cost: number | null;
    impact_ready: boolean;
};

type Props = {
    mode: 'email' | 'otp' | 'portal';
    token: string;
    workspace_name: string;
    project_name: string;
    viewer_email?: string;
    is_approver?: boolean;
    approved_all?: boolean;
    documents?: PortalDoc[];
    comments?: PortalComment[];
    change_requests?: PortalCr[];
    errors: Record<string, string>;
};

const field = 'w-full rounded-[10px] border-2 border-gray-200 px-3.5 py-2.5 text-sm font-medium text-gray-700 focus:border-teal-400 focus:shadow-[0_0_0_3px_#F0FDFA] focus:outline-none';
const btnTeal = 'inline-flex items-center justify-center gap-[7px] rounded-[10px] bg-teal-600 px-4 py-2.5 text-[13px] font-bold text-white hover:bg-teal-700 disabled:opacity-50';

function Gate({ mode, token, workspace_name, project_name, errors }: Props) {
    const { data, setData, post, processing } = useForm({ email: '', code: '' });

    return (
        <div className="flex min-h-screen flex-col items-center justify-center bg-gray-50 p-5">
            <div className="mb-6 flex items-center gap-3">
                <div className="flex h-[38px] w-[38px] items-center justify-center rounded-[10px] bg-gradient-to-br from-teal-600 to-teal-400 text-[15px] font-extrabold text-white">
                    {workspace_name[0]}
                </div>
                <div>
                    <div className="text-[15px] font-extrabold text-gray-900">{workspace_name}</div>
                    <div className="text-xs font-medium text-gray-400">Proposal &amp; Spesifikasi — {project_name}</div>
                </div>
            </div>
            <div className="w-full max-w-[400px] rounded-2xl border border-gray-200 bg-white p-7">
                {mode === 'email' ? (
                    <>
                        <div className="text-lg font-extrabold text-gray-900">Verifikasi email Anda</div>
                        <div className="mt-1 text-[13px] text-gray-500">Masukkan email yang diundang — kode akses dikirim ke sana (berlaku 10 menit).</div>
                        <input
                            type="email"
                            className={`${field} mt-4`}
                            placeholder="nama@perusahaan.co.id"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                        />
                        {errors.email && <div className="mt-1 text-xs text-red-600">{errors.email}</div>}
                        <button className={`${btnTeal} mt-4 w-full`} disabled={processing || !data.email} onClick={() => post(route('portal.otp.request', token))}>
                            Kirim kode akses
                        </button>
                    </>
                ) : (
                    <>
                        <div className="text-lg font-extrabold text-gray-900">Masukkan kode akses</div>
                        <div className="mt-1 text-[13px] text-gray-500">6 digit terkirim ke email Anda.</div>
                        <input
                            inputMode="numeric"
                            maxLength={6}
                            className={`${field} mt-4 text-center font-mono text-xl tracking-[0.4em]`}
                            placeholder="••••••"
                            value={data.code}
                            onChange={(e) => setData('code', e.target.value.replace(/\D/g, ''))}
                        />
                        {errors.code && <div className="mt-1 text-xs text-red-600">{errors.code}</div>}
                        <button className={`${btnTeal} mt-4 w-full`} disabled={processing || data.code.length !== 6} onClick={() => post(route('portal.otp.verify', token))}>
                            Masuk portal
                        </button>
                        <button className="mt-2 w-full text-xs font-semibold text-gray-400 hover:text-teal-700" onClick={() => router.get(route('portal.show', token))}>
                            ← Ganti email
                        </button>
                    </>
                )}
            </div>
            <div className="mt-5 text-[11px] font-semibold text-gray-400">Powered by Spekta</div>
        </div>
    );
}

export default function Portal(props: Props) {
    const { token, workspace_name, project_name, documents = [], comments = [], change_requests = [], is_approver, approved_all } = props;
    const [activeId, setActiveId] = useState(documents[0]?.id ?? '');
    const [reply, setReply] = useState('');
    const doc = documents.find((d) => d.id === activeId);

    const html = useMemo(
        () => (doc?.content_md ? DOMPurify.sanitize(marked.parse(doc.content_md) as string) : ''),
        [doc?.content_md],
    );

    if (props.mode !== 'portal') {
        return (
            <>
                <Head title={`Portal — ${project_name}`} />
                <Gate {...props} />
            </>
        );
    }

    const docComments = comments.filter((c) => c.document_id === activeId);
    const roots = docComments.filter((c) => !c.parent_id);
    const approvedCount = documents.filter((d) => d.approved).length;

    const sendComment = (parentId?: string) => {
        if (!reply.trim()) return;
        router.post(route('portal.comments', token), { document_id: activeId, body: reply, parent_id: parentId ?? null }, {
            preserveScroll: true,
            onSuccess: () => setReply(''),
        });
    };

    return (
        <div className="min-h-screen bg-gray-50">
            <Head title={`Portal — ${project_name}`} />

            {/* header */}
            <div className="sticky top-0 z-20 border-b border-gray-200 bg-white">
                <div className="mx-auto flex max-w-[1180px] items-center justify-between gap-4 px-7 py-3.5">
                    <div className="flex items-center gap-3">
                        <div className="flex h-[38px] w-[38px] items-center justify-center rounded-[10px] bg-gradient-to-br from-teal-600 to-teal-400 text-[15px] font-extrabold text-white">
                            {workspace_name[0]}
                        </div>
                        <div>
                            <div className="text-[15px] font-extrabold text-gray-900">{workspace_name}</div>
                            <div className="text-xs font-medium text-gray-400">Proposal &amp; Spesifikasi — {project_name}</div>
                        </div>
                    </div>
                    <div className="flex items-center gap-3">
                        {approved_all ? (
                            <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-[5px] text-[10.5px] font-extrabold tracking-wide text-emerald-800">
                                <span className="h-1.5 w-1.5 rounded-full bg-emerald-500" />
                                DISETUJUI · SCOPE TERKUNCI
                            </span>
                        ) : (
                            <>
                                <span className="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-3 py-[5px] text-[10.5px] font-extrabold tracking-wide text-amber-800">
                                    <span className="h-1.5 w-1.5 rounded-full bg-amber-500" />
                                    MENUNGGU REVIEW
                                </span>
                                {is_approver && (
                                    <button className={btnTeal} onClick={() => router.post(route('portal.approve-all', token), {}, { preserveScroll: true })}>
                                        ✓ Setujui semua
                                    </button>
                                )}
                            </>
                        )}
                    </div>
                </div>
            </div>

            <div className="mx-auto flex max-w-[1180px] items-start gap-[18px] px-7 py-4 pb-10">
                {/* doc list */}
                <div className="w-[240px] flex-none rounded-xl border border-gray-200 bg-white p-3.5">
                    <div className="mb-2 text-[11px] font-bold tracking-[0.08em] text-gray-500">DOKUMEN</div>
                    <div className="flex flex-col gap-0.5">
                        {documents.map((d) => {
                            const count = comments.filter((c) => c.document_id === d.id).length;
                            return (
                                <button
                                    key={d.id}
                                    onClick={() => setActiveId(d.id)}
                                    className={`flex w-full items-center gap-2 rounded-lg px-2 py-2 text-left text-[12.5px] ${
                                        d.id === activeId ? 'bg-teal-50 font-bold text-teal-800' : 'font-medium text-gray-600 hover:bg-teal-50/60'
                                    }`}
                                >
                                    {d.approved ? (
                                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#16A34A" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round" className="flex-none">
                                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
                                            <polyline points="22 4 12 14.01 9 11.01" />
                                        </svg>
                                    ) : (
                                        <span className="box-border h-[15px] w-[15px] flex-none rounded-full border-2 border-gray-200" />
                                    )}
                                    <span className="min-w-0 truncate">{d.doc_key}</span>
                                    {count > 0 && (
                                        <span className="ml-auto rounded-full bg-blue-100 px-2 py-px font-mono text-[10px] font-bold text-blue-700">{count}</span>
                                    )}
                                </button>
                            );
                        })}
                    </div>
                    <div className="mt-3 border-t border-gray-100 pt-3 text-[11.5px] font-semibold text-gray-500">
                        Progres review
                        <div className="mt-[7px] h-1.5 overflow-hidden rounded-full bg-gray-200">
                            <div className="h-full rounded-full bg-emerald-500 transition-all" style={{ width: `${(approvedCount / Math.max(documents.length, 1)) * 100}%` }} />
                        </div>
                        <div className="mt-1.5 font-mono">{approvedCount} / {documents.length} disetujui</div>
                    </div>
                </div>

                {/* content */}
                <div className="min-w-0 flex-1">
                    <div className="rounded-xl border border-gray-200 bg-white px-6 py-[22px]">
                        <div className="text-lg font-extrabold text-gray-900">{doc?.title ?? doc?.doc_key}</div>
                        <article
                            className="prose prose-sm prose-headings:font-extrabold prose-headings:tracking-tight mt-3 max-w-none"
                            dangerouslySetInnerHTML={{ __html: html }}
                        />
                    </div>
                    <div className="mt-3 flex items-start gap-2.5 rounded-[10px] border border-red-200 border-l-[3px] border-l-red-500 bg-red-50 px-4 py-3">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#B91C1C" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round" className="mt-px flex-none">
                            <rect x="3" y="11" width="18" height="11" rx="2" />
                            <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                        </svg>
                        <div className="text-[12.5px] leading-relaxed font-medium text-red-900">
                            Setelah approval, perubahan scope tercatat sebagai <b className="font-bold">Change Request</b> dengan estimasi biaya &amp; dampak timeline tersendiri.
                        </div>
                    </div>
                </div>

                {/* comments + approve */}
                <div className="w-[290px] flex-none rounded-xl border border-gray-200 bg-white p-4">
                    <div className="text-[11px] font-bold tracking-[0.08em] text-gray-500">KOMENTAR — {doc?.doc_key}</div>
                    <div className="mt-3 flex max-h-[380px] flex-col gap-2.5 overflow-auto">
                        {roots.length === 0 && <div className="text-xs text-gray-400">Belum ada komentar.</div>}
                        {roots.map((c) => (
                            <div key={c.id}>
                                <div className="rounded-[10px] border border-gray-200 px-3 py-2.5">
                                    <div className="flex items-center gap-2">
                                        <div className={`flex h-6 w-6 flex-none items-center justify-center rounded-full text-[10px] font-extrabold text-white ${c.author_type === 'team' ? 'bg-teal-600' : 'bg-blue-600'}`}>
                                            {c.author_name.slice(0, 2).toUpperCase()}
                                        </div>
                                        <div className="min-w-0">
                                            <div className="truncate text-[11.5px] font-bold text-gray-800">{c.author_name}</div>
                                            <div className="text-[10px] font-medium text-gray-400">{c.created_at}</div>
                                        </div>
                                    </div>
                                    <div className="mt-[7px] text-[12.5px] leading-relaxed font-medium text-gray-700">{c.body}</div>
                                </div>
                                {docComments.filter((r) => r.parent_id === c.id).map((r) => (
                                    <div key={r.id} className="mt-1.5 ml-5 rounded-[10px] border border-gray-200 px-3 py-2.5">
                                        <div className="text-[11.5px] font-bold text-gray-800">{r.author_name} <span className="font-medium text-gray-400">· {r.created_at}</span></div>
                                        <div className="mt-1 text-[12.5px] leading-relaxed font-medium text-gray-700">{r.body}</div>
                                    </div>
                                ))}
                            </div>
                        ))}
                    </div>
                    <input
                        className={`${field} mt-2.5 !text-[12.5px]`}
                        placeholder="Tulis komentar… (Enter)"
                        value={reply}
                        onChange={(e) => setReply(e.target.value)}
                        onKeyDown={(e) => e.key === 'Enter' && sendComment()}
                    />
                    <div className="mt-3.5 flex flex-col gap-2 border-t border-gray-100 pt-3.5">
                        {doc?.approved ? (
                            <div className="flex w-full items-center justify-center gap-[7px] rounded-[10px] border border-emerald-200 bg-emerald-50 py-2.5 text-[12.5px] font-bold text-emerald-800">
                                ✓ Disetujui
                            </div>
                        ) : is_approver ? (
                            <button
                                className={`${btnTeal} w-full`}
                                onClick={() => router.post(route('portal.approve', token), { document_id: activeId }, { preserveScroll: true })}
                            >
                                ✓ Setujui dokumen ini
                            </button>
                        ) : (
                            <div className="text-center text-[11px] font-medium text-gray-400">Hanya approver utama yang dapat menyetujui.</div>
                        )}
                        <button
                            className="w-full rounded-[10px] border border-gray-200 bg-white py-2.5 text-[12.5px] font-bold text-gray-700 hover:bg-gray-50"
                            onClick={async () => {
                                const title = await promptDialog('Jelaskan perubahan yang diminta (jadi Change Request bernomor):');
                                if (title) router.post(route('portal.cr.propose', token), { title }, { preserveScroll: true });
                            }}
                        >
                            Minta perubahan
                        </button>
                    </div>

                    {change_requests.length > 0 && (
                        <div className="mt-3.5 border-t border-gray-100 pt-3.5">
                            <div className="text-[11px] font-bold tracking-[0.08em] text-gray-500">CHANGE REQUEST</div>
                            <div className="mt-2 flex flex-col gap-2">
                                {change_requests.map((cr) => (
                                    <div key={cr.id} className="rounded-[10px] border border-gray-200 px-3 py-2.5 text-[12px]">
                                        <div className="flex items-center gap-2">
                                            <span className="font-mono font-bold text-gray-800">{cr.label}</span>
                                            <span className={`rounded-full px-2 py-0.5 text-[9px] font-extrabold uppercase ${
                                                cr.status === 'approved' ? 'bg-emerald-100 text-emerald-800' : cr.status === 'rejected' ? 'bg-gray-200 text-gray-500' : 'bg-amber-100 text-amber-800'
                                            }`}>
                                                {cr.status}
                                            </span>
                                        </div>
                                        <div className="mt-1 font-medium text-gray-700">{cr.title}</div>
                                        {cr.impact_ready && (
                                            <div className="mt-1 font-mono text-[11px] text-gray-500">
                                                Δ {cr.delta_md} MD · Rp {Math.round((cr.delta_cost ?? 0) / 1e6)} jt
                                            </div>
                                        )}
                                        {cr.status === 'proposed' && is_approver && (
                                            cr.impact_ready ? (
                                                <div className="mt-2 flex gap-1.5">
                                                    <button
                                                        className="flex-1 rounded-lg bg-teal-600 py-1.5 text-[11px] font-bold text-white hover:bg-teal-700"
                                                        onClick={() => router.post(route('portal.cr.decide', [token, cr.id]), { decision: 'approved' }, { preserveScroll: true })}
                                                    >
                                                        Setujui — baseline baru
                                                    </button>
                                                    <button
                                                        className="rounded-lg border border-gray-200 px-2.5 py-1.5 text-[11px] font-bold text-gray-500 hover:text-red-600"
                                                        onClick={() => router.post(route('portal.cr.decide', [token, cr.id]), { decision: 'rejected' }, { preserveScroll: true })}
                                                    >
                                                        Tolak
                                                    </button>
                                                </div>
                                            ) : (
                                                <div className="mt-1.5 text-[10.5px] font-medium text-gray-400">Menunggu impact review tim…</div>
                                            )
                                        )}
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}
