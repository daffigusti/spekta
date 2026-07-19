import { Head, Link, router, useForm } from '@inertiajs/react';
import DOMPurify from 'dompurify';
import { marked } from 'marked';
import { useEffect, useMemo, useRef, useState } from 'react';

import MarkdownPreview from '@/components/markdown-preview';
import { confirmDialog, promptDialog, selectDialog } from '@/components/system-dialog';
import WorkspaceLayout from '@/layouts/workspace-layout';
import {
    Building2,
    CalendarCheck,
    CarFront,
    ClipboardList,
    GraduationCap,
    School,
    ShoppingBag,
    ShoppingCart,
    Stethoscope,
    Store,
    Ticket,
    Wallet,
    type LucideIcon,
} from 'lucide-react';

export type Node = {
    id: string;
    parent_id: string | null;
    kind: string;
    title: string;
    description: string | null;
    scope: string;
    est_md: number;
    phase_no: number | null;
};

export type StackLayer = {
    layer: string;
    choice: string;
    justification: string | null;
    alternatives: { choice: string; reason_rejected: string }[];
    source: string;
};

type InterviewItem = {
    seq: number;
    question: string;
    reason: string | null;
    options: string[];
    answer_text: string | null;
    skipped: boolean;
};

type RunNode = {
    doc_key: string;
    status: string;
    error_text?: string | null;
};

export type Understanding = {
    roles: { name: string; note: string }[];
    features: { title: string; quote: string }[];
    domain: string | null;
    complexity: number;
    assumptions: string[];
    // pernyataan input yang saling bertentangan (deteksi FR-02) — null di baris lama
    contradictions?: string[] | null;
    confirmed: boolean;
};

export type StepJob = { status: string; step: string; error?: string | null } | null;

type Props = {
    project: { id: string; name: string; client_name: string | null; status: string; wizard_step: string; scope_mode: string };
    input: { kind: string; raw_text: string } | null;
    understanding: Understanding | null;
    interview: InterviewItem[];
    nodes: Node[];
    stack: StackLayer[];
    run: { id: string; status: string; nodes: RunNode[] } | null;
    stream: { doc_key: string; text: string } | null;
    credits: number;
    errors: Record<string, string>;
    step_job: StepJob;
};

const STEPS = [
    { key: 'input', label: 'Input' },
    { key: 'understanding', label: 'Analisa' },
    { key: 'interview', label: 'Interview' },
    { key: 'structure', label: 'Struktur' },
    { key: 'stack', label: 'Stack' },
    { key: 'generate', label: 'Generate' },
];

const btn =
    'inline-flex items-center gap-1.5 rounded-[10px] bg-teal-600 px-5 py-2.5 text-[13px] font-bold text-white hover:bg-teal-700 disabled:opacity-50';
const btnGhost =
    'inline-flex items-center rounded-[10px] border border-gray-200 bg-white px-4 py-2.5 text-[13px] font-bold text-gray-700 hover:bg-gray-50';
const field =
    'w-full rounded-[10px] border-2 border-gray-200 px-3.5 py-2.5 text-sm font-medium text-gray-700 focus:border-teal-400 focus:shadow-[0_0_0_3px_#F0FDFA] focus:outline-none';
const sectionLabel = 'text-[11px] font-bold tracking-[0.08em] text-gray-500 uppercase';
const Spinner = ({ stroke = '#0D9488' }: { stroke?: string }) => (
    <svg
        width="15"
        height="15"
        viewBox="0 0 24 24"
        fill="none"
        stroke={stroke}
        strokeWidth="2.4"
        strokeLinecap="round"
        strokeLinejoin="round"
        className="flex-none animate-spin"
    >
        <path d="M21 12a9 9 0 1 1-6.219-8.56" />
    </svg>
);

// Job step async (WizardStepJob): poll reload ringan sampai selesai (wizard_step maju) / error.
// Selesai = cache dihapus server → stepJob null → parent otomatis render step berikutnya.
function useStepJob(stepJob: StepJob | undefined, step: 'structure' | 'stack') {
    const mine = !!stepJob && stepJob.step === step;
    const active = mine && ['queued', 'running'].includes(stepJob.status);
    const error = mine && stepJob.status === 'error' ? (stepJob.error ?? 'AI gagal — coba lagi.') : null;
    useEffect(() => {
        if (!active) return;
        const t = setInterval(() => router.reload({ only: ['step_job', 'project', 'nodes', 'stack'] }), 1500);
        return () => clearInterval(t);
    }, [active]);
    return { active, error };
}

const CheckCircle = ({ stroke = '#16A34A' }: { stroke?: string }) => (
    <svg
        width="15"
        height="15"
        viewBox="0 0 24 24"
        fill="none"
        stroke={stroke}
        strokeWidth="2.4"
        strokeLinecap="round"
        strokeLinejoin="round"
        className="flex-none"
    >
        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
        <polyline points="22 4 12 14.01 9 11.01" />
    </svg>
);

function Stepper({ current }: { current: string }) {
    const idx = STEPS.findIndex((s) => s.key === current);
    return (
        <div className="mb-6 flex items-center overflow-x-auto rounded-xl border border-gray-200 bg-white px-[22px] py-3.5">
            <div className="flex min-w-max flex-1 items-center">
                {STEPS.map((s, i) => (
                    <div key={s.key} className="flex flex-1 items-center last:flex-none">
                        <div className="flex flex-none items-center gap-[9px]">
                            <span
                                className={`flex h-7 w-7 flex-none items-center justify-center rounded-full border-2 font-mono text-[11.5px] font-extrabold ${
                                    i < idx
                                        ? 'border-teal-600 bg-teal-600 text-white'
                                        : i === idx
                                          ? 'border-teal-600 bg-teal-50 text-teal-700 shadow-[0_0_0_4px_#F0FDFA]'
                                          : 'border-gray-200 bg-white text-gray-400'
                                }`}
                            >
                                {i < idx ? (
                                    <svg
                                        width="13"
                                        height="13"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        strokeWidth="3"
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                    >
                                        <polyline points="20 6 9 17 4 12" />
                                    </svg>
                                ) : (
                                    i + 1
                                )}
                            </span>
                            <span
                                className={`text-[12.5px] whitespace-nowrap ${i === idx ? 'font-extrabold text-gray-900' : 'font-semibold text-gray-400'}`}
                            >
                                {s.label}
                            </span>
                        </div>
                        {i < STEPS.length - 1 && (
                            <div className={`mx-3 h-0.5 min-w-3.5 flex-1 rounded-full ${i < idx ? 'bg-teal-500' : 'bg-gray-200'}`} />
                        )}
                    </div>
                ))}
            </div>
        </div>
    );
}

