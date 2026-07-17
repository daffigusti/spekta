import { Head, router, useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';

import SpektaLayout from '@/layouts/spekta-layout';

type TemplateKind = 'proposal' | 'document' | 'portal';
type Cfg = Record<string, string | number | boolean | null>;

type TemplateData = {
    id: string;
    kind: TemplateKind;
    config: Cfg;
    file_url: string | null;
};

type FieldDef =
    | { key: string; type: 'color'; label: string }
    | { key: string; type: 'text'; label: string; placeholder?: string }
    | { key: string; type: 'bool'; label: string }
    | { key: string; type: 'select'; label: string; options: { value: string; label: string }[] };

// ponytail: skema field per kind display-only; kind & key tetap divalidasi server
const CARD_META: Record<TemplateKind, { title: string; desc: string; fields: FieldDef[] }> = {
    proposal: {
        title: 'Proposal',
        desc: 'Format proposal & RAB — warna, halaman sampul, footer.',
        fields: [
            { key: 'primary_color', type: 'color', label: 'Warna utama' },
            { key: 'accent_color', type: 'color', label: 'Warna aksen' },
            {
                key: 'page_format',
                type: 'select',
                label: 'Format halaman',
                options: [
                    { value: 'A4', label: 'A4' },
                    { value: 'Letter', label: 'Letter' },
                ],
            },
            { key: 'footer_text', type: 'text', label: 'Teks footer', placeholder: 'mis. Rahasia — PT Contoh' },
            { key: 'show_cover', type: 'bool', label: 'Tampilkan halaman sampul' },
        ],
    },
    document: {
        title: 'Dokumen',
        desc: 'Dokumen spesifikasi — penomoran heading & daftar isi.',
        fields: [
            { key: 'heading_numbering', type: 'bool', label: 'Penomoran heading otomatis' },
            { key: 'include_toc', type: 'bool', label: 'Sertakan daftar isi' },
            {
                key: 'language',
                type: 'select',
                label: 'Bahasa dokumen',
                options: [
                    { value: 'id', label: 'Indonesia' },
                    { value: 'en', label: 'English' },
                ],
            },
        ],
    },
    portal: {
        title: 'Portal Klien',
        desc: 'Tema portal & teks sambutan yang dilihat klien.',
        fields: [
            { key: 'theme_color', type: 'color', label: 'Warna tema' },
            { key: 'welcome_text', type: 'text', label: 'Teks sambutan', placeholder: 'mis. Selamat datang di portal proyek Anda' },
        ],
    },
};

const inputCls =
    'w-full rounded-[10px] border-2 border-gray-200 bg-gray-50 px-[11px] py-[7px] text-[13px] font-semibold text-gray-700 focus:border-teal-400 focus:bg-white focus:shadow-[0_0_0_3px_#F0FDFA] focus:outline-none disabled:cursor-not-allowed disabled:opacity-60';

function TemplateCard({ template, canManage }: { template: TemplateData; canManage: boolean }) {
    const meta = CARD_META[template.kind];
    const [config, setConfig] = useState<Cfg>(template.config ?? {});
    const [processing, setProcessing] = useState(false);
    const [saved, setSaved] = useState(false);

    const set = (key: string, value: string | boolean | null) => setConfig((c) => ({ ...c, [key]: value }));

    const save = () => {
        setProcessing(true);
        router.post(
            route('templates.update', template.kind),
            { config },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setSaved(true);
                    window.setTimeout(() => setSaved(false), 2000);
                },
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <div className="flex flex-col rounded-xl border border-gray-200 bg-white p-[18px]">
            <div className="text-[15px] font-bold text-gray-800">{meta.title}</div>
            <div className="mt-1 text-[11.5px] leading-relaxed font-medium text-gray-400">{meta.desc}</div>

            <div className="mt-4 flex flex-1 flex-col gap-3.5">
                {meta.fields.map((f) => {
                    if (f.type === 'color') {
                        const val = (config[f.key] as string) ?? '#0D9488';
                        return (
                            <div key={f.key}>
                                <label className="mb-1 block text-[11px] font-bold tracking-[0.04em] text-gray-500">{f.label}</label>
                                <div className="flex items-center gap-2">
                                    <input
                                        type="color"
                                        value={val}
                                        disabled={!canManage}
                                        onChange={(e) => set(f.key, e.target.value)}
                                        className="h-9 w-11 flex-none cursor-pointer rounded-lg border-2 border-gray-200 bg-white disabled:cursor-not-allowed disabled:opacity-60"
                                    />
                                    <input
                                        type="text"
                                        value={val}
                                        disabled={!canManage}
                                        onChange={(e) => set(f.key, e.target.value)}
                                        className={inputCls}
                                        style={{ fontFamily: 'ui-monospace,SFMono-Regular,Menlo,monospace' }}
                                    />
                                </div>
                            </div>
                        );
                    }
                    if (f.type === 'text') {
                        return (
                            <div key={f.key}>
                                <label className="mb-1 block text-[11px] font-bold tracking-[0.04em] text-gray-500">{f.label}</label>
                                <input
                                    type="text"
                                    value={(config[f.key] as string) ?? ''}
                                    placeholder={f.placeholder}
                                    disabled={!canManage}
                                    onChange={(e) => set(f.key, e.target.value || null)}
                                    className={inputCls}
                                />
                            </div>
                        );
                    }
                    if (f.type === 'select') {
                        return (
                            <div key={f.key}>
                                <label className="mb-1 block text-[11px] font-bold tracking-[0.04em] text-gray-500">{f.label}</label>
                                <select
                                    value={(config[f.key] as string) ?? f.options[0].value}
                                    disabled={!canManage}
                                    onChange={(e) => set(f.key, e.target.value)}
                                    className={inputCls}
                                >
                                    {f.options.map((o) => (
                                        <option key={o.value} value={o.value}>
                                            {o.label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        );
                    }
                    // bool
                    return (
                        <label key={f.key} className="flex cursor-pointer items-center gap-2.5 text-[13px] font-semibold text-gray-700">
                            <input
                                type="checkbox"
                                checked={Boolean(config[f.key])}
                                disabled={!canManage}
                                onChange={(e) => set(f.key, e.target.checked)}
                                className="h-4 w-4 rounded border-gray-300 text-teal-600 focus:ring-teal-400 disabled:cursor-not-allowed disabled:opacity-60"
                            />
                            {f.label}
                        </label>
                    );
                })}
            </div>

            {canManage && (
                <div className="mt-4 flex items-center gap-3 border-t border-gray-100 pt-3.5">
                    <button
                        onClick={save}
                        disabled={processing}
                        className="rounded-[10px] bg-teal-600 px-4 py-2 text-[13px] font-bold text-white hover:bg-teal-700 disabled:opacity-50"
                    >
                        Simpan
                    </button>
                    {saved && <span className="text-xs font-bold text-teal-600">Tersimpan ✓</span>}
                </div>
            )}
        </div>
    );
}

function BrandingCard({ branding, canManage }: { branding: { logo_url: string | null; name: string }; canManage: boolean }) {
    const { setData, post, processing, reset, recentlySuccessful } = useForm<{ logo: File | null }>({ logo: null });
    const [preview, setPreview] = useState<string | null>(null);
    const fileRef = useRef<HTMLInputElement>(null);
    const [hasFile, setHasFile] = useState(false);

    const initials = branding.name
        .split(' ')
        .map((w) => w[0])
        .slice(0, 2)
        .join('')
        .toUpperCase();

    const onPick = (file: File | null) => {
        setData('logo', file);
        setHasFile(Boolean(file));
        setPreview(file ? URL.createObjectURL(file) : null);
    };

    const upload = () => {
        post(route('templates.update', 'proposal'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                reset('logo');
                setHasFile(false);
                if (fileRef.current) fileRef.current.value = '';
            },
        });
    };

    const shown = preview ?? branding.logo_url;

    return (
        <div className="rounded-xl border border-gray-200 bg-white p-[18px]">
            <div className="text-[15px] font-bold text-gray-800">Branding workspace</div>
            <div className="mt-1 text-[11.5px] leading-relaxed font-medium text-gray-400">Logo dipakai di proposal &amp; portal klien (FR-16).</div>

            <div className="mt-4 flex flex-wrap items-center gap-4">
                {shown ? (
                    <img src={shown} alt="Logo" className="h-16 w-16 flex-none rounded-xl border border-gray-200 bg-white object-contain p-1.5" />
                ) : (
                    <div className="flex h-16 w-16 flex-none items-center justify-center rounded-xl bg-gradient-to-br from-teal-600 to-teal-400 text-xl font-extrabold text-white">
                        {initials || 'S'}
                    </div>
                )}

                {canManage && (
                    <div className="flex flex-wrap items-center gap-3">
                        <input
                            ref={fileRef}
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            onChange={(e) => onPick(e.target.files?.[0] ?? null)}
                            className="text-[12.5px] font-medium text-gray-600 file:mr-3 file:rounded-[10px] file:border-2 file:border-gray-200 file:bg-gray-50 file:px-3 file:py-1.5 file:text-[12.5px] file:font-bold file:text-gray-600 hover:file:bg-gray-100"
                        />
                        <button
                            onClick={upload}
                            disabled={processing || !hasFile}
                            className="rounded-[10px] bg-teal-600 px-4 py-2 text-[13px] font-bold text-white hover:bg-teal-700 disabled:opacity-50"
                        >
                            Simpan logo
                        </button>
                        {recentlySuccessful && <span className="text-xs font-bold text-teal-600">Tersimpan ✓</span>}
                    </div>
                )}
            </div>
        </div>
    );
}

export default function Templates({
    templates,
    branding,
    canManage,
}: {
    templates: TemplateData[];
    branding: { logo_url: string | null; name: string };
    canManage: boolean;
}) {
    return (
        <SpektaLayout crumb="Template Perusahaan" active="templates">
            <Head title="Template Perusahaan — Spekta" />

            <div className="mb-[22px]">
                <h1 className="text-[26px] font-extrabold tracking-[-0.02em] text-gray-900">Template Perusahaan</h1>
                <div className="mt-1 text-sm font-medium tracking-[0.02em] text-gray-500">
                    Proposal, dokumen &amp; portal klien mengikuti template ini otomatis
                    {!canManage && ' · hanya owner/admin yang dapat mengubah'}
                </div>
            </div>

            <div className="grid gap-[18px]">
                <BrandingCard branding={branding} canManage={canManage} />

                <div className="grid items-start gap-[18px]" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(min(300px,100%),1fr))' }}>
                    {templates.map((t) => (
                        <TemplateCard key={t.id} template={t} canManage={canManage} />
                    ))}
                </div>
            </div>
        </SpektaLayout>
    );
}
