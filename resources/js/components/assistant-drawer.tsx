import { router } from '@inertiajs/react';
import DOMPurify from 'dompurify';
import { marked } from 'marked';
import { useEffect, useRef, useState } from 'react';

import MarkdownPreview from '@/components/markdown-preview';

const mdHtml = (md: string) => DOMPurify.sanitize(marked.parse(md) as string);

// usulan revisi dokumen dari asisten: <<<DOC key\n…markdown…\nDOC>>> — bisa lebih dari satu blok
export const parseAssistant = (body: string) => {
    const proposals: { docKey: string; md: string }[] = [];
    let truncatedKey: string | null = null;
    const text = body
        .replace(/<<<DOC\s+([\w-]+)\s*\n([\s\S]*?)\nDOC>>>/g, (_all, key: string, md: string) => {
            proposals.push({ docKey: key, md });
            return '';
        })
        // blok terbuka tanpa penutup = jawaban terpotong max_tokens — buang isinya, catat key-nya
        .replace(/<<<DOC\s+([\w-]+)[\s\S]*$/, (_all, key: string) => {
            truncatedKey = key;
            return '';
        })
        .replace(/<<<DOC[^\n]*|DOC>>>/g, '') // sapu sisa penanda tidak lengkap
        .trim();
    return { text, proposals, truncatedKey };
};

export type AssistantMsg = { id: string; role: string; body: string };
export type ApplyTarget = { id: string; doc_key: string; content_md: string | null };

type Props = {
    open: boolean;
    onClose: () => void;
    projectId: string;
    projectName: string;
    contextLabel: string;
    docKey: string;
    /** nama layar wireframe terpilih — dikirim ke AI agar revisi fokus ke layar itu */
    screen?: string | null;
    /** dokumen yang bisa jadi target "Terapkan" usulan revisi */
    applyTargets: ApplyTarget[];
    messages: AssistantMsg[];
    stream: string | null;
    error?: string;
    /** props Inertia yang di-reload setelah apply usulan */
    reloadOnApply?: string[];
    placeholder?: string;
    emptyHint?: string;
    /** prefill input saat drawer dibuka (mis. tombol "Fix di chat" pada temuan spec health) */
    initialMessage?: string | null;
    /** BR-01: pemakaian chat bulan ini vs kuota paket (limit null = unlimited) */
    quota?: { used: number; limit: number | null; plan: string } | null;
};

