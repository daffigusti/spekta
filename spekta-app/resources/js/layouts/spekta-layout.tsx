import AppLogoIcon from '@/components/app-logo-icon';
import { Link, router, usePage } from '@inertiajs/react';
import { PropsWithChildren, ReactNode, useEffect, useRef, useState } from 'react';

interface WorkspaceProps {
    id: string;
    name: string;
    plan: string;
    credits: number;
    credits_quota: number | null;
    projects_count: number;
}

interface WorkspaceItem {
    id: string;
    name: string;
    role: string;
}

interface PageProps {
    auth: { user: { name: string } };
    workspace: WorkspaceProps | null;
    workspaces: WorkspaceItem[];
    [key: string]: unknown;
}

function NavItem({ href, active, icon, label, badge }: { href: string; active?: boolean; icon: ReactNode; label: string; badge?: ReactNode }) {
    return (
        <Link
            href={href}
            className={`flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm ${
                active ? 'bg-teal-50 font-bold text-teal-700' : 'font-medium text-gray-600 hover:bg-teal-50/50'
            }`}
        >
            {icon}
            {label}
            {badge}
        </Link>
    );
}

const icons = {
    grid: (
        <svg
            width="19"
            height="19"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2.2"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <rect x="3" y="3" width="7" height="7" rx="1" />
            <rect x="14" y="3" width="7" height="7" rx="1" />
            <rect x="3" y="14" width="7" height="7" rx="1" />
            <rect x="14" y="14" width="7" height="7" rx="1" />
        </svg>
    ),
    card: (
        <svg
            width="19"
            height="19"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2.2"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <rect x="2" y="5" width="20" height="14" rx="2" />
            <line x1="2" y1="10" x2="22" y2="10" />
        </svg>
    ),
    file: (
        <svg
            width="19"
            height="19"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2.2"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <path d="M14 3v5h5" />
            <path d="M7 3h7l5 5v13a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z" />
        </svg>
    ),
    users: (
        <svg
            width="19"
            height="19"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2.2"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
            <circle cx="9" cy="7" r="4" />
            <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
            <path d="M16 3.13a4 4 0 0 1 0 7.75" />
        </svg>
    ),
    settings: (
        <svg
            width="19"
            height="19"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2.2"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <circle cx="12" cy="12" r="3" />
            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" />
        </svg>
    ),
};

