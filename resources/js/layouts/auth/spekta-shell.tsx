import AppLogoIcon from '@/components/app-logo-icon';
import { Link } from '@inertiajs/react';
import { PropsWithChildren, useState } from 'react';

const teal = {
    bg: '#031716',
    card: 'rgba(4,47,46,0.6)',
    line: 'rgba(94,234,212,0.2)',
    lineSoft: 'rgba(94,234,212,0.12)',
    text: '#D1FAF5',
    muted: '#99F6E4',
    accent: '#2DD4BF',
    accent2: '#5EEAD4',
};

export function SpektaAuthShell({ children, active }: PropsWithChildren<{ active: 'login' | 'register' }>) {
    const tab = (on: boolean) => ({
        flex: 1,
        border: 'none',
        borderRadius: 8,
        padding: '9px 0',
        fontSize: 13,
        fontWeight: 800,
        cursor: 'pointer',
        fontFamily: "'Sora',sans-serif",
        background: on ? teal.accent : 'transparent',
        color: on ? '#042F2E' : teal.muted,
        textAlign: 'center' as const,
        display: 'block',
        textDecoration: 'none',
    });

    return (
        <div
            style={{
                minHeight: '100vh',
                position: 'relative',
                display: 'flex',
                flexDirection: 'column',
                alignItems: 'center',
                justifyContent: 'center',
                padding: '40px 20px',
                boxSizing: 'border-box',
                fontSize: 14,
                fontWeight: 500,
                color: teal.text,
                overflow: 'hidden',
                background: teal.bg,
                fontFamily: 'ui-sans-serif,system-ui,sans-serif',
            }}
        >
            <div
                style={{
                    position: 'absolute',
                    inset: 0,
                    pointerEvents: 'none',
                    background: 'radial-gradient(640px 420px at 50% -80px,rgba(20,184,166,0.25),transparent 70%)',
                }}
            />
            <div
                style={{
                    position: 'absolute',
                    inset: 0,
                    pointerEvents: 'none',
                    opacity: 0.3,
                    backgroundImage:
                        'linear-gradient(rgba(94,234,212,0.07) 1px,transparent 1px),linear-gradient(90deg,rgba(94,234,212,0.07) 1px,transparent 1px)',
                    backgroundSize: '56px 56px',
                    maskImage: 'radial-gradient(700px 560px at 50% 30%,#000 30%,transparent 100%)',
                    WebkitMaskImage: 'radial-gradient(700px 560px at 50% 30%,#000 30%,transparent 100%)',
                }}
            />

            <Link href="/" style={{ position: 'relative', display: 'flex', alignItems: 'center', gap: 10, marginBottom: 30, textDecoration: 'none' }}>
                <div
                    style={{
                        width: 34,
                        height: 34,
                        borderRadius: 9,
                        background: 'linear-gradient(135deg,#14B8A6,#5EEAD4)',
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        color: '#042F2E',
                    }}
                >
                    <AppLogoIcon style={{ width: 20, height: 20 }} />
                </div>
                <span style={{ fontSize: 17, fontWeight: 800, color: '#fff', fontFamily: "'Sora',sans-serif", letterSpacing: '-0.01em' }}>
                    Spekta<span style={{ color: '#F5A623' }}>.</span>
                </span>
            </Link>

            <div
                style={{
                    position: 'relative',
                    width: '100%',
                    maxWidth: 420,
                    background: teal.card,
                    backdropFilter: 'blur(16px)',
                    border: `1px solid ${teal.line}`,
                    borderRadius: 20,
                    padding: 32,
                    boxSizing: 'border-box',
                    boxShadow: '0 30px 80px -20px rgba(0,0,0,0.6)',
                }}
            >
                <div
                    style={{
                        display: 'flex',
                        background: 'rgba(0,0,0,0.3)',
                        border: `1px solid ${teal.lineSoft}`,
                        borderRadius: 11,
                        padding: 4,
                        marginBottom: 26,
                    }}
                >
                    <Link href={route('login')} style={tab(active === 'login')}>
                        Masuk
                    </Link>
                    <Link href={route('register')} style={tab(active === 'register')}>
                        Daftar
                    </Link>
                </div>
                {children}
            </div>

            <div
                style={{
                    position: 'relative',
                    display: 'flex',
                    gap: 22,
                    marginTop: 26,
                    fontSize: 12,
                    fontWeight: 600,
                    color: teal.accent2,
                    opacity: 0.85,
                    flexWrap: 'wrap',
                    justifyContent: 'center',
                }}
            >
                <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round">
                        <rect x="3" y="11" width="18" height="11" rx="2" />
                        <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                    </svg>
                    Data terenkripsi
                </span>
                <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round">
                        <polyline points="20 6 9 17 4 12" />
                    </svg>
                    Spec Anda milik Anda
                </span>
                <Link href="/" style={{ color: teal.accent2 }}>
                    ← Beranda
                </Link>
            </div>
        </div>
    );
}