export default function AssistantDrawer({
    open,
    onClose,
    projectId,
    projectName,
    contextLabel,
    docKey,
    screen = null,
    applyTargets,
    messages,
    stream,
    error,
    reloadOnApply = ['documents', 'project', 'findings', 'errors'],
    placeholder = 'Tanya atau minta perubahan… (Enter)',
    emptyHint = 'Tanya apa pun soal spec — mis. "Apa dampak menambah e-wallet OVO ke FR-02?"',
    initialMessage = null,
    quota = null,
}: Props) {
    const [chat, setChat] = useState('');
    useEffect(() => {
        if (open && initialMessage) setChat(initialMessage);
    }, [open, initialMessage]);
    const [chatSending, setChatSending] = useState(false);
    const [appliedProposals, setAppliedProposals] = useState<Set<string>>(new Set());
    const [applyingKey, setApplyingKey] = useState<string | null>(null);
    const [closing, setClosing] = useState(false);
    const chatEndRef = useRef<HTMLDivElement>(null);

    const lastMsg = messages[messages.length - 1];
    const busy = chatSending || stream != null || lastMsg?.role === 'user';

    // Typewriter: poll datang per ~1.2 dtk dalam gumpalan — reveal per karakter biar terasa live.
    // Init dengan stream saat mount: refresh/reopen lanjut dari posisi sekarang, bukan replay dari awal
    const [shown, setShown] = useState(stream ?? '');
    const targetRef = useRef('');
    useEffect(() => {
        targetRef.current = stream ?? '';
        if (stream == null) setShown('');
    }, [stream]);
    useEffect(() => {
        if (stream == null || !open) return;
        // Tick 90ms bukan rAF: tiap tick = parse markdown + replace innerHTML seluruh teks — di 60fps dokumen panjang bikin berat
        const iv = setInterval(() => {
            setShown((s) => {
                const t = targetRef.current;
                if (!t.startsWith(s)) return t;
                if (s.length >= t.length) return s;
                // ponytail: decay eksponensial ~1.2s habiskan sisa — selaras cadence poll
                return t.slice(0, s.length + Math.max(12, Math.ceil((t.length - s.length) / 13)));
            });
        }, 90);
        return () => clearInterval(iv);
    }, [stream == null, open]);

    const close = () => {
        setClosing(true);
        setTimeout(() => {
            onClose();
            setClosing(false);
        }, 240);
    };

    const sendChat = () => {
        if (!chat.trim() || busy) return;
        setChatSending(true);
        router.post(
            route('projects.assistant', projectId),
            { message: chat, doc_key: docKey, screen },
            {
                preserveScroll: true,
                preserveState: true, // tanpa ini POST me-reset state komponen — drawer tertutup, terasa seperti refresh
                only: ['assistant_messages', 'chat_stream', 'errors'],
                onSuccess: () => setChat(''),
                onFinish: () => setChatSending(false),
            },
        );
    };

    // poll stream jawaban asisten selama menunggu
    useEffect(() => {
        if (!busy || !open) return;
        const t = setInterval(() => router.reload({ only: ['assistant_messages', 'chat_stream'] }), 1200);
        return () => clearInterval(t);
    }, [busy, open]);

    // auto-scroll chat ke bawah saat ada pesan/stream baru
    useEffect(() => {
        chatEndRef.current?.scrollIntoView({ block: 'nearest' });
    }, [messages.length, shown.length, busy]);

    if (!open) return null;

    return (
        <div className="fixed inset-0 z-40">
            <div
                className={`absolute inset-0 bg-gray-900/30 ${closing ? '[animation:fade-out_.25s_ease-in_forwards]' : '[animation:fade-in_.2s_ease-out]'}`}
                onClick={close}
            />
            <div
                className={`absolute top-0 right-0 flex h-full w-full max-w-[480px] flex-col bg-gray-50 shadow-2xl ${
                    closing ? '[animation:drawer-out_.25s_ease-in_forwards]' : '[animation:drawer-in_.25s_ease-out]'
                }`}
            >
                <div className="flex flex-none items-center gap-2.5 border-b border-gray-200 bg-white px-5 py-4">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#0D9488" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
                        <path d="m12 3-1.9 5.8a2 2 0 0 1-1.3 1.3L3 12l5.8 1.9a2 2 0 0 1 1.3 1.3L12 21l1.9-5.8a2 2 0 0 1 1.3-1.3L21 12l-5.8-1.9a2 2 0 0 1-1.3-1.3L12 3z" />
                    </svg>
                    <div className="min-w-0 flex-1">
                        <div className="text-[14px] font-extrabold text-gray-900">Asisten AI</div>
                        <div className="truncate text-[11.5px] font-medium text-gray-400">
                            Konteks: {contextLabel} · {projectName}
                        </div>
                    </div>
                    {quota && quota.limit != null && (
                        <span
                            title={`Kuota chat AI paket ${quota.plan}: ${quota.used} dari ${quota.limit} bulan ini`}
                            className={`flex-none rounded-full border px-2.5 py-1 font-mono text-[10.5px] font-bold ${
                                quota.used >= quota.limit
                                    ? 'border-red-200 bg-red-50 text-red-600'
                                    : quota.used >= quota.limit * 0.8
                                      ? 'border-amber-200 bg-amber-50 text-amber-700'
                                      : 'border-gray-200 bg-gray-50 text-gray-500'
                            }`}
                        >
                            {quota.used}/{quota.limit}
                        </span>
                    )}
                    <button className="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700" onClick={close}>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round">
                            <line x1="18" y1="6" x2="6" y2="18" />
                            <line x1="6" y1="6" x2="18" y2="18" />
                        </svg>
                    </button>
                </div>

                <div className="flex min-h-0 flex-1 flex-col gap-2.5 overflow-y-auto px-5 py-4">
                    {messages.length === 0 && <div className="text-[12.5px] font-medium text-gray-400">{emptyHint}</div>}
                    {messages.map((m) => {
                        if (m.role === 'user')
                            return (
                                <div key={m.id} className="ml-10 rounded-xl bg-teal-800 px-3.5 py-2.5 text-[13px] font-medium text-teal-50">
                                    {m.body}
                                </div>
                            );
                        const { text, proposals, truncatedKey } = parseAssistant(m.body);
                        return (
                            <div key={m.id} className="rounded-xl border border-gray-200 bg-white px-3.5 py-3">
                                <MarkdownPreview
                                    html={mdHtml(text)}
                                    className="prose prose-sm max-w-none text-[13px] leading-relaxed text-gray-700 prose-headings:my-2 prose-headings:text-[13.5px] prose-p:my-1.5 prose-table:text-[12px] prose-li:my-0.5"
                                />
                                {proposals.map((proposal, pi) => {
                                    const target = applyTargets.find((d) => d.doc_key === proposal.docKey);
                                    if (!target) return null;
                                    const key = `${m.id}:${pi}`;
                                    const applied = appliedProposals.has(key) || target.content_md === proposal.md;
                                    // Guard salah label: judul awal usulan menyebut dokumen LAIN → AI kemungkinan salah KEY
                                    const head = proposal.md.split('\n').slice(0, 3).join('\n');
                                    const mismatch = applyTargets.find(
                                        (d) => d.doc_key !== proposal.docKey && new RegExp(`^#\\s+.*\\b${d.doc_key}(\\.md)?\\b`, 'mi').test(head),
                                    );
                                    return (
                                        <div key={key} className="mt-2.5 flex items-center justify-between gap-2 rounded-[10px] border border-teal-200 bg-teal-50 px-3 py-2.5">
                                            <div className="min-w-0">
                                                <div className="text-[12px] font-extrabold text-teal-900">✦ Usulan revisi {proposal.docKey}.md</div>
                                                <div className="text-[11px] font-medium text-teal-700">
                                                    {proposal.md.split('\n').length} baris — tersimpan sebagai versi baru
                                                </div>
                                                {mismatch && !applied && (
                                                    <div className="mt-1 rounded-md border border-amber-300 bg-amber-50 px-2 py-1 text-[11px] font-bold text-amber-700">
                                                        ⚠ Isi tampak seperti {mismatch.doc_key}.md — cek dulu sebelum terapkan, ini bisa menimpa{' '}
                                                        {proposal.docKey}.md dengan konten yang salah.
                                                    </div>
                                                )}
                                            </div>
                                            {applied ? (
                                                <span className="flex-none rounded-lg border border-teal-300 px-3 py-1.5 text-[11.5px] font-bold text-teal-700">
                                                    ✓ Diterapkan
                                                </span>
                                            ) : (
                                                <button
                                                    disabled={applyingKey !== null}
                                                    className="flex-none rounded-lg bg-teal-600 px-3 py-1.5 text-[11.5px] font-bold text-white hover:bg-teal-700 disabled:opacity-50"
                                                    onClick={() => {
                                                        setApplyingKey(key);
                                                        router.post(
                                                            route('documents.versions.store', target.id),
                                                            { content_md: proposal.md },
                                                            {
                                                                preserveScroll: true,
                                                                only: reloadOnApply,
                                                                onSuccess: () => setAppliedProposals((prev) => new Set(prev).add(key)),
                                                                onFinish: () => setApplyingKey(null),
                                                            },
                                                        );
                                                    }}
                                                >
                                                    {applyingKey === key ? 'Menerapkan…' : 'Terapkan'}
                                                </button>
                                            )}
                                        </div>
                                    );
                                })}
                                {truncatedKey && (
                                    <div className="mt-2.5 rounded-[10px] border border-amber-300 bg-amber-50 px-3 py-2.5 text-[12px] font-semibold text-amber-700">
                                        ⚠ Usulan revisi {truncatedKey}.md terpotong — jawaban kena batas panjang output. Minta AI kirim ulang
                                        dokumen itu saja, mis. "kirim ulang revisi {truncatedKey}.md".
                                    </div>
                                )}
                            </div>
                        );
                    })}
                    {busy &&
                        stream &&
                        (() => {
                            const cut = shown.indexOf('<<<DOC');
                            const streamText = cut === -1 ? shown : shown.slice(0, cut);
                            return (
                                <div className="rounded-xl border border-teal-200 bg-white px-3.5 py-3">
                                    <MarkdownPreview
                                        html={mdHtml(streamText) + '<span class="animate-pulse text-teal-600">▌</span>'}
                                        skipLastMermaid={(streamText.match(/```/g)?.length ?? 0) % 2 === 1}
                                        className="prose prose-sm max-w-none text-[13px] leading-relaxed text-gray-700 prose-headings:my-2 prose-headings:text-[13.5px] prose-p:my-1.5 prose-table:text-[12px] prose-li:my-0.5"
                                    />
                                    {cut !== -1 && (
                                        <div className="mt-2 flex items-center gap-2 text-[11.5px] font-bold text-teal-700">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#0D9488" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round" className="animate-spin">
                                                <path d="M21 12a9 9 0 1 1-6.219-8.56" />
                                            </svg>
                                            Menulis usulan revisi dokumen…
                                        </div>
                                    )}
                                </div>
                            );
                        })()}
                    {busy && !stream && (
                        <div className="flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-3.5 py-3 text-[12.5px] font-semibold text-teal-800">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#0D9488" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round" className="animate-spin">
                                <path d="M21 12a9 9 0 1 1-6.219-8.56" />
                            </svg>
                            Menganalisis spec…
                        </div>
                    )}
                    {error && <div className="text-[12px] font-semibold text-red-600">{error}</div>}
                    <div ref={chatEndRef} />
                </div>

                <div className="flex flex-none gap-2 border-t border-gray-200 bg-white px-5 py-3.5">
                    <input
                        autoFocus
                        className="min-w-0 flex-1 rounded-[10px] border-2 border-gray-200 bg-white px-3.5 py-2.5 text-[13px] font-medium text-gray-700 focus:border-teal-400 focus:shadow-[0_0_0_3px_#F0FDFA] focus:outline-none"
                        placeholder={placeholder}
                        value={chat}
                        disabled={busy}
                        onChange={(e) => setChat(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                sendChat();
                            }
                        }}
                    />
                    <button
                        type="button"
                        title="Kirim"
                        disabled={busy || !chat.trim()}
                        onClick={sendChat}
                        className="flex-none rounded-[10px] bg-teal-600 px-3.5 text-white hover:bg-teal-700 disabled:opacity-40"
                    >
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
                            <path d="m22 2-7 20-4-9-9-4Z" />
                            <path d="M22 2 11 13" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    );
}

/** Tombol pembuka drawer + indikator busy — dipakai di panel samping halaman. */
export function AssistantButton({ busy, onOpen }: { busy: boolean; onOpen: () => void }) {
    return (
        <button
            onClick={onOpen}
            className="flex w-full items-center justify-center gap-2 rounded-[10px] border-2 border-teal-200 bg-teal-50 px-3 py-2.5 text-[12.5px] font-bold text-teal-800 hover:bg-teal-100"
        >
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#0D9488" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
                <path d="m12 3-1.9 5.8a2 2 0 0 1-1.3 1.3L3 12l5.8 1.9a2 2 0 0 1 1.3 1.3L12 21l1.9-5.8a2 2 0 0 1 1.3-1.3L21 12l-5.8-1.9a2 2 0 0 1-1.3-1.3L12 3z" />
            </svg>
            Chat dengan Asisten AI
            {busy && (
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#0D9488" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round" className="animate-spin">
                    <path d="M21 12a9 9 0 1 1-6.219-8.56" />
                </svg>
            )}
        </button>
    );
}