export default function SpektaLayout({
    children,
    crumb,
    active,
}: PropsWithChildren<{ crumb: string; active?: 'projects' | 'templates' | 'ratecard' | 'team' | 'settings' }>) {
    const { auth, workspace, workspaces = [] } = usePage<PageProps>().props;
    const [switcherOpen, setSwitcherOpen] = useState(false);
    const switcherRef = useRef<HTMLDivElement>(null);
    useEffect(() => {
        if (!switcherOpen) return;
        const close = (e: MouseEvent) => {
            if (!switcherRef.current?.contains(e.target as Node)) setSwitcherOpen(false);
        };
        document.addEventListener('mousedown', close);
        return () => document.removeEventListener('mousedown', close);
    }, [switcherOpen]);
    const initials = auth.user.name
        .split(' ')
        .map((w) => w[0])
        .slice(0, 2)
        .join('')
        .toUpperCase();
    const quota = workspace?.credits_quota;
    const credits = workspace?.credits ?? 0;
    const pct = quota ? Math.min(100, (credits / quota) * 100) : 100;

    return (
        <div className="flex min-h-screen bg-gray-50 text-sm text-gray-700">
            <aside className="sticky top-0 flex h-screen w-[250px] flex-none flex-col border-r border-gray-200 bg-white">
                <div className="flex items-center gap-2.5 px-4 pt-[18px] pb-3.5">
                    <div className="flex h-[38px] w-[38px] flex-none items-center justify-center rounded-[10px] bg-gradient-to-br from-[#14B8A6] to-[#5EEAD4] text-[#042F2E]">
                        <AppLogoIcon className="h-[22px] w-[22px]" />
                    </div>
                    <div className="min-w-0">
                        <div className="text-[15px] font-extrabold tracking-tight text-gray-900">
                            Spekta<span className="text-[#F5A623]">.</span>
                        </div>
                        <div className="truncate text-[11px] font-semibold text-gray-400">{workspace?.name ?? '—'} Workspace</div>
                    </div>
                </div>

                <div ref={switcherRef} className="relative mx-4 mb-3.5">
                    <button
                        type="button"
                        onClick={() => setSwitcherOpen((o) => !o)}
                        className="flex w-full cursor-pointer items-center justify-between rounded-[10px] border-2 border-gray-200 px-2.5 py-2 hover:border-teal-200"
                    >
                        <div className="flex min-w-0 items-center gap-2 text-[13px] font-bold text-gray-800">
                            <svg
                                width="16"
                                height="16"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="#0D9488"
                                strokeWidth="2.2"
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                className="flex-none"
                            >
                                <path d="M3 21h18" />
                                <path d="M5 21V7l8-4v18" />
                                <path d="M19 21V11l-6-4" />
                            </svg>
                            <span className="truncate">{workspace?.name ?? '—'}</span>
                        </div>
                        <svg
                            width="15"
                            height="15"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="#9CA3AF"
                            strokeWidth="2.2"
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            className="flex-none"
                        >
                            <polyline points="6 9 12 15 18 9" />
                        </svg>
                    </button>
                    {switcherOpen && (
                        <div className="absolute top-full right-0 left-0 z-30 mt-1 overflow-hidden rounded-[10px] border-2 border-gray-200 bg-white shadow-lg">
                            {workspaces.map((w) => (
                                <button
                                    key={w.id}
                                    type="button"
                                    onClick={() => {
                                        setSwitcherOpen(false);
                                        if (w.id !== workspace?.id) router.post(route('workspace.switch'), { workspace_id: w.id });
                                    }}
                                    className={`flex w-full items-center justify-between px-2.5 py-2 text-left text-[13px] hover:bg-teal-50 ${
                                        w.id === workspace?.id ? 'bg-teal-50/60 font-bold text-teal-700' : 'font-medium text-gray-700'
                                    }`}
                                >
                                    <span className="truncate">{w.name}</span>
                                    <span className="ml-2 flex-none text-[10px] font-semibold tracking-wide text-gray-400 uppercase">{w.role}</span>
                                </button>
                            ))}
                        </div>
                    )}
                </div>

                <nav className="flex flex-1 flex-col gap-0.5 overflow-auto px-3">
                    <div className="px-2.5 pt-1.5 pb-1 text-[11px] font-bold tracking-[0.08em] text-gray-400">PRESALES</div>
                    <NavItem
                        href={route('dashboard')}
                        active={active === 'projects'}
                        icon={icons.grid}
                        label="Proyek"
                        badge={
                            <span className="ml-auto rounded-full bg-gray-100 px-2 py-px font-mono text-[11px] font-semibold text-gray-500">
                                {workspace?.projects_count ?? 0}
                            </span>
                        }
                    />
                    <NavItem href={route('templates.index')} active={active === 'templates'} icon={icons.file} label="Template Perusahaan" />
                    <NavItem href={route('ratecards.index')} active={active === 'ratecard'} icon={icons.card} label="Rate Card" />
                    <div className="px-2.5 pt-3.5 pb-1 text-[11px] font-bold tracking-[0.08em] text-gray-400">WORKSPACE</div>
                    <NavItem href={route('team.index')} active={active === 'team'} icon={icons.users} label="Tim & Klien" />
                    <NavItem href={route('profile.edit')} active={active === 'settings'} icon={icons.settings} label="Pengaturan" />
                </nav>

                <div className="mx-4 mt-3 mb-4 rounded-xl border border-gray-200 bg-gray-50 px-3.5 py-3">
                    <div className="text-[11px] font-bold tracking-wider text-gray-500 uppercase">Kredit blueprint</div>
                    <div className="mt-1 font-mono text-[15px] font-extrabold text-gray-900">
                        {credits} <span className="font-semibold text-gray-400">{quota ? `/ ${quota} blueprint` : 'blueprint'}</span>
                    </div>
                    <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-gray-200">
                        <div className="h-full rounded-full bg-teal-600" style={{ width: `${pct}%` }} />
                    </div>
                    <Link href={route('billing.index')} className="mt-2 block text-[11px] font-bold text-teal-700 hover:text-teal-900">
                        Upgrade / top-up →
                    </Link>
                </div>
            </aside>

            <div className="flex min-w-0 flex-1 flex-col">
                <div className="sticky top-0 z-20 flex h-[60px] flex-none items-center gap-3 border-b border-gray-200 bg-white px-7">
                    <div className="min-w-0 truncate text-[13px] font-semibold text-gray-400">
                        {workspace?.name} <span className="text-gray-300">/</span> <span className="font-bold text-gray-800">{crumb}</span>
                    </div>
                    <div className="flex-1" />
                    {/* ponytail: search visual-only, ⌘K palette belum ada di MVP */}
                    <div className="hidden w-[240px] items-center gap-2 overflow-hidden rounded-[10px] border-2 border-gray-200 bg-gray-50 px-3 py-[7px] whitespace-nowrap text-gray-400 md:flex">
                        <svg
                            width="15"
                            height="15"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            strokeWidth="2.2"
                            strokeLinecap="round"
                            strokeLinejoin="round"
                        >
                            <circle cx="11" cy="11" r="8" />
                            <line x1="21" y1="21" x2="16.65" y2="16.65" />
                        </svg>
                        <span className="text-[13px] font-medium">Cari proyek, dokumen…</span>
                        <span className="ml-auto rounded-[5px] border border-gray-200 bg-white px-1.5 font-mono text-[10px] font-semibold">⌘K</span>
                    </div>
                    <div className="relative flex h-9 w-9 flex-none items-center justify-center rounded-[10px] border-2 border-gray-200 text-gray-600">
                        <svg
                            width="18"
                            height="18"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            strokeWidth="2.2"
                            strokeLinecap="round"
                            strokeLinejoin="round"
                        >
                            <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9" />
                            <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0" />
                        </svg>
                    </div>
                    <button
                        onClick={() => router.post(route('logout'))}
                        className="rounded-[10px] border-2 border-gray-200 px-3 py-1.5 text-xs font-bold text-gray-500 hover:bg-gray-50"
                    >
                        Keluar
                    </button>
                    <div className="flex h-9 w-9 flex-none items-center justify-center rounded-[10px] bg-gradient-to-br from-teal-600 to-teal-400 text-[13px] font-extrabold text-white">
                        {initials}
                    </div>
                </div>
                <main className="w-full flex-1 p-7">{children}</main>
            </div>
        </div>
    );
}
