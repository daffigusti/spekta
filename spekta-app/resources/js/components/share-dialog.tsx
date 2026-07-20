import { router } from '@inertiajs/react';
import { useState } from 'react';

type Props = {
    projectId: string;
    /** Semua dokumen proyek — link share mencakup seluruhnya. */
    docKeys: string[];
    open: boolean;
    onClose: () => void;
    /** Validation errors dari Inertia (approver_email, contact_emails.*, …). */
    errors?: Record<string, string>;
};

/** FR-17: form share ke portal klien — approver utama (BR-27), kontak lain (BR-40), internal review (BR-30). */
export default function ShareDialog({ projectId, docKeys, open, onClose, errors = {} }: Props) {
    const [approver, setApprover] = useState('');
    const [contacts, setContacts] = useState('');
    const [days, setDays] = useState(30);
    const [reviewed, setReviewed] = useState(false);
    const [busy, setBusy] = useState(false);

    if (!open) return null;

    // ponytail: input koma-separated, bukan repeater 4 field — backend validasi per email
    const contactList = contacts
        .split(',')
        .map((s) => s.trim())
        .filter(Boolean);
    const tooMany = contactList.length > 4; // BR-40: maks 5 kontak termasuk approver

    const errorMsg =
        errors.approver_email ??
        errors.contact_emails ??
        Object.entries(errors).find(([k]) => k.startsWith('contact_emails.'))?.[1] ??
        errors.internal_review_done ??
        errors.expires_days;

    const close = () => {
        onClose();
        setApprover('');
        setContacts('');
        setDays(30);
        setReviewed(false);
    };

    const submit = () => {
        setBusy(true);
        router.post(
            route('projects.share', projectId),
            {
                approver_email: approver.trim(),
                contact_emails: contactList,
                doc_keys: docKeys,
                expires_days: days,
                internal_review_done: reviewed,
            },
            {
                preserveScroll: true,
                onSuccess: close,
                onFinish: () => setBusy(false),
                // gagal validasi → dialog tetap terbuka, pesan tampil lewat prop errors
            },
        );
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center">
            <div className="absolute inset-0 bg-gray-900/30" onClick={close} />
            <div className="relative w-full max-w-md rounded-2xl bg-white p-5 shadow-2xl">
                <div className="text-[15px] font-extrabold text-gray-900">Share ke portal klien</div>
                <div className="mt-0.5 text-[12px] font-medium text-gray-400">
                    Klien menerima link + OTP email untuk review &amp; approval dokumen.
                </div>

                <label className="mt-4 block text-[11.5px] font-bold tracking-[0.04em] text-gray-500">EMAIL APPROVER UTAMA</label>
                {/* BR-27: satu approver utama — hanya email ini yang bisa approve */}
                <input
                    type="email"
                    className="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-[12.5px] focus:border-teal-500 focus:outline-none"
                    placeholder="nama@klien.com"
                    value={approver}
                    onChange={(e) => setApprover(e.target.value)}
                />

                <label className="mt-3.5 block text-[11.5px] font-bold tracking-[0.04em] text-gray-500">KONTAK KLIEN LAIN (OPSIONAL)</label>
                {/* BR-40: maks 4 kontak tambahan — boleh komentar, tidak bisa approve */}
                <input
                    type="text"
                    className="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-[12.5px] focus:border-teal-500 focus:outline-none"
                    placeholder="email1@klien.com, email2@klien.com"
                    value={contacts}
                    onChange={(e) => setContacts(e.target.value)}
                />
                <p className="mt-1 text-[10.5px] font-medium text-gray-400">
                    Maks 4, pisahkan dengan koma. Bisa komentar &amp; jawab pertanyaan, tidak bisa approve.
                </p>
                {tooMany && <p className="mt-1 text-[11.5px] font-semibold text-red-600">Maksimal 4 kontak tambahan.</p>}

                <label className="mt-3.5 block text-[11.5px] font-bold tracking-[0.04em] text-gray-500">MASA BERLAKU LINK</label>
                <div className="mt-1 flex items-center gap-2">
                    <input
                        type="number"
                        min={1}
                        max={90}
                        className="w-24 rounded-lg border border-gray-300 px-3 py-2 text-[12.5px] focus:border-teal-500 focus:outline-none"
                        value={days}
                        onChange={(e) => setDays(Number(e.target.value))}
                    />
                    <span className="text-[12px] font-medium text-gray-500">hari (maks 90)</span>
                </div>

                {/* BR-30: empat mata — wajib dicentang sebelum share */}
                <label className="mt-4 flex items-start gap-2.5 rounded-lg border border-gray-100 bg-gray-50 px-3 py-2.5">
                    <input
                        type="checkbox"
                        className="mt-0.5 h-4 w-4 accent-teal-600"
                        checked={reviewed}
                        onChange={(e) => setReviewed(e.target.checked)}
                    />
                    <span className="text-[12.5px] font-medium text-gray-700">
                        Internal review selesai — minimal satu Owner/Admin sudah memeriksa dokumen.
                    </span>
                </label>

                {errorMsg && <p className="mt-2 text-[11.5px] font-semibold text-red-600">{errorMsg}</p>}

                <div className="mt-4 flex justify-end gap-2">
                    <button type="button" className="rounded-lg px-4 py-2 text-[13px] font-bold text-gray-500 hover:bg-gray-100" onClick={close}>
                        Batal
                    </button>
                    <button
                        type="button"
                        className="rounded-lg bg-teal-600 px-4 py-2 text-[13px] font-bold text-white hover:bg-teal-700 disabled:opacity-40"
                        disabled={busy || !approver.trim() || !reviewed || tooMany || days < 1 || days > 90}
                        onClick={submit}
                    >
                        {busy ? 'Membagikan…' : 'Share ke klien'}
                    </button>
                </div>
            </div>
        </div>
    );
}
