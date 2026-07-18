import { router } from '@inertiajs/react';
import { useState } from 'react';

type Affected = { doc_key: string; reason: string; manual_edit: boolean };
type Impact = { summary: string; delta_md: number; affected: Affected[] };

// baca cookie XSRF-TOKEN Laravel — fetch() manual (bukan router.post) butuh header ini sendiri
function xsrf(): string {
    return decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
}

type Props = {
    projectId: string;
    open: boolean;
    onClose: () => void;
    /** errors.credits dari Inertia — regenerate() bisa balik back()->withErrors(['credits' => …]) (BR-02/BR-05) */
    creditsError?: string;
};

/** FR-09/FR-10: dialog "Usulkan perubahan" — analisa dampak lalu regenerate dokumen terpilih. */
export default function ImpactDialog({ projectId, open, onClose, creditsError }: Props) {
    const [text, setText] = useState('');
    const [impact, setImpact] = useState<Impact | null>(null);
    const [selected, setSelected] = useState<Record<string, boolean>>({});
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    if (!open) return null;

    const analyze = async () => {
        setBusy(true);
        setError('');
        try {
            const res = await fetch(route('projects.impact', projectId), {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
                body: JSON.stringify({ change_text: text }),
            });
            if (!res.ok) {
                // abort(402/403, message) Laravel balas JSON { message } saat Accept: application/json — pakai pesan aslinya, bukan cuma status
                const body: { message?: string } | null = await res.json().catch(() => null);
                throw new Error(body?.message ?? `Analisa dampak gagal (${res.status}).`);
            }
            const data: Impact = await res.json();
            setImpact(data);
            // Dokumen ber-edit manual default TIDAK dicentang — keputusan sadar user (design §2)
            setSelected(Object.fromEntries(data.affected.map((a) => [a.doc_key, !a.manual_edit])));
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Analisa dampak gagal.');
        } finally {
            setBusy(false);
        }
    };

    const regenerate = () => {
        const doc_keys = Object.keys(selected).filter((k) => selected[k]);
        router.post(
            route('projects.regenerate', projectId),
            { change_text: text, doc_keys },
            {
                preserveScroll: true,
                onSuccess: () => {
                    onClose();
                    setImpact(null);
                    setText('');
                },
                // gagal (mis. errors.credits) → dialog tetap terbuka, pesan tampil lewat prop creditsError
            },
        );
    };

    const close = () => {
        onClose();
        setImpact(null);
        setText('');
        setError('');
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center">
            <div className="absolute inset-0 bg-gray-900/30" onClick={close} />
            <div className="relative w-full max-w-lg rounded-2xl bg-white p-5 shadow-2xl">
                <div className="text-[15px] font-extrabold text-gray-900">Usulkan perubahan — analisa dampak</div>
                <div className="mt-0.5 text-[12px] font-medium text-gray-400">FR-09/FR-10 — lihat dokumen terdampak sebelum regenerasi.</div>

                {creditsError && (
                    <div className="mt-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-[11.5px] font-semibold text-red-600">{creditsError}</div>
                )}

                {!impact ? (
                    <>
                        <textarea
                            className="mt-3.5 h-28 w-full rounded-lg border border-gray-300 p-3 text-[12.5px] focus:border-teal-500 focus:outline-none"
                            placeholder="Jelaskan perubahan yang diinginkan…"
                            value={text}
                            onChange={(e) => setText(e.target.value)}
                        />
                        {error && <p className="mt-1.5 text-[11.5px] font-semibold text-red-600">{error}</p>}
                        <div className="mt-4 flex justify-end gap-2">
                            <button type="button" className="rounded-lg px-4 py-2 text-[13px] font-bold text-gray-500 hover:bg-gray-100" onClick={close}>
                                Batal
                            </button>
                            <button
                                type="button"
                                className="rounded-lg bg-teal-600 px-4 py-2 text-[13px] font-bold text-white hover:bg-teal-700 disabled:opacity-40"
                                disabled={busy || text.trim().length < 5}
                                onClick={analyze}
                            >
                                {busy ? 'Menganalisa…' : 'Analisa dampak'}
                            </button>
                        </div>
                    </>
                ) : (
                    <>
                        <p className="mt-3.5 text-[13px] font-medium text-gray-700">{impact.summary}</p>
                        <p className="mt-1 text-[11.5px] font-semibold text-gray-400">Perkiraan delta effort: {impact.delta_md} MD</p>
                        <ul className="mt-3 flex max-h-[260px] flex-col gap-1.5 overflow-y-auto">
                            {impact.affected.map((a) => (
                                <li key={a.doc_key} className="flex items-start gap-2.5 rounded-lg border border-gray-100 bg-gray-50 px-3 py-2 text-[12.5px]">
                                    <input
                                        type="checkbox"
                                        className="mt-0.5 h-4 w-4 accent-teal-600"
                                        checked={!!selected[a.doc_key]}
                                        onChange={(e) => setSelected({ ...selected, [a.doc_key]: e.target.checked })}
                                    />
                                    <span className="min-w-0 font-medium text-gray-700">
                                        <span className="font-mono font-bold text-gray-900">{a.doc_key}</span> — {a.reason}
                                        {a.manual_edit && (
                                            <span className="ml-1.5 rounded-full bg-amber-100 px-1.5 py-0.5 text-[9px] font-extrabold text-amber-700">
                                                ada edit manual
                                            </span>
                                        )}
                                    </span>
                                </li>
                            ))}
                        </ul>
                        <div className="mt-4 flex justify-end gap-2">
                            <button type="button" className="rounded-lg px-4 py-2 text-[13px] font-bold text-gray-500 hover:bg-gray-100" onClick={() => setImpact(null)}>
                                Kembali
                            </button>
                            <button
                                type="button"
                                className="rounded-lg bg-teal-600 px-4 py-2 text-[13px] font-bold text-white hover:bg-teal-700 disabled:opacity-40"
                                disabled={!Object.values(selected).some(Boolean)}
                                onClick={regenerate}
                            >
                                Regenerate terpilih
                            </button>
                        </div>
                    </>
                )}
            </div>
        </div>
    );
}