// ---------- STEP 1: INPUT ----------
function StepInput({ project, input }: Pick<Props, 'project' | 'input'>) {
    const { data, setData, post, processing, errors } = useForm<{
        kind: string;
        raw_text: string;
        file: File | null;
        language: string;
        depth: string;
        work_mode: string;
        template: string;
    }>({
        kind: input?.kind ?? 'idea',
        raw_text: input?.raw_text ?? '',
        file: null,
        language: 'id',
        depth: 'auto',
        work_mode: 'ai_assisted',
        template: 'default',
    });
    const [dragOver, setDragOver] = useState(false);
    const fileInput = useRef<HTMLInputElement>(null);

    const pickFile = (f: File | undefined) => {
        if (!f) return;
        const ok = ['txt', 'md', 'docx', 'pdf'].includes(f.name.split('.').pop()?.toLowerCase() ?? '');
        if (ok) setData('file', f);
    };

    const KINDS = [
        {
            v: 'idea',
            l: 'Ketik Ide',
            ph: 'Contoh: "Klien saya butuh aplikasi kasir untuk 3 cabang toko retail, dengan pembayaran QRIS, laporan penjualan harian, dan prediksi stok…"',
        },
        {
            v: 'transcript',
            l: 'Transkrip / Notulen Meeting',
            ph: 'Tempel transkrip meeting di sini, atau tarik file rekaman/notulen ke area di bawah…',
        },
        { v: 'rfp', l: 'Dokumen RFP / Brief', ph: 'Tempel isi dokumen RFP / brief klien di sini…' },
    ];

    // Starter ide — user klik lalu ganti bagian [dalam kurung] dengan konteks kliennya
    const IDEA_TEMPLATES: { l: string; icon: LucideIcon; text: string }[] = [
        {
            l: 'Kasir / POS',
            icon: ShoppingCart,
            text: 'Klien saya butuh aplikasi kasir untuk [jenis usaha, mis. toko retail] dengan [jumlah] cabang. Fitur utama: pencatatan transaksi penjualan, pembayaran [QRIS / tunai / kartu], manajemen stok, dan laporan penjualan [harian / bulanan]. Pengguna: [kasir, pemilik toko]. Kebutuhan lain: [integrasi printer struk, prediksi stok, dsb].',
        },
        {
            l: 'Toko Online',
            icon: ShoppingBag,
            text: 'Klien ingin toko online untuk menjual [jenis produk]. Fitur: katalog produk, keranjang, checkout dengan pembayaran [QRIS / VA / COD], pelacakan pesanan, dan panel admin untuk kelola produk & pesanan. Target pasar [B2C / B2B], perkiraan [jumlah] produk. Kebutuhan lain: [integrasi ekspedisi, voucher / promo].',
        },
        {
            l: 'Booking / Reservasi',
            icon: CalendarCheck,
            text: 'Klien butuh sistem booking online untuk [jenis layanan, mis. lapangan futsal / klinik / salon]. Pelanggan bisa lihat jadwal ketersediaan, booking slot, dan bayar [DP / lunas] via [payment gateway]. Admin mengelola jadwal, konfirmasi booking, dan laporan. Kebutuhan lain: [reminder WhatsApp, kalender staf].',
        },
        {
            l: 'Company Profile + CMS',
            icon: Building2,
            text: 'Klien butuh website company profile untuk [jenis perusahaan] dengan CMS agar tim bisa update konten sendiri. Halaman: beranda, tentang kami, layanan / produk, portofolio, blog / berita, dan kontak dengan form. Kebutuhan lain: [multi-bahasa, SEO, tombol WhatsApp].',
        },
        {
            l: 'Sistem Internal',
            icon: ClipboardList,
            text: 'Klien butuh sistem internal untuk mengelola [proses, mis. inventori / absensi & cuti / proyek]. Role: [admin, staf, manajer]. Alur utama: [input data → approval → laporan]. Fitur: dashboard ringkasan, notifikasi, dan export laporan [Excel / PDF]. Kebutuhan lain: [integrasi sistem yang sudah ada].',
        },
        {
            l: 'Marketplace',
            icon: Store,
            text: 'Klien ingin marketplace multi-vendor untuk [jenis produk / jasa]. Penjual bisa buka toko, kelola produk, dan terima pesanan; pembeli checkout dengan [payment gateway] dan dana diteruskan ke penjual [otomatis / manual]. Fitur: komisi platform [persen], rating & ulasan, chat pembeli-penjual. Kebutuhan lain: [verifikasi penjual, ongkir otomatis].',
        },
        {
            l: 'E-learning / LMS',
            icon: GraduationCap,
            text: 'Klien butuh platform e-learning untuk [target, mis. kursus online / pelatihan karyawan]. Fitur: katalog kelas, materi [video / PDF / kuis], progress belajar, sertifikat, dan pembayaran kelas [sekali beli / langganan]. Role: [siswa, instruktur, admin]. Kebutuhan lain: [live class, forum diskusi].',
        },
        {
            l: 'Akademik Sekolah',
            icon: School,
            text: 'Klien butuh sistem informasi akademik untuk [jenjang, mis. SMP / SMA / kampus] dengan [jumlah] siswa. Fitur: data siswa & guru, jadwal pelajaran, absensi, nilai & rapor digital, dan pengumuman. Role: [admin, guru, siswa, orang tua]. Kebutuhan lain: [pembayaran SPP online, e-learning].',
        },
        {
            l: 'Klinik / Kesehatan',
            icon: Stethoscope,
            text: 'Klien butuh sistem untuk [klinik / praktik dokter / apotek]. Fitur: pendaftaran & antrian pasien, rekam medis elektronik, jadwal dokter, resep & stok obat, dan kasir. Role: [admin, dokter, perawat, kasir]. Kebutuhan lain: [integrasi BPJS / Satu Sehat, reminder jadwal kontrol].',
        },
        {
            l: 'Event & Tiket',
            icon: Ticket,
            text: 'Klien butuh platform penjualan tiket untuk [jenis event, mis. konser / seminar / workshop]. Fitur: halaman event, pembelian tiket via [payment gateway], e-ticket dengan QR code, dan check-in scan di lokasi. Role: [penyelenggara, pembeli]. Kebutuhan lain: [kategori tiket / early bird, laporan penjualan].',
        },
        {
            l: 'Rental / Sewa',
            icon: CarFront,
            text: 'Klien punya usaha rental [kendaraan / alat berat / kamera] dan butuh sistem sewa online. Fitur: katalog unit dengan kalender ketersediaan, booking & pembayaran [DP / lunas], kontrak / bukti sewa, dan pengingat jatuh tempo pengembalian. Kebutuhan lain: [denda keterlambatan, tracking unit].',
        },
        {
            l: 'Koperasi / Simpan Pinjam',
            icon: Wallet,
            text: 'Klien butuh sistem koperasi simpan pinjam untuk [jumlah] anggota. Fitur: pendaftaran anggota, simpanan [pokok / wajib / sukarela], pengajuan & angsuran pinjaman, perhitungan bunga [flat / menurun], dan laporan SHU. Role: [admin, bendahara, anggota]. Kebutuhan lain: [notifikasi jatuh tempo, aplikasi mobile anggota].',
        },
    ];

    const applyTemplate = async (text: string) => {
        if (data.raw_text.trim() && !(await confirmDialog('Ganti isi yang sudah diketik dengan template ini?'))) return;
        setData('raw_text', text);
    };

    // Guard template mentah: placeholder [kurung] belum diisi → konfirmasi, bukan hard block
    const submitInput = async () => {
        const holes = data.kind === 'idea' ? data.raw_text.match(/\[[^\]\n]{2,80}\]/g) : null;
        if (holes && !(await confirmDialog(`Masih ada ${holes.length} bagian [kurung] yang belum diisi (mis. ${holes[0]}). Lanjut analisa saja?`)))
            return;
        post(route('wizard.input', project.id));
    };
    const chip = (active: boolean) =>
        `rounded-full border px-3 py-1.5 text-[12px] font-bold ${active ? 'border-teal-600 bg-teal-50 text-teal-800' : 'border-gray-200 bg-white text-gray-500 hover:border-teal-300'}`;

    return (
        <div>
            <h1 className="mb-1 text-[26px] font-extrabold tracking-[-0.02em] text-gray-900">Mulai blueprint baru</h1>
            <div className="mb-5 text-sm text-gray-500">
                Dari ide, transkrip meeting klien, atau dokumen RFP — AI mengekstrak requirement untuk Anda.
            </div>

            <div className="grid items-start gap-[18px]" style={{ gridTemplateColumns: 'minmax(0,1fr) minmax(300px,380px)' }}>
                <div>
                    <div className="mb-3.5 flex flex-wrap gap-2">
                        {KINDS.map((k) => (
                            <button
                                key={k.v}
                                type="button"
                                onClick={() => setData('kind', k.v)}
                                className={`inline-flex items-center rounded-[10px] border-2 px-3.5 py-2 text-[13px] font-bold ${
                                    data.kind === k.v
                                        ? 'border-teal-600 bg-teal-50 text-teal-800'
                                        : 'border-gray-200 bg-white text-gray-500 hover:border-teal-300'
                                }`}
                            >
                                {k.l}
                            </button>
                        ))}
                    </div>
                    <textarea
                        className={`${field} h-[170px] resize-none`}
                        placeholder={KINDS.find((k) => k.v === data.kind)?.ph}
                        value={data.raw_text}
                        onChange={(e) => setData('raw_text', e.target.value)}
                    />
                    {errors.raw_text && <div className="mt-1 text-xs text-red-600">{errors.raw_text}</div>}

                    {data.kind === 'idea' && (
                        <div className="mt-2.5 flex flex-wrap items-center gap-1.5">
                            <span className="text-[12px] font-semibold text-gray-400">Bingung mulai? Klik template lalu isi bagian [kurung]:</span>
                            {IDEA_TEMPLATES.map((t) => (
                                <button
                                    key={t.l}
                                    type="button"
                                    onClick={() => void applyTemplate(t.text)}
                                    className={`inline-flex items-center gap-1.5 ${chip(false)}`}
                                    title={t.text}
                                >
                                    <t.icon size={13} strokeWidth={2.2} className="text-teal-600" />
                                    {t.l}
                                </button>
                            ))}
                        </div>
                    )}

                    {/* FR-01: upload .txt/.md/.docx/.pdf — teks diekstrak lalu digabung dengan textarea */}
                    <input
                        ref={fileInput}
                        type="file"
                        accept=".txt,.md,.docx,.pdf"
                        className="hidden"
                        onChange={(e) => pickFile(e.target.files?.[0])}
                    />
                    <div
                        onClick={() => fileInput.current?.click()}
                        onDragOver={(e) => {
                            e.preventDefault();
                            setDragOver(true);
                        }}
                        onDragLeave={() => setDragOver(false)}
                        onDrop={(e) => {
                            e.preventDefault();
                            setDragOver(false);
                            pickFile(e.dataTransfer.files?.[0]);
                        }}
                        className={`mt-3 cursor-pointer rounded-xl border-2 border-dashed p-[18px] text-center ${
                            dragOver
                                ? 'border-teal-400 bg-teal-50 text-teal-700'
                                : data.file
                                  ? 'border-teal-300 bg-teal-50/50 text-gray-600'
                                  : 'border-gray-200 bg-white text-gray-500 hover:border-teal-300'
                        }`}
                    >
                        <svg
                            width="22"
                            height="22"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="#0D9488"
                            strokeWidth="2.2"
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            className="mx-auto mb-1.5"
                        >
                            <path d="M4 14.899A7 7 0 1 1 15.71 8h1.79a4.5 4.5 0 0 1 2.5 8.242" />
                            <path d="M12 12v9" />
                            <path d="m16 16-4-4-4 4" />
                        </svg>
                        {data.file ? (
                            <div className="text-[13px] font-semibold">
                                📄 {data.file.name}{' '}
                                <button
                                    type="button"
                                    className="ml-1.5 font-bold text-gray-400 hover:text-red-500"
                                    onClick={(e) => {
                                        e.stopPropagation();
                                        setData('file', null);
                                        if (fileInput.current) fileInput.current.value = '';
                                    }}
                                >
                                    ✕ hapus
                                </button>
                            </div>
                        ) : (
                            <div className="text-[13px] font-semibold">
                                Atau tarik file ke sini{' '}
                                <span className="font-medium text-gray-400">— .txt · .md · .docx · .pdf (audio/video segera)</span>
                            </div>
                        )}
                        <div className="mt-2.5 flex justify-center gap-1.5">
                            {['Fireflies', 'Google Meet', 'WhatsApp export'].map((s) => (
                                <span
                                    key={s}
                                    className="rounded-full border border-gray-200 bg-gray-50 px-2.5 py-[3px] text-[11px] font-bold text-gray-600"
                                >
                                    {s}
                                </span>
                            ))}
                        </div>
                    </div>
                    {errors.file && <div className="mt-1 text-xs text-red-600">{errors.file}</div>}
                </div>

                <div className="rounded-xl border border-gray-200 bg-white p-[18px]">
                    <div className="text-[15px] font-bold text-gray-800">Pengaturan blueprint</div>
                    <div className="mt-4">
                        <div className={sectionLabel}>Bahasa dokumen</div>
                        <div className="mt-2 flex flex-wrap gap-1.5">
                            {[
                                { v: 'id', l: 'Indonesia' },
                                { v: 'en', l: 'English' },
                                { v: 'bilingual', l: 'Bilingual' },
                            ].map((o) => (
                                <button key={o.v} type="button" className={chip(data.language === o.v)} onClick={() => setData('language', o.v)}>
                                    {o.l}
                                </button>
                            ))}
                        </div>
                    </div>
                    <div className="mt-4">
                        <div className={sectionLabel}>Kedalaman dokumen</div>
                        <div className="mt-2 flex flex-wrap gap-1.5">
                            {[
                                { v: 'concise', l: 'Ringkas (5 docs)' },
                                { v: 'auto', l: 'Otomatis' },
                                { v: 'full', l: 'Lengkap (13 docs)' },
                            ].map((o) => (
                                <button key={o.v} type="button" className={chip(data.depth === o.v)} onClick={() => setData('depth', o.v)}>
                                    {o.l}
                                </button>
                            ))}
                        </div>
                        {/* Help dinamis mengikuti pilihan — dokumen yang tak digenerate bisa ditambah nanti (1 kredit) */}
                        <div className="mt-2 rounded-lg bg-gray-50 px-3 py-2.5 text-[11.5px] leading-relaxed font-medium text-gray-500">
                            {data.depth === 'concise' && (
                                <>
                                    <b className="text-gray-700">Ringkas</b> — 5 dokumen inti: PRD, Requirements, User Flows, Wireframes, Roadmap.
                                    Cocok untuk validasi ide cepat atau proposal awal ke klien.
                                </>
                            )}
                            {data.depth === 'auto' && (
                                <>
                                    <b className="text-gray-700">Otomatis</b> — AI menilai kompleksitas proyek (1–5) dari input Anda, lalu memilih set
                                    dokumen: sederhana 5 docs, menengah 7 docs (+ Database, API), kompleks 13 docs lengkap.
                                </>
                            )}
                            {data.depth === 'full' && (
                                <>
                                    <b className="text-gray-700">Lengkap</b> — 13 dokumen: PRD, Requirements, User Flows, Wireframes, Business Rules,
                                    Database, API, Architecture, Security, Features, Testing, Design, Roadmap. Untuk spek siap-development.
                                </>
                            )}{' '}
                            Dokumen yang belum digenerate bisa ditambah kapan saja lewat "Generate dokumen lanjutan" di halaman proyek.
                        </div>
                    </div>
                    <div className="mt-4">
                        <div className={sectionLabel}>Mode pengerjaan (estimasi)</div>
                        <div className="mt-2 flex flex-wrap gap-1.5">
                            {[
                                { v: 'conservative', l: 'Konvensional' },
                                { v: 'ai_assisted', l: 'AI-assisted' },
                                { v: 'vibe', l: 'Vibe / AI-first' },
                            ].map((o) => (
                                <button key={o.v} type="button" className={chip(data.work_mode === o.v)} onClick={() => setData('work_mode', o.v)}>
                                    {o.l}
                                </button>
                            ))}
                        </div>
                        <div className="mt-2 rounded-lg bg-gray-50 px-3 py-2.5 text-[11.5px] leading-relaxed font-medium text-gray-500">
                            {data.work_mode === 'conservative' && (
                                <>
                                    <b className="text-gray-700">Konvensional</b> — tim koding manual. Estimasi man-days baseline penuh (1.0×),
                                    confidence ±15%.
                                </>
                            )}
                            {data.work_mode === 'ai_assisted' && (
                                <>
                                    <b className="text-gray-700">AI-assisted</b> — tim pakai Copilot/AI sebagai alat bantu. Porsi implementasi (FE+BE)
                                    dihitung 0.6× baseline; QA & PM tetap penuh. Confidence ±20%.
                                </>
                            )}
                            {data.work_mode === 'vibe' && (
                                <>
                                    <b className="text-gray-700">Vibe / AI-first</b> — AI menulis mayoritas kode. Porsi implementasi 0.4× baseline; QA
                                    & PM tetap penuh — review kode AI tidak ikut cepat. Confidence melebar ±25%.
                                </>
                            )}{' '}
                            Estimasi menampilkan baseline konvensional & angka mode ini berdampingan.
                        </div>
                    </div>
                    <div className="mt-4">
                        <div className={sectionLabel}>Template</div>
                        <div className="mt-2 flex flex-wrap gap-1.5">
                            {[
                                { v: 'workspace', l: 'Standar workspace' },
                                { v: 'default', l: 'Default' },
                            ].map((o) => (
                                <button key={o.v} type="button" className={chip(data.template === o.v)} onClick={() => setData('template', o.v)}>
                                    {o.l}
                                </button>
                            ))}
                        </div>
                    </div>
                    <div className="mt-4">
                        <div className={sectionLabel}>Mode</div>
                        <div className="mt-2 flex flex-wrap gap-1.5">
                            <button type="button" className={chip(true)}>
                                Greenfield (baru)
                            </button>
                            <button
                                type="button"
                                disabled
                                className="rounded-full border border-gray-200 bg-white px-3 py-1.5 text-[12px] font-bold text-gray-400"
                                title="Segera"
                            >
                                Brownfield — connect repo
                            </button>
                        </div>
                    </div>
                    <div className="mt-4 border-t border-gray-100 pt-3.5 text-[11.5px] leading-normal font-medium text-gray-400">
                        Nama proyek & klien diisi otomatis oleh AI — bisa diubah di langkah berikutnya. Pertanyaan yang di-skip menjadi asumsi
                        eksplisit yang tercetak di PRD — melindungi Anda saat dispute scope.
                    </div>
                </div>
            </div>

            <div className="mt-5 flex justify-end">
                <button className={btn} disabled={processing} onClick={() => void submitInput()}>
                    {processing && <Spinner stroke="#fff" />}
                    {processing ? 'Menganalisa…' : '✦ Analisa dengan AI'}
                </button>
            </div>
        </div>
    );
}

