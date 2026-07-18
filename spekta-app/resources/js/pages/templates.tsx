import { Head, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

import SpektaLayout from '@/layouts/spekta-layout';

type TemplateData = {
    id: string;
    name: string;
    is_default: boolean;
    doc_kinds: string[];
    language: string;
    tone: string;
    config: { white_label?: boolean };
    projects_count: number;
    updated_at: string | null;
};

const LANG_LABEL: Record<string, string> = { id: 'Indonesia (ID)', en: 'English (EN)' };
const TONE_LABEL: Record<string, string> = { formal: 'Formal', formal_rfc: 'Formal — RFC style', casual: 'Santai' };
const proposalLabel = (whiteLabel?: boolean) => (whiteLabel ? 'White-label + logo' : 'Standar');

function formatDate(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
}

const inputCls =
    'w-full rounded-[10px] border-2 border-gray-200 bg-gray-50 px-[11px] py-[9px] text-[13px] font-semibold text-gray-700 focus:border-teal-400 focus:bg-white focus:shadow-[0_0_0_3px_#F0FDFA] focus:outline-none';

// Micro-label abu-abu di atas grup field / chip
const microLabel = 'text-[11px] font-bold tracking-[0.06em] text-gray-400 uppercase';

type FormState = {
    name: string;
    doc_kinds: string[];
    language: string;
    tone: string;
    white_label: boolean;
    logo: File | null;
    brand_primary: string;
};

const DEFAULT_ACCENT = '#0D9488'; // teal default proposal

const EMPTY_FORM: FormState = {
    name: '',
    doc_kinds: [],
    language: 'id',
    tone: 'formal',
    white_label: false,
    logo: null,
    brand_primary: DEFAULT_ACCENT,
};

function MiniCol({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <div className="text-[11px] font-medium text-gray-400">{label}</div>
            <div className="mt-0.5 text-[13px] font-semibold text-gray-700">{value}</div>
        </div>
    );
}

function TemplateCard({ template, canManage, onEdit }: { template: TemplateData; canManage: boolean; onEdit: (t: TemplateData) => void }) {
    const setDefault = () => router.post(route('templates.default', template.id), {}, { preserveScroll: true });

    return (
        <div className={`flex flex-col rounded-xl bg-white p-5 ${template.is_default ? 'border-2 border-teal-600' : 'border border-gray-200'}`}>
            <div className="flex items-start justify-between gap-2">
                <div className="text-[15px] font-bold text-gray-800">{template.name}</div>
                {template.is_default && (
                    <span className="flex-none rounded-full bg-teal-50 px-2 py-0.5 text-[10px] font-bold tracking-[0.04em] text-teal-700">
                        ● DEFAULT
                    </span>
                )}
            </div>
            <div className="mt-1 text-[11.5px] font-medium text-gray-400">
                Dipakai {template.projects_count} proyek · diperbarui {formatDate(template.updated_at)}
            </div>

            <div className="mt-4">
                <div className={microLabel}>Set Dokumen</div>
                <div className="mt-2 flex flex-wrap gap-1.5">
                    {template.doc_kinds.map((k) => (
                        <span
                            key={k}
                            className="rounded border border-gray-200 bg-gray-50 px-1.5 py-0.5 font-mono text-[11px] font-semibold text-gray-600"
                        >
                            {k}
                        </span>
                    ))}
                </div>
            </div>

            <div className="mt-4 grid grid-cols-3 gap-3 border-t border-gray-100 pt-4">
                <MiniCol label="Bahasa" value={LANG_LABEL[template.language] ?? template.language} />
                <MiniCol label="Proposal" value={proposalLabel(template.config?.white_label)} />
                <MiniCol label="Tone" value={TONE_LABEL[template.tone] ?? template.tone} />
            </div>

            {canManage && (
                <div className="mt-5 flex items-center gap-2.5">
                    {!template.is_default && (
                        <button
                            onClick={setDefault}
                            className="rounded-[10px] bg-teal-600 px-4 py-2 text-[13px] font-bold text-white hover:bg-teal-700"
                        >
                            Jadikan default
                        </button>
                    )}
                    <button
                        onClick={() => onEdit(template)}
                        className="rounded-[10px] border-2 border-gray-200 px-4 py-2 text-[13px] font-bold text-gray-600 hover:border-teal-300 hover:text-teal-700"
                    >
                        Edit template
                    </button>
                </div>
            )}
        </div>
    );
}

function TemplateModal({
    mode,
    editing,
    docKindOptions,
    logoUrl,
    brandPrimary,
    onClose,
}: {
    mode: 'create' | 'edit';
    editing: TemplateData | null;
    docKindOptions: string[];
    logoUrl: string | null;
    brandPrimary: string | null;
    onClose: () => void;
}) {
    const [form, setForm] = useState<FormState>(
        editing
            ? {
                  name: editing.name,
                  doc_kinds: editing.doc_kinds,
                  language: editing.language,
                  tone: editing.tone,
                  white_label: Boolean(editing.config?.white_label),
                  logo: null,
                  brand_primary: brandPrimary ?? DEFAULT_ACCENT,
              }
            : { ...EMPTY_FORM, brand_primary: brandPrimary ?? DEFAULT_ACCENT },
    );
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const toggleKind = (k: string) =>
        setForm((f) => ({
            ...f,
            doc_kinds: f.doc_kinds.includes(k) ? f.doc_kinds.filter((x) => x !== k) : [...f.doc_kinds, k],
        }));

    const canSave = form.name.trim().length > 0 && form.doc_kinds.length > 0 && !processing;

    // Preview logo: file baru yang dipilih menang atas logo workspace tersimpan
    const logoPreview = useMemo(() => (form.logo ? URL.createObjectURL(form.logo) : logoUrl), [form.logo, logoUrl]);

    const save = () => {
        setProcessing(true);
        const payload = {
            name: form.name,
            doc_kinds: form.doc_kinds,
            language: form.language,
            tone: form.tone,
            config: { white_label: form.white_label },
            brand_primary: form.brand_primary,
            ...(form.logo ? { logo: form.logo } : {}),
        };

        const url = mode === 'edit' && editing ? route('templates.update', editing.id) : route('templates.store');
        router.post(url, payload, {
            preserveScroll: true,
            forceFormData: Boolean(form.logo),
            onSuccess: () => onClose(),
            onError: (e) => setErrors(e as Record<string, string>),
            onFinish: () => setProcessing(false),
        });
    };

    const remove = () => {
        if (!editing) return;
        if (!window.confirm(`Hapus template "${editing.name}"?`)) return;
        router.delete(route('templates.destroy', editing.id), {
            preserveScroll: true,
            onSuccess: () => onClose(),
            onError: (e) => setErrors(e as Record<string, string>),
        });
    };

    return (
        <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4 py-10">
            <div className="w-full max-w-[560px] rounded-2xl bg-white p-6 shadow-xl">
                <div className="flex items-center justify-between">
                    <h2 className="text-[18px] font-extrabold tracking-[-0.01em] text-gray-900">
                        {mode === 'edit' ? 'Edit template' : 'Template baru'}
                    </h2>
                    <button onClick={onClose} className="text-lg font-bold text-gray-300 hover:text-gray-500" title="Tutup">
                        ✕
                    </button>
                </div>

                {errors.template && <div className="mt-3 text-[12px] font-bold text-red-500">{errors.template}</div>}

                <div className="mt-5 flex flex-col gap-5">
                    <div>
                        <label className={microLabel}>Nama template</label>
                        <input
                            value={form.name}
                            onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
                            placeholder="mis. Standar AmanahCorp"
                            className={`mt-1.5 ${inputCls}`}
                        />
                        {errors.name && <div className="mt-1 text-[11.5px] font-bold text-red-500">{errors.name}</div>}
                    </div>

                    <div>
                        <label className={microLabel}>Set Dokumen</label>
                        <div className="mt-2 flex flex-wrap gap-1.5">
                            {docKindOptions.map((k) => {
                                const on = form.doc_kinds.includes(k);
                                return (
                                    <button
                                        key={k}
                                        type="button"
                                        onClick={() => toggleKind(k)}
                                        className={`rounded border-[1.5px] px-1.5 py-0.5 font-mono text-[11px] font-semibold ${
                                            on
                                                ? 'border-teal-600 bg-teal-50 text-teal-800'
                                                : 'border-gray-200 bg-gray-50 text-gray-400 hover:border-teal-300'
                                        }`}
                                    >
                                        {k}
                                    </button>
                                );
                            })}
                        </div>
                        {form.doc_kinds.length === 0 && (
                            <div className="mt-1.5 text-[11.5px] font-semibold text-gray-400">Pilih minimal satu dokumen.</div>
                        )}
                        {errors.doc_kinds && <div className="mt-1 text-[11.5px] font-bold text-red-500">{errors.doc_kinds}</div>}
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className={microLabel}>Bahasa</label>
                            <select
                                value={form.language}
                                onChange={(e) => setForm((f) => ({ ...f, language: e.target.value }))}
                                className={`mt-1.5 ${inputCls}`}
                            >
                                <option value="id">Indonesia (ID)</option>
                                <option value="en">English (EN)</option>
                            </select>
                        </div>
                        <div>
                            <label className={microLabel}>Tone</label>
                            <select
                                value={form.tone}
                                onChange={(e) => setForm((f) => ({ ...f, tone: e.target.value }))}
                                className={`mt-1.5 ${inputCls}`}
                            >
                                <option value="formal">Formal</option>
                                <option value="formal_rfc">Formal — RFC style</option>
                                <option value="casual">Santai</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label className={microLabel}>Proposal</label>
                        <label className="mt-2 flex cursor-pointer items-center gap-2.5 text-[13px] font-semibold text-gray-700">
                            <input
                                type="checkbox"
                                checked={form.white_label}
                                onChange={(e) => setForm((f) => ({ ...f, white_label: e.target.checked }))}
                                className="h-4 w-4 rounded border-gray-300 text-teal-600 focus:ring-teal-400"
                            />
                            White-label + logo
                        </label>
                        <div className="mt-2.5 flex items-center gap-3">
                            {logoPreview && (
                                <img
                                    src={logoPreview}
                                    alt="Logo workspace"
                                    className="h-10 w-10 flex-none rounded-lg border border-gray-200 bg-white object-contain p-1"
                                />
                            )}
                            <input
                                type="file"
                                accept=".jpg,.jpeg,.png,.webp"
                                onChange={(e) => setForm((f) => ({ ...f, logo: e.target.files?.[0] ?? null }))}
                                className="text-[12.5px] font-medium text-gray-600 file:mr-3 file:rounded-[10px] file:border-2 file:border-gray-200 file:bg-gray-50 file:px-3 file:py-1.5 file:text-[12.5px] file:font-bold file:text-gray-600 hover:file:bg-gray-100"
                            />
                        </div>
                        <div className="mt-1.5 text-[11.5px] font-medium text-gray-400">
                            {form.logo ? 'Logo baru — tersimpan saat klik Simpan.' : 'Logo workspace — dipakai proposal & portal.'}
                        </div>
                        {errors.logo && <div className="mt-1 text-[11.5px] font-bold text-red-500">{errors.logo}</div>}
                        <div className="mt-3 flex items-center gap-3">
                            <input
                                type="color"
                                value={form.brand_primary}
                                onChange={(e) => setForm((f) => ({ ...f, brand_primary: e.target.value }))}
                                className="h-9 w-14 flex-none cursor-pointer rounded-lg border border-gray-200 bg-white p-1"
                                title="Warna aksen proposal"
                            />
                            <div className="text-[12.5px] font-semibold text-gray-600">
                                Warna aksen proposal
                                <div className="text-[11.5px] font-medium text-gray-400">Judul & tabel di proposal DOCX ikut warna ini.</div>
                            </div>
                        </div>
                        {errors.brand_primary && <div className="mt-1 text-[11.5px] font-bold text-red-500">{errors.brand_primary}</div>}
                    </div>
                </div>

                <div className="mt-6 flex items-center justify-between">
                    <div>
                        {mode === 'edit' && editing && !editing.is_default && (
                            <button onClick={remove} className="text-[13px] font-bold text-red-500 hover:text-red-600">
                                Hapus template
                            </button>
                        )}
                    </div>
                    <div className="flex items-center gap-2.5">
                        <button
                            onClick={onClose}
                            className="rounded-[10px] border-2 border-gray-200 px-4 py-2 text-[13px] font-bold text-gray-600 hover:bg-gray-50"
                        >
                            Batal
                        </button>
                        <button
                            onClick={save}
                            disabled={!canSave}
                            className="rounded-[10px] bg-teal-600 px-5 py-2 text-[13px] font-bold text-white hover:bg-teal-700 disabled:opacity-50"
                        >
                            Simpan
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}

export default function Templates({
    templates,
    docKindOptions,
    logoUrl,
    brandPrimary,
    canManage,
}: {
    templates: TemplateData[];
    docKindOptions: string[];
    logoUrl: string | null;
    brandPrimary: string | null;
    canManage: boolean;
}) {
    const [modal, setModal] = useState<{ mode: 'create' | 'edit'; editing: TemplateData | null } | null>(null);

    return (
        <SpektaLayout crumb="Template Perusahaan" active="templates">
            <Head title="Template Perusahaan — Spekta" />

            <div className="mb-[22px]">
                <h1 className="text-[26px] font-extrabold tracking-[-0.02em] text-gray-900">Template Perusahaan</h1>
                <div className="mt-1 text-sm font-medium tracking-[0.02em] text-gray-500">
                    Standar dokumen, bahasa, dan branding proposal — semua blueprint mengikuti template default
                    {!canManage && ' · hanya owner/admin yang dapat mengubah'}
                </div>
            </div>

            <div className="grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-3">
                {templates.map((t) => (
                    <TemplateCard key={t.id} template={t} canManage={canManage} onEdit={(tpl) => setModal({ mode: 'edit', editing: tpl })} />
                ))}

                {canManage && (
                    <button
                        onClick={() => setModal({ mode: 'create', editing: null })}
                        className="flex min-h-[220px] flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-gray-300 bg-white/50 text-gray-400 transition hover:border-teal-400 hover:text-teal-600"
                    >
                        <span className="text-3xl leading-none font-light">+</span>
                        <span className="text-[13px] font-bold">Template baru</span>
                    </button>
                )}
            </div>

            {modal && (
                <TemplateModal
                    mode={modal.mode}
                    editing={modal.editing}
                    docKindOptions={docKindOptions}
                    logoUrl={logoUrl}
                    brandPrimary={brandPrimary}
                    onClose={() => setModal(null)}
                />
            )}
        </SpektaLayout>
    );
}
