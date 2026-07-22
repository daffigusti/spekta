import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

import { authCta, authError, authField, authLabel, AuthPasswordInput, GoogleDivider, SpektaAuthShell } from '@/layouts/auth/spekta-shell';

type LoginForm = {
    email: string;
    password: string;
    remember: boolean;
};

export default function Login({ error, status }: { error?: string; status?: string; canResetPassword: boolean }) {
    const { data, setData, post, processing, errors, reset } = useForm<LoginForm>({
        email: '',
        password: '',
        remember: true,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('login'), { onFinish: () => reset('password') });
    };

    return (
        <SpektaAuthShell active="login">
            <Head title="Masuk — Spekta" />
            <h1 style={{ margin: 0, fontFamily: "'Sora',sans-serif", fontSize: 22, fontWeight: 800, letterSpacing: '-0.02em', color: '#fff' }}>
                Selamat datang kembali
            </h1>
            <div style={{ fontSize: 13, color: '#99F6E4', marginTop: 5, opacity: 0.85 }}>Lanjutkan blueprint yang sedang berjalan.</div>

            {error && (
                <div role="alert" aria-live="assertive" style={{ marginTop: 14, fontSize: 13, color: '#FCA5A5' }}>
                    {error}
                </div>
            )}

            {status && (
                <div role="status" style={{ marginTop: 14, fontSize: 13, color: '#5EEAD4' }}>
                    {status}
                </div>
            )}

            <GoogleDivider />

            <form onSubmit={submit}>
                <div style={authLabel}>Email kerja</div>
                <input
                    type="email"
                    required
                    autoFocus
                    autoComplete="email"
                    placeholder="nama@perusahaan.co.id"
                    value={data.email}
                    onChange={(e) => setData('email', e.target.value)}
                    style={authField}
                />
                {errors.email && <div style={authError}>{errors.email}</div>}

                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', margin: '14px 0 6px' }}>
                    <div style={{ ...authLabel, marginBottom: 0 }}>Kata sandi</div>
                    <Link href={route('password.request')} style={{ fontSize: 12, fontWeight: 600, color: '#2DD4BF' }}>
                        Lupa kata sandi?
                    </Link>
                </div>
                <AuthPasswordInput value={data.password} onChange={(v) => setData('password', v)} autoComplete="current-password" />
                {errors.password && <div style={authError}>{errors.password}</div>}

                <button type="submit" disabled={processing} style={{ ...authCta, opacity: processing ? 0.6 : 1 }}>
                    {processing ? 'Memproses…' : 'Masuk ke workspace'}
                </button>
            </form>
        </SpektaAuthShell>
    );
}