// ---------- shared: kartu pemahaman AI (dipakai step 2 editable + step 3 read-only) ----------
function UnderstandingCard({
    u,
    editable,
    data,
    setData,
}: {
    u: Understanding;
    editable: boolean;
    data?: { roles: { name: string; note: string }[]; features: { title: string; quote: string }[]; complexity: number };
    setData?: (k: 'roles' | 'features' | 'complexity', v: unknown) => void;
}) {
    const roles = editable && data ? data.roles : u.roles;
    const features = editable && data ? data.features : u.features;
    const complexity = editable && data ? data.complexity : u.complexity;

    return (
        <div className="rounded-xl border border-gray-200 bg-white p-[18px]">
            <div className="flex items-center justify-between gap-2.5">
                <div className="flex items-center gap-2 text-[15px] font-bold text-gray-800">
                    <svg
                        width="18"
                        height="18"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="#0D9488"
                        strokeWidth="2.2"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                    >
                        <path d="M12 5a3 3 0 1 0-5.997.125 4 4 0 0 0-2.526 5.77 4 4 0 0 0 .556 6.588A4 4 0 1 0 12 18Z" />
                        <path d="M12 5a3 3 0 1 1 5.997.125 4 4 0 0 1 2.526 5.77 4 4 0 0 1-.556 6.588A4 4 0 1 1 12 18Z" />
                    </svg>
                    Pemahaman AI
                </div>
                <span className="rounded-full border border-gray-200 bg-gray-50 px-2.5 py-[3px] text-[11px] font-bold text-gray-500">
                    {u.domain ?? '—'}
                </span>
            </div>

            <div className={`${sectionLabel} mt-4`}>User roles</div>
            <div className="mt-[7px] flex flex-wrap gap-1.5">
                {roles.map((r, i) => (
                    <span
                        key={i}
                        className="inline-flex items-center gap-1.5 rounded-full border-[1.5px] border-teal-600 bg-teal-50 px-3 py-[5px] text-xs font-bold text-teal-800"
                    >
                        {r.name}
                        {editable && setData && (
                            <button
                                className="text-teal-400 hover:text-red-500"
                                onClick={() =>
                                    setData(
                                        'roles',
                                        roles.filter((_, j) => j !== i),
                                    )
                                }
                            >
                                ✕
                            </button>
                        )}
                    </span>
                ))}
                {editable && setData && (
                    <button
                        className="rounded-full border-[1.5px] border-dashed border-gray-200 bg-white px-3 py-[5px] text-xs font-semibold text-gray-400 hover:border-teal-400 hover:text-teal-700"
                        onClick={async () => {
                            const name = await promptDialog('Nama role baru:');
                            if (name) setData('roles', [...roles, { name, note: '' }]);
                        }}
                    >
                        + tambah role
                    </button>
                )}
            </div>

            <div className={`${sectionLabel} mt-4`}>Fitur terdeteksi</div>
            <div className="mt-2 flex flex-col gap-[7px]">
                {features.map((f, i) => (
                    <div key={i} className="flex items-center gap-2 text-[13px] font-semibold text-gray-700">
                        <CheckCircle />
                        {editable && setData ? (
                            <>
                                <input
                                    className="min-w-0 flex-1 border-0 bg-transparent p-0 font-semibold focus:outline-none"
                                    value={f.title}
                                    onChange={(e) =>
                                        setData(
                                            'features',
                                            features.map((x, j) => (j === i ? { ...x, title: e.target.value } : x)),
                                        )
                                    }
                                />
                                <button
                                    className="text-xs font-bold text-gray-300 hover:text-red-500"
                                    onClick={() =>
                                        setData(
                                            'features',
                                            features.filter((_, j) => j !== i),
                                        )
                                    }
                                >
                                    ✕
                                </button>
                            </>
                        ) : (
                            <>
                                <span className="min-w-0 flex-1 truncate">{f.title}</span>
                                {f.quote && (
                                    <span className="ml-auto max-w-[40%] truncate font-mono text-[10.5px] font-semibold text-gray-400">
                                        "{f.quote}"
                                    </span>
                                )}
                            </>
                        )}
                    </div>
                ))}
                {editable && setData && (
                    <button
                        className="rounded-lg border-2 border-dashed border-gray-200 py-1.5 text-xs font-bold text-gray-400 hover:border-teal-400 hover:text-teal-700"
                        onClick={() => setData('features', [...features, { title: 'Fitur baru', quote: '' }])}
                    >
                        + Tambah fitur
                    </button>
                )}
            </div>

            <div className={`${sectionLabel} mt-4`}>Kompleksitas</div>
            <div className="mt-2 flex items-center gap-2.5 text-xs font-semibold text-gray-500">
                <span>Kecil</span>
                <div className="flex h-1.5 flex-1 gap-0.5">
                    {[1, 2, 3, 4, 5].map((c) => (
                        <button
                            key={c}
                            disabled={!editable}
                            onClick={() => editable && setData && setData('complexity', c)}
                            className={`flex-1 rounded-full ${c <= complexity ? 'bg-teal-600' : 'bg-gray-200'} ${editable ? 'cursor-pointer hover:bg-teal-400' : ''}`}
                            title={`Kompleksitas ${c}`}
                        />
                    ))}
                </div>
                <span>Enterprise</span>
            </div>
            {editable && <div className="mt-1.5 text-[11px] text-gray-400">Menentukan jumlah dokumen (6–14) dan kelas arsitektur (BR-16).</div>}

            {u.assumptions.length > 0 && (
                <div className="mt-4 rounded-[10px] border-l-[3px] border-amber-500 bg-amber-100/60 px-3.5 py-3">
                    <div className="flex items-center gap-[7px] text-[11px] font-bold tracking-[0.08em] text-amber-800">
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
                            <path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z" />
                            <line x1="12" y1="9" x2="12" y2="13" />
                            <line x1="12" y1="17" x2="12.01" y2="17" />
                        </svg>
                        ASUMSI AWAL
                    </div>
                    <div className="mt-1.5 text-[12.5px] leading-relaxed font-medium text-amber-800">
                        {u.assumptions.map((a, i) => (
                            <div key={i}>· {a}</div>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}

// ---------- STEP 2: UNDERSTANDING ----------
function StepUnderstanding({ project, understanding }: Pick<Props, 'project' | 'understanding'>) {
    const u = understanding!;
    const { data, setData, post, processing } = useForm({
        name: project.name,
        client_name: project.client_name ?? '',
        roles: u.roles ?? [],
        features: u.features ?? [],
        domain: u.domain ?? '',
        complexity: u.complexity ?? 3,
        assumptions: u.assumptions ?? [],
    });

    return (
        // ponytail: form step tetap dibatasi 960px — input full width susah dibaca
        <div className="mx-auto max-w-[960px]">
            {/* nama auto dari AI — koreksi di sini; klien opsional */}
            <div className="mb-4 grid gap-3 rounded-xl border border-gray-200 bg-white p-[18px] sm:grid-cols-2">
                <div>
                    <div className={sectionLabel}>Nama proyek</div>
                    <input className={`${field} mt-[7px]`} value={data.name} onChange={(e) => setData('name', e.target.value)} />
                </div>
                <div>
                    <div className={sectionLabel}>Nama klien (opsional)</div>
                    <input
                        className={`${field} mt-[7px]`}
                        placeholder="PT Maju Jaya"
                        value={data.client_name}
                        onChange={(e) => setData('client_name', e.target.value)}
                    />
                </div>
            </div>
            {/* kontradiksi di input — non-blocking: perbaiki input, atau lanjut dan diklarifikasi di interview */}
            {(u.contradictions ?? []).length > 0 && (
                <div className="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-[14px]">
                    <div className="text-[12px] font-extrabold text-amber-800">⚠ Input memuat pernyataan yang saling bertentangan</div>
                    <ul className="mt-1.5 flex flex-col gap-1 text-[12px] font-medium text-amber-800">
                        {(u.contradictions ?? []).map((c, i) => (
                            <li key={i}>• {c}</li>
                        ))}
                    </ul>
                    <div className="mt-1.5 text-[11px] font-semibold text-amber-600">
                        Perbaiki input di step sebelumnya, atau lanjut — tiap poin otomatis jadi pertanyaan interview.
                    </div>
                </div>
            )}
            <UnderstandingCard u={u} editable data={data} setData={setData as (k: 'roles' | 'features' | 'complexity', v: unknown) => void} />
            <div className="mt-4 flex justify-end">
                <button
                    className={btn}
                    disabled={processing || data.features.length === 0}
                    onClick={() => post(route('wizard.understanding', project.id))}
                >
                    {processing && <Spinner stroke="#fff" />}
                    {processing ? 'Menyiapkan interview…' : 'Konfirmasi & lanjut →'}
                </button>
            </div>
        </div>
    );
}

// ---------- STEP 3: INTERVIEW ----------
function StepInterview({ project, interview, understanding, step_job }: Pick<Props, 'project' | 'interview' | 'understanding' | 'step_job'>) {
    const pending = interview.filter((i) => !i.answer_text && !i.skipped);
    const current = pending[0];
    const next = pending[1];
    const answered = interview.length - pending.length;
    const [answer, setAnswer] = useState('');
    const [localBusy, setLocalBusy] = useState(false);
    const [finishing, setFinishing] = useState(false);
    // buildStructure berjalan async di WizardStepJob — poll sampai wizard_step maju
    const job = useStepJob(step_job, 'structure');
    const structuring = finishing || job.active;
    const busy = localBusy || structuring;

    const submit = (skip: boolean) => {
        if (!current) return;
        setLocalBusy(true);
        router.post(
            route('wizard.interview.answer', project.id),
            { seq: current.seq, answer: skip ? null : answer, skip },
            {
                onFinish: () => {
                    setAnswer('');
                    setLocalBusy(false);
                },
                preserveScroll: true,
            },
        );
    };

    const finish = (skipAll: boolean) => {
        if (busy) return;
        setFinishing(true);
        router.post(route('wizard.interview.finish', project.id), { skip_all: skipAll }, { onFinish: () => setFinishing(false) });
    };

    return (
        <div className="grid items-start gap-[18px] lg:grid-cols-2">
            {understanding && <UnderstandingCard u={understanding} editable={false} />}

            <div>
                <div className="mb-2 flex items-center justify-between">
                    <div className="text-[15px] font-bold text-gray-800">
                        Pertanyaan {Math.min(answered + 1, interview.length)} dari {interview.length}
                    </div>
                    <button
                        onClick={() => finish(true)}
                        disabled={busy}
                        className="inline-flex items-center gap-1.5 text-xs font-semibold text-teal-500 underline hover:text-teal-800 disabled:opacity-50"
                    >
                        {structuring && <Spinner />}
                        {structuring ? 'Menyusun struktur…' : 'Lewati semua → jadi asumsi'}
                    </button>
                </div>
                <div className="mb-3.5 h-1.5 overflow-hidden rounded-full bg-gray-200">
                    <div
                        className="h-full rounded-full bg-teal-600 transition-all"
                        style={{ width: `${(answered / Math.max(interview.length, 1)) * 100}%` }}
                    />
                </div>

                {current ? (
                    <>
                        <div className="rounded-xl border border-gray-200 bg-white p-4">
                            <div className="text-sm font-bold text-gray-800">{current.question}</div>
                            {current.reason && <div className="mt-[3px] text-[11.5px] font-medium text-gray-400">{current.reason}</div>}
                            <div className="mt-3 flex flex-col gap-[7px]">
                                {current.options?.map((o) => {
                                    const on = answer === o;
                                    return (
                                        <button
                                            key={o}
                                            onClick={() => setAnswer(o)}
                                            className={`flex items-center gap-2.5 rounded-[10px] border-2 px-3 py-2.5 text-left text-[13px] ${
                                                on
                                                    ? 'border-teal-600 bg-teal-50 font-bold text-teal-900'
                                                    : 'border-gray-200 font-medium text-gray-600 hover:border-teal-300'
                                            }`}
                                        >
                                            <span
                                                className={`h-[15px] w-[15px] flex-none rounded-full border-2 ${on ? 'border-teal-600 bg-teal-600' : 'border-gray-400'}`}
                                            />
                                            {o}
                                        </button>
                                    );
                                })}
                                <textarea
                                    className={`${field} min-h-[60px]`}
                                    placeholder={current.options?.length ? 'Atau jawaban lain…' : 'Jawaban Anda…'}
                                    value={current.options?.includes(answer) ? '' : answer}
                                    onChange={(e) => setAnswer(e.target.value)}
                                />
                            </div>
                        </div>

                        {next && (
                            <div className="mt-3 rounded-xl border border-gray-200 bg-white p-4 opacity-45">
                                <div className="text-sm font-bold text-gray-800">{next.question}</div>
                                {next.options?.slice(0, 2).map((o) => (
                                    <div
                                        key={o}
                                        className="mt-[7px] flex items-center gap-2.5 rounded-[10px] border-2 border-gray-200 px-3 py-2.5 text-[13px] text-gray-600"
                                    >
                                        <span className="h-[15px] w-[15px] flex-none rounded-full border-2 border-gray-400" />
                                        {o}
                                    </div>
                                ))}
                            </div>
                        )}

                        {job.error && (
                            <div className="mt-4 rounded-lg border border-red-200 bg-red-50 p-3 text-[13px] font-semibold text-red-700">
                                {job.error}
                            </div>
                        )}
                        <div className="mt-4 flex justify-end gap-2.5">
                            <button className={btnGhost} disabled={busy} onClick={() => submit(true)}>
                                Lewati → asumsi
                            </button>
                            <button className={btn} disabled={busy || !answer.trim()} onClick={() => submit(false)}>
                                Jawab & lanjut →
                            </button>
                        </div>
                    </>
                ) : (
                    <>
                        <div className="rounded-xl border border-teal-200 bg-teal-50 p-5 text-sm font-semibold text-teal-800">
                            Semua pertanyaan selesai. Lanjut menyusun struktur proyek.
                        </div>
                        {job.error && (
                            <div className="mt-4 rounded-lg border border-red-200 bg-red-50 p-3 text-[13px] font-semibold text-red-700">
                                {job.error}
                            </div>
                        )}
                        <div className="mt-4 flex justify-end">
                            <button className={btn} disabled={busy} onClick={() => finish(false)}>
                                {structuring && <Spinner stroke="#fff" />}
                                {structuring ? 'Menyusun struktur…' : job.error ? 'Coba lagi →' : 'Susun struktur →'}
                            </button>
                        </div>
                    </>
                )}
            </div>
        </div>
    );
}

// ---------- STEP 4: STRUCTURE ----------
export function StepStructure({
    project,
    nodes,
    step_job,
    fullHeight = false,
}: Pick<Props, 'project' | 'nodes'> & { step_job?: StepJob; fullHeight?: boolean }) {
    const phases = nodes.filter((n) => n.kind === 'phase');
    const root = nodes.find((n) => n.kind === 'root');
    const [scopeMode, setScopeMode] = useState(project.scope_mode);
    const [posting, setPosting] = useState(false);
    // recommendStack berjalan async di WizardStepJob — poll sampai wizard_step maju
    const job = useStepJob(step_job, 'stack');
    const confirming = posting || job.active;

    const featuresOf = (pid: string) => nodes.filter((n) => n.parent_id === pid && n.kind === 'feature' && n.scope !== 'parked');

    // ---- limit sub-fitur tampil per kartu (estimasi tetap hitung semua) ----
    const SUB_LIMIT = 3;
    const [expandedSubs, setExpandedSubs] = useState<Record<string, boolean>>({});
    const [detail, setDetail] = useState<Node | null>(null);
    const visibleSubsOf = (fid: string) => (expandedSubs[fid] ? subsOf(fid) : subsOf(fid).slice(0, SUB_LIMIT));
    const hiddenSubsOf = (fid: string) => Math.max(subsOf(fid).length - SUB_LIMIT, 0);
    const subsOf = (fid: string) => nodes.filter((n) => n.parent_id === fid && n.kind === 'subfeature');
    const mdOf = (f: Node) => {
        const subs = subsOf(f.id);
        return subs.length ? subs.reduce((a, s) => a + Number(s.est_md), 0) : Number(f.est_md);
    };
    const inScope = (f: Node) => scopeMode === 'full' || f.scope === 'mvp';
    const totalMd = phases
        .flatMap((p) => featuresOf(p.id))
        .filter(inScope)
        .reduce((a, f) => a + mdOf(f), 0);
    const phaseMd = (p: Node) =>
        featuresOf(p.id)
            .filter(inScope)
            .reduce((a, f) => a + mdOf(f), 0);

    const toggleScope = (f: Node) =>
        router.patch(route('wizard.nodes.update', [project.id, f.id]), { scope: f.scope === 'mvp' ? 'full' : 'mvp' }, { preserveScroll: true });

    // ---- collapse sub-fitur per kartu + collapse all ----
    const [allCollapsed, setAllCollapsed] = useState(false);
    const [collapsedOverride, setCollapsedOverride] = useState<Record<string, boolean>>({});
    const isCollapsed = (id: string) => collapsedOverride[id] ?? allCollapsed;
    const toggleCollapse = (id: string) => setCollapsedOverride((prev) => ({ ...prev, [id]: !isCollapsed(id) }));
    const toggleAll = () => {
        setAllCollapsed((v) => !v);
        setCollapsedOverride({});
    };

    // ---- layout mind map: root → fase (kolom tengah) → fitur (kolom kanan) ----
    const CARD_W = 200;
    const featH = (f: Node) =>
        66 + (!isCollapsed(f.id) && subsOf(f.id).length ? 24 + visibleSubsOf(f.id).length * 25 + (hiddenSubsOf(f.id) ? 24 : 0) : 0);
    const layout = useMemo(() => {
        const pos: Record<string, { x: number; y: number }> = {};
        let y = 70;
        for (const p of phases) {
            const feats = featuresOf(p.id);
            const blockTop = y;
            for (const f of feats) {
                pos[f.id] = { x: 620, y };
                y += featH(f) + 16;
            }
            const blockH = Math.max(y - blockTop - 16, 60);
            pos[p.id] = { x: 330, y: blockTop + blockH / 2 - 30 };
            y += 34; // jarak antar fase
        }
        pos.__root = { x: 40, y: Math.max((y - 34) / 2 - 30, 70) };
        return { pos, height: Math.max(y + 60, 560) };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [nodes, allCollapsed, collapsedOverride, expandedSubs]);

    // posisi hasil drag menimpa auto-layout; node baru tetap dapat posisi otomatis
    const [dragged, setDragged] = useState<Record<string, { x: number; y: number }>>({});
    const drag = useRef<{ id: string; sx: number; sy: number; ox: number; oy: number } | null>(null);
    const posOf = (id: string) => dragged[id] ?? layout.pos[id] ?? { x: 0, y: 0 };

    // ---- kamera free canvas: translate + scale, pan bebas segala arah ----
    const [cam, setCam] = useState({ x: 24, y: 24, z: 1 });
    const camTarget = useRef({ x: 24, y: 24, z: 1 });
    const viewRef = useRef<HTMLDivElement>(null);
    const clampZ = (z: number) => Math.min(1.5, Math.max(0.2, z));

    const contentW = useMemo(() => {
        const xs = [...Object.values(layout.pos), ...Object.values(dragged)].map((p) => p.x);
        return Math.max(xs.length ? Math.max(...xs) + CARD_W + 80 : 0, 880);
    }, [layout, dragged]);

    const zoomAt = (cx: number, cy: number, nz: number) => {
        const t = camTarget.current;
        nz = clampZ(nz);
        camTarget.current = { x: cx - ((cx - t.x) / t.z) * nz, y: cy - ((cy - t.y) / t.z) * nz, z: nz };
    };
    const zoomBy = (f: number) => {
        const el = viewRef.current;
        if (el) zoomAt(el.clientWidth / 2, el.clientHeight / 2, camTarget.current.z * f);
    };
    const fitScreen = () => {
        const el = viewRef.current;
        if (!el) return;
        const z = clampZ(Math.min((el.clientWidth - 48) / contentW, (el.clientHeight - 48) / layout.height));
        camTarget.current = { x: Math.max((el.clientWidth - contentW * z) / 2, 12), y: Math.max((el.clientHeight - layout.height * z) / 2, 12), z };
    };

    // scroll wheel = zoom ke arah kursor (listener non-passive — onWheel React tidak bisa preventDefault)
    useEffect(() => {
        const el = viewRef.current;
        if (!el) return;
        const onWheel = (e: WheelEvent) => {
            e.preventDefault();
            const rect = el.getBoundingClientRect();
            zoomAt(e.clientX - rect.left, e.clientY - rect.top, camTarget.current.z * (e.deltaY < 0 ? 1.1 : 1 / 1.1));
        };
        el.addEventListener('wheel', onWheel, { passive: false });
        return () => el.removeEventListener('wheel', onWheel);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // ---- rAF lerp: kartu, wire, dan kamera mengejar target bareng tiap frame ----
    const [anim, setAnim] = useState<Record<string, { x: number; y: number }>>({});
    const targetsRef = useRef<Record<string, { x: number; y: number }>>({});
    targetsRef.current = { ...layout.pos, ...dragged };
    useEffect(() => {
        let raf = 0;
        const tick = () => {
            setCam((prev) => {
                const t = camTarget.current;
                const dx = t.x - prev.x;
                const dy = t.y - prev.y;
                const dz = t.z - prev.z;
                if (Math.abs(dx) < 0.5 && Math.abs(dy) < 0.5 && Math.abs(dz) < 0.002)
                    return prev.x === t.x && prev.y === t.y && prev.z === t.z ? prev : t;
                return { x: prev.x + dx * 0.3, y: prev.y + dy * 0.3, z: prev.z + dz * 0.3 };
            });
            setAnim((prev) => {
                const t = targetsRef.current;
                let changed = Object.keys(prev).length !== Object.keys(t).length;
                const next: typeof prev = {};
                for (const id in t) {
                    const cur = prev[id];
                    if (!cur) {
                        next[id] = t[id];
                        changed = true;
                        continue;
                    }
                    const dx = t[id].x - cur.x;
                    const dy = t[id].y - cur.y;
                    if (Math.abs(dx) < 0.5 && Math.abs(dy) < 0.5) {
                        next[id] = cur.x === t[id].x && cur.y === t[id].y ? cur : t[id];
                        if (next[id] !== cur) changed = true;
                    } else {
                        next[id] = { x: cur.x + dx * 0.3, y: cur.y + dy * 0.3 };
                        changed = true;
                    }
                }
                return changed ? next : prev;
            });
            raf = requestAnimationFrame(tick);
        };
        raf = requestAnimationFrame(tick);
        return () => cancelAnimationFrame(raf);
    }, []);
    const animOf = (id: string) => anim[id] ?? posOf(id);

    // klik area kosong = pan canvas (lewat camTarget, ikut di-lerp)
    const pan = useRef<{ sx: number; sy: number; ox: number; oy: number } | null>(null);

    const startDrag = (id: string) => (e: React.MouseEvent) => {
        if ((e.target as HTMLElement).closest('button')) return; // tombol dalam kartu tetap klik biasa
        const p = posOf(id);
        drag.current = { id, sx: e.clientX, sy: e.clientY, ox: p.x, oy: p.y };
    };
    const startPan = (e: React.MouseEvent) => {
        if ((e.target as HTMLElement).closest('[data-node],button')) return;
        const t = camTarget.current;
        pan.current = { sx: e.clientX, sy: e.clientY, ox: t.x, oy: t.y };
    };
    const onMove = (e: React.MouseEvent) => {
        const d = drag.current;
        if (d) {
            const z = camTarget.current.z;
            setDragged((prev) => ({
                ...prev,
                [d.id]: { x: Math.max(0, d.ox + (e.clientX - d.sx) / z), y: Math.max(0, d.oy + (e.clientY - d.sy) / z) },
            }));
            return;
        }
        const p = pan.current;
        if (p) camTarget.current = { ...camTarget.current, x: p.ox + (e.clientX - p.sx), y: p.oy + (e.clientY - p.sy) };
    };
    const endDrag = () => {
        drag.current = null;
        pan.current = null;
    };

    const wire = (a: { x: number; y: number }, b: { x: number; y: number }, dy1 = 30, dy2 = 30) => {
        const x1 = a.x + CARD_W,
            y1 = a.y + dy1,
            x2 = b.x,
            y2 = b.y + dy2;
        return `M ${x1} ${y1} C ${x1 + 55} ${y1}, ${x2 - 55} ${y2}, ${x2} ${y2}`;
    };

    const toolBtn = 'rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-bold text-gray-700 shadow-sm hover:bg-gray-50';
    const card = 'absolute w-[200px] rounded-xl border bg-white shadow-[0_2px_6px_rgba(0,0,0,0.06)]';

    return (
        <div className={fullHeight ? 'flex h-full flex-col bg-white' : 'overflow-hidden rounded-xl border border-gray-200 bg-white'}>
            <div className={fullHeight ? 'relative min-h-0 flex-1' : 'relative'}>
                <div
                    ref={viewRef}
                    className={`relative cursor-grab overflow-hidden select-none ${fullHeight ? 'h-full' : 'h-[560px]'}`}
                    style={{
                        backgroundImage: 'radial-gradient(#E5E7EB 1px,transparent 1px)',
                        backgroundSize: `${22 * cam.z}px ${22 * cam.z}px`,
                        backgroundPosition: `${cam.x}px ${cam.y}px`,
                    }}
                    onMouseDown={startPan}
                    onMouseMove={onMove}
                    onMouseUp={endDrag}
                    onMouseLeave={endDrag}
                >
                    <div
                        className="absolute top-0 left-0 origin-top-left"
                        style={{ width: contentW, height: layout.height, transform: `translate(${cam.x}px, ${cam.y}px) scale(${cam.z})` }}
                    >
                        {/* wires */}
                        <svg className="pointer-events-none absolute inset-0 h-full w-full">
                            {phases.map((p) => (
                                <path key={p.id} d={wire(animOf('__root'), animOf(p.id), 34, 30)} stroke="#99F6E4" strokeWidth="2" fill="none" />
                            ))}
                            {phases.flatMap((p) =>
                                featuresOf(p.id).map((f) => (
                                    <path
                                        key={f.id}
                                        d={wire(animOf(p.id), animOf(f.id), 30, 32)}
                                        stroke={inScope(f) ? '#99F6E4' : '#FDE68A'}
                                        strokeWidth="2"
                                        fill="none"
                                    />
                                )),
                            )}
                        </svg>

                        {/* node root */}
                        <div
                            data-node
                            className={`${card} cursor-grab border-2 border-teal-600 px-3.5 py-3 shadow-[0_2px_8px_rgba(13,148,136,0.12)] active:cursor-grabbing`}
                            style={{ left: animOf('__root').x, top: animOf('__root').y }}
                            onMouseDown={startDrag('__root')}
                        >
                            <div className="text-[13px] font-extrabold text-gray-900">{project.name}</div>
                            <div className="mt-0.5 text-[11px] font-medium text-gray-400">
                                Perencanaan · {phases.length} fase · {phases.flatMap((p) => featuresOf(p.id)).length} fitur
                            </div>
                        </div>

                        {/* node fase */}
                        {phases.map((p, pi) => (
                            <div
                                key={p.id}
                                data-node
                                className={`${card} cursor-grab border-gray-200 px-3.5 py-3 active:cursor-grabbing`}
                                style={{ left: animOf(p.id).x, top: animOf(p.id).y }}
                                onMouseDown={startDrag(p.id)}
                            >
                                <span className="absolute -top-[9px] right-2.5 rounded-[5px] bg-teal-600 px-[7px] py-0.5 text-[9px] font-extrabold tracking-[0.05em] text-white">
                                    FASE {p.phase_no ?? pi + 1}
                                </span>
                                <div className="text-[13px] font-bold text-gray-800">{p.title}</div>
                                <div className="mt-0.5 flex items-center justify-between font-mono text-[11px] font-semibold text-teal-700">
                                    est. {phaseMd(p).toFixed(0)} MD
                                    <button
                                        className="rounded border border-gray-200 px-1.5 font-sans text-[11px] font-bold text-gray-500 hover:bg-gray-50"
                                        title="Tambah fitur di fase ini"
                                        onClick={async () => {
                                            const title = await promptDialog('Nama fitur baru:');
                                            if (title)
                                                router.post(
                                                    route('wizard.nodes.store', project.id),
                                                    { parent_id: p.id, kind: 'feature', title, est_md: 5 },
                                                    { preserveScroll: true },
                                                );
                                        }}
                                    >
                                        + Fitur
                                    </button>
                                </div>
                            </div>
                        ))}

                        {/* node fitur */}
                        {phases.flatMap((p) =>
                            featuresOf(p.id).map((f) => (
                                <div
                                    key={f.id}
                                    data-node
                                    className={`${card} cursor-grab border-gray-200 px-3 py-2.5 active:cursor-grabbing ${inScope(f) ? '' : 'opacity-40'}`}
                                    style={{ left: animOf(f.id).x, top: animOf(f.id).y }}
                                    onMouseDown={startDrag(f.id)}
                                >
                                    <div className="flex items-center gap-2">
                                        <button
                                            onClick={() => toggleScope(f)}
                                            title="Klik untuk toggle MVP/Full"
                                            className={`rounded-full px-2 py-0.5 text-[9px] font-extrabold ${
                                                f.scope === 'mvp' ? 'bg-teal-100 text-teal-800' : 'bg-amber-100 text-amber-700'
                                            }`}
                                        >
                                            {f.scope === 'mvp' ? 'MVP' : 'POST-MVP'}
                                        </button>
                                        <span
                                            className="min-w-0 flex-1 cursor-pointer truncate text-[12.5px] font-bold text-gray-800 hover:text-teal-700"
                                            title={f.title + (f.description ? ` — ${f.description}` : '')}
                                            onClick={() => setDetail(f)}
                                        >
                                            {f.title}
                                        </span>
                                        {subsOf(f.id).length > 0 && (
                                            <button
                                                className="text-gray-400 hover:text-teal-700"
                                                title={isCollapsed(f.id) ? 'Buka sub-fitur' : 'Tutup sub-fitur'}
                                                onClick={() => toggleCollapse(f.id)}
                                            >
                                                <svg
                                                    width="13"
                                                    height="13"
                                                    viewBox="0 0 24 24"
                                                    fill="none"
                                                    stroke="currentColor"
                                                    strokeWidth="2.4"
                                                    strokeLinecap="round"
                                                    strokeLinejoin="round"
                                                    className={isCollapsed(f.id) ? '' : 'rotate-180'}
                                                >
                                                    <polyline points="6 9 12 15 18 9" />
                                                </svg>
                                            </button>
                                        )}
                                        <button
                                            className="text-xs text-gray-300 hover:text-red-500"
                                            title="Parkir ide (tidak dihapus permanen)"
                                            onClick={() => router.delete(route('wizard.nodes.destroy', [project.id, f.id]), { preserveScroll: true })}
                                        >
                                            ✕
                                        </button>
                                    </div>
                                    <div className="mt-1 font-mono text-[11px] font-semibold text-teal-700">
                                        est. {mdOf(f).toFixed(0)} MD
                                        {isCollapsed(f.id) && subsOf(f.id).length > 0 && (
                                            <span className="ml-1.5 text-gray-400">· {subsOf(f.id).length} sub</span>
                                        )}
                                    </div>
                                    {!isCollapsed(f.id) && subsOf(f.id).length > 0 && (
                                        <div className="mt-1.5 flex flex-col gap-1">
                                            <div className="text-[10px] font-bold tracking-[0.08em] text-gray-400">SUB-FITUR</div>
                                            {visibleSubsOf(f.id).map((s) => (
                                                <span
                                                    key={s.id}
                                                    className="cursor-pointer truncate rounded-md border border-gray-200 bg-gray-50 px-2 py-[3px] text-[11.5px] font-semibold text-gray-600 hover:border-teal-300 hover:bg-teal-50"
                                                    title={s.title + (s.description ? ` — ${s.description}` : '')}
                                                    onClick={() => setDetail(s)}
                                                >
                                                    {s.title} <span className="font-mono text-gray-400">({Number(s.est_md).toFixed(0)})</span>
                                                </span>
                                            ))}
                                            {hiddenSubsOf(f.id) > 0 && (
                                                <button
                                                    className="rounded-md border border-dashed border-teal-300 bg-teal-50/70 px-2 py-[3px] text-[11px] font-bold text-teal-700 hover:bg-teal-100"
                                                    onClick={() => setExpandedSubs((prev) => ({ ...prev, [f.id]: !prev[f.id] }))}
                                                >
                                                    {expandedSubs[f.id] ? 'Tampilkan lebih sedikit' : `Lihat semua · ${hiddenSubsOf(f.id)} lagi`}
                                                </button>
                                            )}
                                        </div>
                                    )}
                                </div>
                            )),
                        )}
                    </div>
                </div>

                {/* toolbar overlay (tidak ikut zoom) */}
                <div className="absolute top-3.5 left-3.5 z-[5] flex flex-wrap gap-2">
                    <button
                        className={toolBtn}
                        onClick={async () => {
                            const title = await promptDialog('Nama fase baru:');
                            if (title && root)
                                router.post(
                                    route('wizard.nodes.store', project.id),
                                    { parent_id: root.id, kind: 'phase', title },
                                    { preserveScroll: true },
                                );
                        }}
                    >
                        + Fase
                    </button>
                    <div className="flex overflow-hidden rounded-lg border border-gray-200 shadow-sm">
                        {['mvp', 'full'].map((m) => (
                            <button
                                key={m}
                                onClick={() => setScopeMode(m)}
                                className={`px-3 py-1.5 text-xs font-extrabold uppercase ${scopeMode === m ? 'bg-teal-600 text-white' : 'bg-white text-gray-500'}`}
                            >
                                {m}
                            </button>
                        ))}
                    </div>
                    <span className="rounded-lg border border-gray-200 bg-white px-3 py-1.5 font-mono text-xs font-semibold text-teal-800 shadow-sm">
                        Σ {totalMd.toFixed(0)} MD
                    </span>
                    <button className={toolBtn} onClick={toggleAll}>
                        {allCollapsed ? 'Buka semua' : 'Tutup semua'}
                    </button>
                </div>

                {/* zoom controls */}
                <div className="absolute right-4 bottom-4 z-[5] flex items-center overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                    <button
                        className="px-2.5 py-1.5 text-sm font-bold text-gray-600 hover:bg-gray-50"
                        title="Zoom out"
                        onClick={() => zoomBy(1 / 1.2)}
                    >
                        −
                    </button>
                    <span className="border-x border-gray-200 px-2 py-1.5 font-mono text-[11px] font-semibold text-gray-500">
                        {Math.round(cam.z * 100)}%
                    </span>
                    <button className="px-2.5 py-1.5 text-sm font-bold text-gray-600 hover:bg-gray-50" title="Zoom in" onClick={() => zoomBy(1.2)}>
                        +
                    </button>
                    <button className="border-l border-gray-200 px-2.5 py-1.5 text-gray-600 hover:bg-gray-50" title="Fit screen" onClick={fitScreen}>
                        <svg
                            width="14"
                            height="14"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            strokeWidth="2.2"
                            strokeLinecap="round"
                            strokeLinejoin="round"
                        >
                            <path d="M8 3H5a2 2 0 0 0-2 2v3" />
                            <path d="M21 8V5a2 2 0 0 0-2-2h-3" />
                            <path d="M3 16v3a2 2 0 0 0 2 2h3" />
                            <path d="M16 21h3a2 2 0 0 0 2-2v-3" />
                        </svg>
                    </button>
                </div>

                {/* popup detail node — klik judul fitur / chip sub-fitur */}
                {detail && (
                    <div className="absolute inset-0 z-20 flex items-center justify-center bg-gray-900/30" onClick={() => setDetail(null)}>
                        <div
                            className="w-[min(440px,90%)] rounded-xl border border-gray-200 bg-white p-5 shadow-2xl"
                            onClick={(e) => e.stopPropagation()}
                        >
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <div className="mb-1.5 flex flex-wrap items-center gap-1.5">
                                        <span className="rounded-full bg-gray-100 px-2 py-0.5 text-[9px] font-extrabold tracking-wide text-gray-500 uppercase">
                                            {detail.kind === 'subfeature' ? 'Sub-fitur' : 'Fitur'}
                                        </span>
                                        {detail.kind === 'feature' && (
                                            <span
                                                className={`rounded-full px-2 py-0.5 text-[9px] font-extrabold ${detail.scope === 'mvp' ? 'bg-teal-100 text-teal-800' : 'bg-amber-100 text-amber-700'}`}
                                            >
                                                {detail.scope === 'mvp' ? 'MVP' : 'POST-MVP'}
                                            </span>
                                        )}
                                    </div>
                                    <div className="text-[15px] leading-snug font-bold text-gray-900">{detail.title}</div>
                                </div>
                                <button className="text-gray-300 hover:text-gray-600" onClick={() => setDetail(null)}>
                                    ✕
                                </button>
                            </div>
                            {(() => {
                                const parent = nodes.find((n) => n.id === detail.parent_id);
                                const grand = parent && nodes.find((n) => n.id === parent.parent_id);
                                const path = [grand, parent].filter((n) => n && n.kind !== 'root').map((n) => n!.title);
                                return path.length > 0 && <div className="mt-1 text-[11.5px] font-medium text-gray-400">{path.join(' › ')}</div>;
                            })()}
                            <div className="mt-3 text-[13px] leading-relaxed text-gray-600">
                                {detail.description || <span className="text-gray-400 italic">Belum ada deskripsi untuk node ini.</span>}
                            </div>
                            <div className="mt-3.5 border-t border-gray-100 pt-3 font-mono text-[12px] font-semibold text-teal-700">
                                est. {Number(detail.est_md).toFixed(0)} MD
                            </div>
                        </div>
                    </div>
                )}
            </div>

            <div className="flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 bg-gray-50 px-[18px] py-3.5">
                <span className="flex items-center gap-[7px] text-[12.5px] font-medium text-gray-500">
                    <svg
                        width="15"
                        height="15"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="#0D9488"
                        strokeWidth="2.2"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                    >
                        <path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8" />
                        <path d="M21 3v5h-5" />
                        <path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16" />
                        <path d="M8 16H3v5" />
                    </svg>
                    Perubahan scope memicu hitung ulang estimasi otomatis
                </span>
                {project.wizard_step === 'structure' ? (
                    <span className="flex items-center gap-3">
                        {job.error && <span className="text-[12.5px] font-semibold text-red-600">{job.error}</span>}
                        <button
                            className={btn}
                            disabled={confirming}
                            onClick={() => {
                                setPosting(true);
                                router.post(
                                    route('wizard.structure.confirm', project.id),
                                    { scope_mode: scopeMode },
                                    { onFinish: () => setPosting(false) },
                                );
                            }}
                        >
                            {confirming && <Spinner stroke="#fff" />}
                            {confirming ? 'Menyiapkan rekomendasi stack…' : job.error ? 'Coba lagi →' : 'Lanjut ke stack →'}
                        </button>
                    </span>
                ) : (
                    <button className={btnGhost} onClick={() => router.visit(route('projects.show', project.id))}>
                        ← Kembali ke dokumen
                    </button>
                )}
            </div>
        </div>
    );
}

// ---------- STEP 5: STACK ----------
const STACK_OPTIONS: Record<string, string[]> = {
    auth: ['NextAuth.js (Auth.js)', 'Laravel Sanctum', 'Session + OAuth Google', 'Auth0', 'Clerk', 'Custom JWT'],
    backend: ['Laravel', 'Next.js API Routes + Prisma', 'Express + Prisma', 'NestJS', 'Django', 'Go (Fiber/Echo)'],
    database: ['PostgreSQL', 'MySQL', 'SQLite', 'MongoDB'],
    deploy: ['Vercel', 'VPS + Docker Compose', 'Railway/Fly.io', 'AWS (ECS/Fargate)', 'Supabase/Neon managed'],
    frontend: ['React + Inertia', 'Next.js + Tailwind', 'Vue + Inertia', 'Nuxt', 'SvelteKit'],
    payment: ['Midtrans', 'Xendit', 'Midtrans + Xendit', 'Stripe'],
};

export function StepStack({ project, stack, understanding }: Pick<Props, 'project' | 'stack' | 'understanding'>) {
    const arch = stack.find((s) => s.layer === 'backend');
    const alternatives = stack.flatMap((s) => s.alternatives ?? []);
    const [confirming, setConfirming] = useState(false);

    return (
        <div>
            <div className="mb-3.5 flex items-center justify-between gap-3">
                <h1 className="text-[22px] font-extrabold tracking-[-0.02em] text-gray-900">Rekomendasi stack dari AI</h1>
                <span className="rounded-full bg-amber-100 px-2.5 py-0.5 text-[10px] font-extrabold text-amber-700">✦ AI</span>
            </div>

            {arch && (
                <div className="mb-4 flex items-start gap-2.5 rounded-[10px] border-l-[3px] border-amber-500 bg-amber-100/60 px-4 py-3">
                    <svg
                        width="17"
                        height="17"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="#B45309"
                        strokeWidth="2.4"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        className="mt-px flex-none"
                    >
                        <path d="m16 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z" />
                        <path d="m2 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z" />
                        <path d="M7 21h10" />
                        <path d="M12 3v18" />
                        <path d="M3 7h2c2 0 5-1 7-2 2 1 5 2 7 2h2" />
                    </svg>
                    <div className="text-[13px] leading-relaxed font-medium text-amber-800">
                        <b className="font-bold">Complexity governor (BR-16):</b> kompleksitas proyek {understanding?.complexity ?? '—'}/5 — AI
                        merekomendasikan <b className="font-bold">{arch.choice}</b>. Arsitektur disesuaikan skala nyata klien, bukan template generik.
                    </div>
                </div>
            )}

            <div className="overflow-x-auto rounded-xl border border-gray-200 bg-white">
                <table className="w-full min-w-[640px] border-collapse text-[13px]">
                    <thead>
                        <tr>
                            <th className="w-[120px] border-b border-gray-200 bg-gray-50 px-4 py-2.5 text-left text-[10px] font-bold tracking-[0.08em] text-gray-500 uppercase">
                                Layer
                            </th>
                            <th className="w-[230px] border-b border-gray-200 bg-gray-50 px-4 py-2.5 text-left text-[10px] font-bold tracking-[0.08em] text-gray-500 uppercase">
                                Pilihan
                            </th>
                            <th className="border-b border-gray-200 bg-gray-50 px-4 py-2.5 text-left text-[10px] font-bold tracking-[0.08em] text-gray-500 uppercase">
                                Justifikasi
                            </th>
                            <th className="w-[100px] border-b border-gray-200 bg-gray-50" />
                        </tr>
                    </thead>
                    <tbody>
                        {stack.map((s) => (
                            <tr key={s.layer} className="border-b border-gray-100 hover:bg-gray-50">
                                <td className="px-4 py-3 font-bold text-gray-800 capitalize">{s.layer}</td>
                                <td className="px-4 py-3 font-mono text-[12.5px] font-semibold text-teal-800">
                                    {s.choice}
                                    {s.source === 'user' && (
                                        <span className="ml-2 rounded-full bg-blue-50 px-2 py-0.5 text-[10px] font-bold text-blue-600">USER</span>
                                    )}
                                </td>
                                <td className="px-4 py-3 font-medium text-gray-500">{s.justification}</td>
                                <td className="px-4 py-3">
                                    <button
                                        className="inline-flex items-center gap-1 rounded-lg border border-gray-200 bg-white px-2.5 py-[5px] text-xs font-bold text-gray-700 hover:bg-gray-100"
                                        onClick={async () => {
                                            const options = [
                                                ...new Set([
                                                    s.choice,
                                                    ...(s.alternatives ?? []).map((a) => a.choice),
                                                    ...(STACK_OPTIONS[s.layer] ?? []),
                                                ]),
                                            ];
                                            const choice = await selectDialog(`Override ${s.layer}:`, options, s.choice);
                                            if (choice && choice !== s.choice)
                                                router.patch(
                                                    route('wizard.stack.update', [project.id, s.layer]),
                                                    { choice },
                                                    { preserveScroll: true },
                                                );
                                        }}
                                    >
                                        Ubah
                                        <svg
                                            width="12"
                                            height="12"
                                            viewBox="0 0 24 24"
                                            fill="none"
                                            stroke="currentColor"
                                            strokeWidth="2.2"
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                        >
                                            <polyline points="6 9 12 15 18 9" />
                                        </svg>
                                    </button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {alternatives.length > 0 && (
                <div className="mt-3.5 flex flex-wrap items-center gap-2 text-[12.5px] text-gray-500">
                    Alternatif yang dipertimbangkan:
                    {alternatives.slice(0, 5).map((a, i) => (
                        <span
                            key={i}
                            className="rounded-full border border-gray-200 bg-white px-2.5 py-[3px] text-[11px] font-bold text-gray-600"
                            title={a.reason_rejected}
                        >
                            {a.choice}
                        </span>
                    ))}
                    — alasan tidak dipilih tercatat ADR-style di ARCHITECTURE.md
                </div>
            )}

            <div className="mt-5 flex justify-end">
                {project.wizard_step === 'stack' ? (
                    <button
                        className={btn}
                        disabled={confirming}
                        onClick={() => {
                            setConfirming(true);
                            router.post(route('wizard.stack.confirm', project.id), {}, { onFinish: () => setConfirming(false) });
                        }}
                    >
                        {confirming && <Spinner stroke="#fff" />}
                        {confirming ? 'Membuka generator…' : '✦ Generate dokumen'}
                    </button>
                ) : (
                    <button className={btnGhost} onClick={() => router.visit(route('projects.show', project.id))}>
                        ← Kembali ke dokumen
                    </button>
                )}
            </div>
        </div>
    );
}

// ---------- STEP 6: GENERATE ----------
function StepGenerate({ project, run, stream, credits, errors }: Pick<Props, 'project' | 'run' | 'stream' | 'credits' | 'errors'>) {
    const running = run && ['queued', 'running'].includes(run.status);
    const doneCount = run?.nodes.filter((n) => n.status === 'done').length ?? 0;
    const currentNode = run?.nodes.find((n) => n.status === 'running') ?? run?.nodes.find((n) => n.status === 'queued');
    const streamRef = useRef<HTMLDivElement>(null);
    const isWireframe = stream?.doc_key === 'WIREFRAMES';
    // Double submit generate = risiko dobel potong kredit (BR-02) — busy wajib
    const [starting, setStarting] = useState(false);
    const startRun = (routeName: 'projects.generate' | 'projects.generate.resume') => {
        if (starting) return;
        setStarting(true);
        router.post(route(routeName, project.id), {}, { onFinish: () => setStarting(false) });
    };

    // Typewriter: poll datang per ~1 dtk dalam gumpalan — reveal per karakter biar terasa live.
    // Init dengan teks stream saat mount: refresh halaman lanjut dari posisi sekarang, bukan replay dari awal
    const [shown, setShown] = useState(stream?.text ?? '');
    const targetRef = useRef('');
    useEffect(() => {
        targetRef.current = stream?.text ?? '';
    }, [stream?.text]);
    // Reset hanya saat GANTI dokumen — bukan saat mount (biar refresh tidak replay)
    const prevDocKey = useRef(stream?.doc_key);
    useEffect(() => {
        if (prevDocKey.current !== stream?.doc_key) setShown('');
        prevDocKey.current = stream?.doc_key;
    }, [stream?.doc_key]);
    useEffect(() => {
        if (!running || isWireframe) return;
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
    }, [running, isWireframe]);

    const streamHtml = useMemo(() => (shown && !isWireframe ? DOMPurify.sanitize(marked.parse(shown) as string) : ''), [shown, isWireframe]);
    // WIREFRAMES streaming JSON mentah — tampilkan layar yang sudah tergambar, bukan teks JSON
    const wfScreens = useMemo(() => {
        if (!isWireframe || !stream?.text) return [];
        // "name" & "flow" hanya ada di level screen (section pakai "title"/"label") — zip berdasarkan urutan
        const names = [...stream.text.matchAll(/"name"\s*:\s*"([^"]+)"/g)].map((m) => m[1]);
        const flows = [...stream.text.matchAll(/"flow"\s*:\s*"([^"]+)"/g)].map((m) => m[1]);
        return names.map((name, i) => ({ name, flow: flows[i] ?? '' }));
    }, [isWireframe, stream?.text]);

    useEffect(() => {
        if (!running) return;
        const t = setInterval(() => router.reload({ only: ['run', 'project', 'stream'] }), 1000);
        return () => clearInterval(t);
    }, [running]);

    // Baca dokumen selesai sambil nunggu sisanya — fetch on demand, cache per doc_key
    const [readDoc, setReadDoc] = useState<{ key: string; md: string | null } | null>(null);
    const docCache = useRef<Record<string, string>>({});
    const openDoc = async (key: string) => {
        setReadDoc({ key, md: docCache.current[key] ?? null });
        if (docCache.current[key] !== undefined) return;
        const r = await fetch(route('projects.documents.show', [project.id, key]), { headers: { Accept: 'application/json' } });
        const md = ((await r.json()) as { content_md: string }).content_md;
        docCache.current[key] = md;
        setReadDoc((cur) => (cur?.key === key ? { key, md } : cur));
    };

    // Deteksi worker mati: run "running" tapi stream & progress node diam >90 dtk
    const [stale, setStale] = useState(false);
    const staleDone = run?.nodes.filter((n) => n.status === 'done').length ?? 0;
    const lastProgress = useRef(Date.now());
    useEffect(() => {
        lastProgress.current = Date.now();
        setStale(false);
    }, [stream?.text?.length, staleDone, running]);
    useEffect(() => {
        if (!running) return;
        const t = setInterval(() => setStale(Date.now() - lastProgress.current > 90_000), 5000);
        return () => clearInterval(t);
    }, [running]);

    // auto-scroll ke bawah saat teks stream bertambah
    useEffect(() => {
        streamRef.current?.scrollTo({ top: streamRef.current.scrollHeight });
    }, [shown.length, stream?.text?.length]);

    useEffect(() => {
        if (run?.status === 'done') router.visit(route('projects.show', project.id));
    }, [run?.status, project.id]);

    return (
        <>
            <div className="flex overflow-hidden rounded-xl border border-gray-200 bg-white">
                {/* pane kiri: checklist dokumen */}
                <div className="w-[300px] flex-none border-r border-gray-200 p-[18px]">
                    <div className="text-sm font-bold text-gray-800">
                        {run ? (
                            <>
                                {run.nodes.length} dokumen · <span className="font-mono text-teal-700">{doneCount}</span> selesai
                            </>
                        ) : (
                            'Siap generate'
                        )}
                    </div>
                    <div className="my-2.5 h-1.5 overflow-hidden rounded-full bg-gray-200">
                        <div
                            className="h-full rounded-full bg-teal-600 transition-all duration-500"
                            style={{ width: run ? `${(doneCount / Math.max(run.nodes.length, 1)) * 100}%` : '0%' }}
                        />
                    </div>
                    <div className="flex flex-col gap-0.5">
                        {run?.nodes.map((n) => (
                            <div
                                key={n.doc_key}
                                onClick={
                                    n.status === 'done'
                                        ? () =>
                                              // WIREFRAMES = JSON, bukan markdown — buka halaman wireframes di tab baru
                                              n.doc_key === 'WIREFRAMES'
                                                  ? window.open(route('projects.wireframes', project.id), '_blank')
                                                  : openDoc(n.doc_key)
                                        : undefined
                                }
                                title={n.status === 'done' ? 'Klik untuk baca' : undefined}
                                className={`flex items-center gap-2 rounded-[7px] px-2 py-[5px] text-[12.5px] ${
                                    n.status === 'running'
                                        ? 'bg-teal-50 font-bold text-teal-900'
                                        : n.status === 'done'
                                          ? 'cursor-pointer font-semibold text-gray-700 hover:bg-gray-100'
                                          : n.status === 'error'
                                            ? 'font-semibold text-red-600'
                                            : 'font-medium text-gray-400'
                                }`}
                            >
                                {n.status === 'done' ? (
                                    <CheckCircle />
                                ) : n.status === 'running' ? (
                                    <Spinner />
                                ) : n.status === 'error' ? (
                                    <svg
                                        width="15"
                                        height="15"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="#EF4444"
                                        strokeWidth="2.4"
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        className="flex-none"
                                    >
                                        <line x1="18" y1="6" x2="6" y2="18" />
                                        <line x1="6" y1="6" x2="18" y2="18" />
                                    </svg>
                                ) : (
                                    <span className="box-border h-[15px] w-[15px] flex-none rounded-full border-2 border-gray-200" />
                                )}
                                {n.doc_key}.md
                                {n.status === 'running' && <span className="ml-auto text-[10.5px] font-medium text-gray-400">menulis…</span>}
                            </div>
                        ))}
                    </div>
                    <div className="mt-3.5 rounded-[10px] border border-gray-200 bg-gray-50 px-3 py-2.5 text-[11.5px] leading-relaxed font-medium text-gray-500">
                        Model routing aktif per kelas node (BR-50): <b className="font-bold text-gray-700">reasoning</b> (PRD/Arch) ·{' '}
                        <b className="font-bold text-gray-700">standard</b> (docs) · <b className="font-bold text-gray-700">economy</b> (API/DB)
                    </div>
                </div>

                {/* pane kanan */}
                <div className="min-w-0 flex-1 p-[18px]">
                    {errors.credits && (
                        <div className="mb-4 rounded-lg border border-red-200 bg-red-50 p-3 text-[13px] font-semibold text-red-700">
                            {errors.credits}
                        </div>
                    )}

                    {!run && (
                        <div className="flex min-h-[380px] flex-col items-center justify-center text-center">
                            <div className="text-lg font-extrabold text-gray-900">Generate blueprint</div>
                            <div className="mt-1.5 max-w-[380px] text-[13px] leading-relaxed font-medium text-gray-500">
                                Pipeline DAG — dokumen digenerate berurutan sesuai dependensi, berjalan di background (FR-07). 1 kredit per pipeline
                                (BR-02).
                            </div>
                            <div className="mt-4 text-sm text-gray-600">
                                Kredit tersedia: <span className="font-mono font-bold text-gray-900">{credits}</span>
                            </div>
                            <button className={`${btn} mt-5`} disabled={starting} onClick={() => startRun('projects.generate')}>
                                {starting && <Spinner stroke="#fff" />}
                                {starting ? 'Memulai pipeline…' : '✦ Generate blueprint (1 kredit)'}
                            </button>
                        </div>
                    )}

                    {run && running && (
                        <>
                            <div className="flex items-center justify-between">
                                <div className="text-sm font-bold text-gray-800">
                                    Sedang menulis — <span className="font-mono text-teal-700">{currentNode?.doc_key ?? '…'}.md</span>
                                </div>
                                {stale ? (
                                    <span
                                        className="rounded-full border border-amber-300 bg-amber-50 px-2.5 py-[3px] text-[11px] font-bold text-amber-800"
                                        title="Tidak ada progress >90 detik. Cek apakah queue worker jalan (composer run dev), lalu run lanjut otomatis."
                                    >
                                        ⚠ Diam — worker queue jalan?
                                    </span>
                                ) : (
                                    <span className="rounded-full border border-gray-200 bg-gray-50 px-2.5 py-[3px] font-mono text-[11px] font-bold text-gray-500 uppercase">
                                        {run.status}
                                    </span>
                                )}
                            </div>
                            {stream?.text && isWireframe ? (
                                <div
                                    ref={streamRef}
                                    className="mt-3 max-h-[420px] min-h-[330px] overflow-auto rounded-xl border border-gray-200 p-[18px]"
                                    style={{ backgroundImage: 'radial-gradient(#E2E8F0 1px, transparent 1px)', backgroundSize: '18px 18px' }}
                                >
                                    <div className="mb-3 flex items-center gap-2 text-[13px] font-bold text-teal-800">
                                        <Spinner />
                                        Menggambar wireframe — {wfScreens.length} layar…
                                    </div>
                                    <div className="grid grid-cols-[repeat(auto-fill,minmax(150px,1fr))] gap-2.5">
                                        {wfScreens.map((s, i) => (
                                            <div
                                                key={i}
                                                className={`overflow-hidden rounded-lg border bg-white shadow-sm ${
                                                    i === wfScreens.length - 1 ? 'animate-pulse border-teal-300' : 'border-gray-200'
                                                }`}
                                            >
                                                <div className="flex items-center gap-1 border-b border-gray-100 bg-gray-50 px-2 py-1">
                                                    <span className="h-1.5 w-1.5 rounded-full bg-gray-300" />
                                                    <span className="h-1.5 w-1.5 rounded-full bg-gray-300" />
                                                    <span className="min-w-0 flex-1 truncate text-center text-[9.5px] font-bold text-gray-600">
                                                        {s.name}
                                                    </span>
                                                </div>
                                                <div className="flex flex-col gap-1 p-2">
                                                    <div className="h-1.5 w-3/4 rounded bg-gray-200" />
                                                    <div className="h-1.5 w-full rounded bg-gray-100" />
                                                    <div className="h-1.5 w-1/2 rounded bg-gray-100" />
                                                </div>
                                                {s.flow && (
                                                    <div className="truncate border-t border-gray-100 px-2 py-1 text-[8.5px] font-semibold text-teal-700">
                                                        {s.flow}
                                                    </div>
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            ) : stream?.text ? (
                                <div
                                    ref={streamRef}
                                    className="mt-3 max-h-[420px] min-h-[330px] overflow-auto rounded-xl border border-gray-200 p-[18px]"
                                >
                                    <MarkdownPreview
                                        html={streamHtml}
                                        skipLastMermaid={(shown.match(/```/g)?.length ?? 0) % 2 === 1}
                                        className="prose prose-sm prose-headings:font-extrabold prose-headings:tracking-tight max-w-none"
                                    />
                                    <span className="ml-0.5 inline-block h-[14px] w-[8px] animate-pulse bg-teal-600 align-text-bottom" />
                                </div>
                            ) : (
                                <div className="mt-3 flex min-h-[330px] items-center justify-center rounded-xl border border-gray-200 p-[18px]">
                                    <div className="text-center">
                                        <Spinner />
                                        <div className="mt-3 text-[13px] font-semibold text-gray-500">
                                            AI menyusun {currentNode?.doc_key ?? 'dokumen'} dari dokumen upstream…
                                        </div>
                                    </div>
                                </div>
                            )}
                            <div className="mt-3 flex items-center gap-[7px] text-xs font-medium text-gray-400">
                                <svg
                                    width="14"
                                    height="14"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    strokeWidth="2.2"
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                >
                                    <circle cx="12" cy="12" r="10" />
                                    <polyline points="12 6 12 12 16 14" />
                                </svg>
                                Berjalan di background — aman meninggalkan halaman ini.
                                {shown && !isWireframe && (
                                    <span className="ml-auto font-mono text-[11px] text-gray-400">
                                        {shown.split('\n').length} baris · {(shown.length / 1000).toFixed(1)}k karakter
                                    </span>
                                )}
                            </div>
                        </>
                    )}

                    {run && ['paused', 'error'].includes(run.status) && (
                        <div className="flex min-h-[380px] flex-col items-center justify-center text-center">
                            <div className="text-lg font-extrabold text-red-600">Generate terhenti</div>
                            <div className="mt-1.5 max-w-[380px] text-[13px] font-medium text-gray-500">
                                Node gagal setelah 2× retry (BR-11). Lanjutkan hanya menjalankan ulang node yang gagal — tidak memakai kredit baru.
                            </div>
                            <button className={`${btn} mt-5`} disabled={starting} onClick={() => startRun('projects.generate.resume')}>
                                {starting && <Spinner stroke="#fff" />}
                                {starting ? 'Melanjutkan…' : 'Lanjutkan (node gagal saja)'}
                            </button>
                        </div>
                    )}
                </div>

                {/* Modal baca dokumen selesai — run tetap jalan di background */}
                {readDoc && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-6" onClick={() => setReadDoc(null)}>
                        <div
                            className="flex max-h-[85vh] w-full max-w-[860px] flex-col overflow-hidden rounded-xl bg-white shadow-2xl"
                            onClick={(e) => e.stopPropagation()}
                        >
                            <div className="flex items-center justify-between border-b border-gray-200 px-5 py-3.5">
                                <div className="font-mono text-sm font-bold text-teal-700">{readDoc.key}.md</div>
                                <button
                                    className="rounded-md px-2 py-1 text-lg leading-none text-gray-400 hover:bg-gray-100"
                                    onClick={() => setReadDoc(null)}
                                >
                                    ×
                                </button>
                            </div>
                            <div className="overflow-auto p-6">
                                {readDoc.md === null ? (
                                    <div className="flex justify-center py-10">
                                        <Spinner />
                                    </div>
                                ) : (
                                    <MarkdownPreview
                                        html={DOMPurify.sanitize(marked.parse(readDoc.md) as string)}
                                        className="prose prose-sm prose-headings:font-extrabold prose-headings:tracking-tight max-w-none"
                                    />
                                )}
                            </div>
                        </div>
                    </div>
                )}
            </div>

            {run && running && (
                <div className="mt-5 flex justify-end">
                    <button className={btn} onClick={() => router.visit(route('projects.show', project.id))}>
                        Buka workspace tanpa nunggu →
                    </button>
                </div>
            )}
        </>
    );
}

export default function Wizard(props: Props) {
    const step = props.project.wizard_step;

    // Draft kosong (step 1, belum ada input tersimpan): saat keluar, tawarkan hapus draft
    const emptyDraft = step === 'input' && !props.input?.raw_text;
    useEffect(() => {
        if (!emptyDraft) return;
        let bypass = false;
        const off = router.on('before', (e) => {
            const v = e.detail.visit;
            // POST wizard.input = user lanjut wizard; visit ulang setelah dialog = bypass
            if (bypass || v.method !== 'get' || v.url.pathname.includes(`/projects/${props.project.id}`)) return;
            e.preventDefault();
            (async () => {
                bypass = true;
                if (await confirmDialog('Draft proyek masih kosong. Hapus draft ini?\n\nBatal = draft tetap tersimpan.')) {
                    router.delete(route('projects.destroy', props.project.id));
                } else {
                    router.visit(v.url.href);
                }
                bypass = false;
            })();
            return false;
        });
        // close/refresh tab: hanya bisa prompt native browser
        const unload = (e: BeforeUnloadEvent) => e.preventDefault();
        window.addEventListener('beforeunload', unload);
        return () => {
            off();
            window.removeEventListener('beforeunload', unload);
        };
    }, [emptyDraft, props.project.id]);

    return (
        <WorkspaceLayout>
            <Head title={`${props.project.name} — Wizard`} />
            <div className="w-full">
                <div className="mb-3 flex items-center gap-2 text-[13px]">
                    <Link href={route('dashboard')} className="font-bold text-gray-500 hover:text-teal-700">
                        ← Proyek
                    </Link>
                    <span className="text-gray-300">/</span>
                    <span className="font-extrabold text-gray-900">{props.project.name}</span>
                </div>
                <Stepper current={step} />
                {step === 'input' && <StepInput project={props.project} input={props.input} />}
                {step === 'understanding' && <StepUnderstanding project={props.project} understanding={props.understanding} />}
                {step === 'interview' && (
                    <StepInterview
                        project={props.project}
                        interview={props.interview}
                        understanding={props.understanding}
                        step_job={props.step_job}
                    />
                )}
                {step === 'structure' && <StepStructure project={props.project} nodes={props.nodes} step_job={props.step_job} />}
                {step === 'stack' && <StepStack project={props.project} stack={props.stack} understanding={props.understanding} />}
                {(step === 'generate' || step === 'done') && (
                    <StepGenerate project={props.project} run={props.run} stream={props.stream} credits={props.credits} errors={props.errors} />
                )}
            </div>
        </WorkspaceLayout>
    );
}