export const authField: React.CSSProperties = {
    width: '100%',
    boxSizing: 'border-box',
    border: '1px solid rgba(94,234,212,0.25)',
    borderRadius: 10,
    padding: '11px 13px',
    fontSize: 13.5,
    fontWeight: 500,
    color: '#fff',
    background: 'rgba(0,0,0,0.3)',
    outline: 'none',
};

export const authLabel: React.CSSProperties = {
    fontSize: 12,
    fontWeight: 700,
    color: '#CCFBF1',
    marginBottom: 6,
};

export const authCta: React.CSSProperties = {
    display: 'block',
    textAlign: 'center',
    width: '100%',
    marginTop: 22,
    background: '#2DD4BF',
    color: '#042F2E',
    border: 'none',
    borderRadius: 11,
    padding: '13px 0',
    fontSize: 14,
    fontWeight: 800,
    boxSizing: 'border-box',
    fontFamily: "'Sora',sans-serif",
    boxShadow: '0 8px 28px rgba(45,212,191,0.3)',
    cursor: 'pointer',
};

export const authError: React.CSSProperties = {
    color: '#FCA5A5',
    fontSize: 12,
    marginTop: 6,
};

// Tombol Google + divider "ATAU DENGAN EMAIL" per design Auth v2.
// ponytail: OAuth Google belum ada di MVP — tombol disabled dengan label segera.
export function GoogleDivider() {
    return (
        <>
            <button
                type="button"
                disabled
                title="Login Google segera hadir"
                style={{
                    width: '100%',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    gap: 10,
                    background: '#fff',
                    border: 'none',
                    color: '#1F2937',
                    borderRadius: 11,
                    padding: '12px 0',
                    fontSize: 13.5,
                    fontWeight: 700,
                    marginTop: 22,
                    boxSizing: 'border-box',
                    cursor: 'not-allowed',
                    opacity: 0.75,
                }}
            >
                <svg width="17" height="17" viewBox="0 0 24 24">
                    <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.27-4.74 3.27-8.1z" />
                    <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84A11 11 0 0 0 12 23z" />
                    <path fill="#FBBC05" d="M5.84 14.1A6.6 6.6 0 0 1 5.5 12c0-.73.13-1.44.34-2.1V7.06H2.18A11 11 0 0 0 1 12c0 1.78.43 3.45 1.18 4.94l3.66-2.84z" />
                    <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1A11 11 0 0 0 2.18 7.06l3.66 2.84C6.71 7.31 9.14 5.38 12 5.38z" />
                </svg>
                Lanjutkan dengan Google <span style={{ fontSize: 10, fontWeight: 800, color: '#0D9488' }}>SEGERA</span>
            </button>
            <div style={{ display: 'flex', alignItems: 'center', gap: 12, margin: '20px 0' }}>
                <div style={{ flex: 1, height: 1, background: 'rgba(94,234,212,0.15)' }} />
                <span style={{ fontSize: 10.5, fontWeight: 700, letterSpacing: '0.1em', color: '#5EEAD4', opacity: 0.7 }}>ATAU DENGAN EMAIL</span>
                <div style={{ flex: 1, height: 1, background: 'rgba(94,234,212,0.15)' }} />
            </div>
        </>
    );
}

// Input kata sandi dengan eye toggle per design Auth v2.
export function AuthPasswordInput({
    value,
    onChange,
    autoComplete,
    placeholder = '••••••••',
}: {
    value: string;
    onChange: (v: string) => void;
    autoComplete: string;
    placeholder?: string;
}) {
    const [show, setShow] = useState(false);
    return (
        <div style={{ position: 'relative' }}>
            <input
                type={show ? 'text' : 'password'}
                required
                autoComplete={autoComplete}
                placeholder={placeholder}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                style={{ ...authField, paddingRight: 42 }}
            />
            <span
                onClick={() => setShow(!show)}
                title={show ? 'Sembunyikan' : 'Tampilkan'}
                style={{
                    position: 'absolute',
                    right: 12,
                    top: '50%',
                    transform: 'translateY(-50%)',
                    color: '#5EEAD4',
                    opacity: 0.7,
                    cursor: 'pointer',
                    display: 'flex',
                }}
            >
                {show ? (
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
                        <path d="M9.88 9.88a3 3 0 1 0 4.24 4.24" />
                        <path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68" />
                        <path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61" />
                        <line x1="2" y1="2" x2="22" y2="22" />
                    </svg>
                ) : (
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
                        <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z" />
                        <circle cx="12" cy="12" r="3" />
                    </svg>
                )}
            </span>
        </div>
    );
}
