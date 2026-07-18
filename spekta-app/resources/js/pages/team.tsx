import { Head, router, useForm } from '@inertiajs/react';

import SpektaLayout from '@/layouts/spekta-layout';

type Member = {
    id: number;
    user_id: number;
    name: string | null;
    email: string | null;
    role: string;
    hide_prices: boolean;
    joined_at: string | null;
};

type Client = {
    name: string;
    projects_count: number;
    last_project: string;
    last_status: string;
    last_activity: string | null;
};

type Limit = { members_limit: number | null; members_used: number };

const ROLE_LABEL: Record<string, string> = { owner: 'Owner', admin: 'Admin', member: 'Anggota' };
const ROLE_BADGE: Record<string, string> = {
    owner: 'border-teal-200 bg-teal-50 text-teal-700',
    admin: 'border-indigo-200 bg-indigo-50 text-indigo-700',
    member: 'border-gray-200 bg-gray-100 text-gray-600',
};

const STATUS_LABEL: Record<string, string> = {
    draft: 'Draft',
    generating: 'Proses',
    ready: 'Siap',
    shared: 'Dibagikan',
    approved: 'Disetujui',
    archived: 'Arsip',
};
const STATUS_BADGE: Record<string, string> = {
    draft: 'border-gray-200 bg-gray-100 text-gray-600',
    generating: 'border-amber-200 bg-amber-50 text-amber-700',
    ready: 'border-teal-200 bg-teal-50 text-teal-700',
    shared: 'border-indigo-200 bg-indigo-50 text-indigo-700',
    approved: 'border-green-200 bg-green-50 text-green-700',
    archived: 'border-gray-200 bg-gray-100 text-gray-500',
};

function initialsOf(name: string | null, email: string | null): string {
    const base = (name || email || '?').trim();
    return base
        .split(/[\s@.]+/)
        .filter(Boolean)
        .map((w) => w[0])
        .slice(0, 2)
        .join('')
        .toUpperCase();
}

const thCls = 'border-b border-gray-200 bg-gray-50 px-4 py-2.5 text-left text-[10px] font-bold tracking-[0.08em] text-gray-500 uppercase';

export default function Team({
    members,
    clients,
    canManage,
    currentUserId,
    limit,
}: {
    members: Member[];
    clients: Client[];
    canManage: boolean;
    currentUserId: number;
    limit: Limit;
}) {
    const invite = useForm<{ email: string; role: string }>({ email: '', role: 'member' });

    const actor = members.find((m) => m.user_id === currentUserId);
    const actorIsOwner = actor?.role === 'owner';

    const submitInvite = (e: React.FormEvent) => {
        e.preventDefault();
        invite.post(route('team.members.store'), {
            preserveScroll: true,
            onSuccess: () => invite.reset('email'),
        });
    };

    const changeRole = (member: Member, role: string) => router.patch(route('team.members.update', member.id), { role }, { preserveScroll: true });

    const toggleHidePrices = (member: Member, hide_prices: boolean) =>
        router.patch(route('team.members.update', member.id), { hide_prices }, { preserveScroll: true });

    const removeMember = (member: Member) => {
        if (!window.confirm(`Hapus ${member.name ?? member.email} dari workspace?`)) return;
        router.delete(route('team.members.destroy', member.id), { preserveScroll: true });
    };

    return (
        <SpektaLayout crumb="Tim & Klien" active="team">
            <Head title="Tim & Klien — Spekta" />

            <div className="mb-[22px]">
                <h1 className="text-[26px] font-extrabold tracking-[-0.02em] text-gray-900">Tim &amp; Klien</h1>
                <div className="mt-1 text-sm font-medium tracking-[0.02em] text-gray-500">
                    Kelola anggota workspace &amp; lihat daftar klien dari proyek
                </div>
            </div>

            {/* Anggota */}
            <div className="mb-[18px] rounded-xl border border-gray-200 bg-white">
                <div className="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-[18px] py-3.5">
                    <div className="text-[15px] font-bold text-gray-800">Anggota</div>
                    {limit.members_limit !== null && (
                        <div className="font-mono text-xs font-bold text-gray-500">
                            {limit.members_used} / {limit.members_limit} anggota
                        </div>
                    )}
                </div>

                {canManage && (
                    <form onSubmit={submitInvite} className="flex flex-wrap items-start gap-2.5 border-b border-gray-100 px-[18px] py-3.5">
                        <div className="min-w-[220px] flex-1">
                            <input
                                type="email"
                                value={invite.data.email}
                                placeholder="email@perusahaan.co.id"
                                onChange={(e) => invite.setData('email', e.target.value)}
                                className="w-full rounded-[10px] border-2 border-gray-200 bg-gray-50 px-[11px] py-[9px] text-[13px] font-semibold text-gray-700 focus:border-teal-400 focus:bg-white focus:shadow-[0_0_0_3px_#F0FDFA] focus:outline-none"
                            />
                            {invite.errors.email && <div className="mt-1 text-[11.5px] font-bold text-red-500">{invite.errors.email}</div>}
                        </div>
                        <select
                            value={invite.data.role}
                            onChange={(e) => invite.setData('role', e.target.value)}
                            className="rounded-[10px] border-2 border-gray-200 bg-gray-50 px-[11px] py-[9px] text-[13px] font-semibold text-gray-700 focus:border-teal-400 focus:bg-white focus:outline-none"
                        >
                            <option value="member">Anggota</option>
                            <option value="admin">Admin</option>
                        </select>
                        <button
                            type="submit"
                            disabled={invite.processing}
                            className="rounded-[10px] bg-teal-600 px-[18px] py-[9px] text-[13px] font-bold text-white hover:bg-teal-700 disabled:opacity-50"
                        >
                            Undang
                        </button>
                    </form>
                )}

                <div className="overflow-x-auto">
                    <table className="w-full min-w-[640px] border-collapse text-[13px]">
                        <thead>
                            <tr>
                                <th className={thCls}>Anggota</th>
                                <th className={thCls}>Peran</th>
                                <th className={thCls}>Harga</th>
                                <th className={thCls}>Bergabung</th>
                                <th className="w-12 border-b border-gray-200 bg-gray-50" />
                            </tr>
                        </thead>
                        <tbody>
                            {members.map((m) => {
                                const isSelf = m.user_id === currentUserId;
                                const canEditRole = canManage && !isSelf && !(m.role === 'owner' && !actorIsOwner);
                                const canRemove = canManage && !isSelf && !(m.role === 'owner' && !actorIsOwner);
                                return (
                                    <tr key={m.id} className="border-b border-gray-100 hover:bg-gray-50">
                                        <td className="px-4 py-3">
                                            <div className="flex items-center gap-3">
                                                <div className="flex h-9 w-9 flex-none items-center justify-center rounded-[10px] bg-gradient-to-br from-teal-600 to-teal-400 text-[12px] font-extrabold text-white">
                                                    {initialsOf(m.name, m.email)}
                                                </div>
                                                <div className="min-w-0">
                                                    <div className="truncate font-bold text-gray-800">
                                                        {m.name ?? '—'}{' '}
                                                        {isSelf && <span className="text-[11px] font-semibold text-gray-400">(Anda)</span>}
                                                    </div>
                                                    <div className="truncate text-[12px] font-medium text-gray-400">{m.email}</div>
                                                </div>
                                            </div>
                                        </td>
                                        <td className="px-4 py-3">
                                            {canEditRole ? (
                                                <select
                                                    value={m.role}
                                                    onChange={(e) => changeRole(m, e.target.value)}
                                                    className="rounded-[8px] border-2 border-gray-200 bg-gray-50 px-2 py-1 text-[12px] font-bold text-gray-700 focus:border-teal-400 focus:bg-white focus:outline-none"
                                                >
                                                    {actorIsOwner && <option value="owner">Owner</option>}
                                                    <option value="admin">Admin</option>
                                                    <option value="member">Anggota</option>
                                                </select>
                                            ) : (
                                                <span
                                                    className={`inline-block rounded-full border px-2.5 py-0.5 text-[11px] font-bold ${ROLE_BADGE[m.role] ?? ROLE_BADGE.member}`}
                                                >
                                                    {ROLE_LABEL[m.role] ?? m.role}
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            {m.role === 'member' ? (
                                                <label className="flex items-center gap-2 text-[12px] font-semibold text-gray-600">
                                                    <input
                                                        type="checkbox"
                                                        checked={m.hide_prices}
                                                        disabled={!canManage}
                                                        onChange={(e) => toggleHidePrices(m, e.target.checked)}
                                                        className="h-4 w-4 rounded border-gray-300 text-teal-600 focus:ring-teal-400 disabled:cursor-not-allowed disabled:opacity-60"
                                                    />
                                                    Sembunyikan harga
                                                </label>
                                            ) : (
                                                <span className="text-[12px] font-medium text-gray-300">Penuh</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 font-mono text-[12px] font-semibold text-gray-500">{m.joined_at ?? '—'}</td>
                                        <td className="pr-3 text-right">
                                            {canRemove && (
                                                <button
                                                    onClick={() => removeMember(m)}
                                                    className="text-sm font-bold text-gray-300 hover:text-red-500"
                                                    title="Hapus anggota"
                                                >
                                                    ✕
                                                </button>
                                            )}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            </div>

            {/* Klien */}
            <div className="rounded-xl border border-gray-200 bg-white">
                <div className="border-b border-gray-100 px-[18px] py-3.5 text-[15px] font-bold text-gray-800">Klien</div>
                {clients.length === 0 ? (
                    <div className="px-[18px] py-10 text-center text-[13px] font-medium text-gray-400">
                        Belum ada klien — nama klien terisi saat membuat proyek.
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[640px] border-collapse text-[13px]">
                            <thead>
                                <tr>
                                    <th className={thCls}>Nama Klien</th>
                                    <th className={thCls}>Jumlah Proyek</th>
                                    <th className={thCls}>Proyek Terakhir</th>
                                    <th className={thCls}>Aktivitas Terakhir</th>
                                </tr>
                            </thead>
                            <tbody>
                                {clients.map((c) => (
                                    <tr key={c.name} className="border-b border-gray-100 hover:bg-gray-50">
                                        <td className="px-4 py-3 font-bold text-gray-800">{c.name}</td>
                                        <td className="px-4 py-3 font-mono font-semibold text-gray-600">{c.projects_count}</td>
                                        <td className="px-4 py-3">
                                            <div className="flex items-center gap-2">
                                                <span className="truncate font-semibold text-gray-700">{c.last_project}</span>
                                                <span
                                                    className={`inline-block flex-none rounded-full border px-2 py-0.5 text-[10px] font-bold ${STATUS_BADGE[c.last_status] ?? STATUS_BADGE.draft}`}
                                                >
                                                    {STATUS_LABEL[c.last_status] ?? c.last_status}
                                                </span>
                                            </div>
                                        </td>
                                        <td className="px-4 py-3 font-mono text-[12px] font-semibold text-gray-500">{c.last_activity ?? '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </SpektaLayout>
    );
}
