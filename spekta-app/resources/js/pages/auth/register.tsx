import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

import { authCta, authError, authField, authLabel, AuthPasswordInput, GoogleDivider, SpektaAuthShell } from '@/layouts/auth/spekta-shell';

type RegisterForm = {
    name: string;
    company: string;
    email: string;
    password: string;
    password_confirmation: string;
};

export default function Register() {
    const { data, setData, post, processing, errors, reset } = useForm<RegisterForm>({
        name: '',
        company: '',
        email: '',
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('register'), { onFinish: () => reset('password', 'password_confirmation') });
    };

    return (
        <SpektaAuthShell active="register">
            <Head title="Daftar — Spekta" />
            <h1 style={{ margin: 0, fontFamily: "'Sora',sans-serif", fontSize: 22, fontWeight: 800, letterSpacing: '-0.02em', color: '#fff' }}>
                Buat workspace Anda
            </h1>
            <div style={{ fontSize: 13, color: '#99F6E4', marginTop: 5, opacity: 0.85 }}>Gratis 2 blueprint / bulan · tanpa kartu kredit.</div>

            <GoogleDivider />

            <form onSubmit={submit}>
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12, marginBottom: 14 }}>
                    <div>
                        <div style={authLabel}>Nama lengkap</div>
                        <input
                            required
                            autoFocus
                            autoComplete="name"
                            placeholder="Muammar K."
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            style={authField}
                        />
                        {errors.name && <div style={authError}>{errors.name}</div>}
                    </div>
                    <div>
                        <div style={authLabel}>Perusahaan</div>
                        <input
                            required
                            placeholder="AmanahCorp"
                            value={data.company}
                            onChange={(e) => setData('company', e.target.value)}
                            style={authField}
                        />
                        {errors.company && <div style={authError}>{errors.company}</div>}
                    </div>
                </div>

                <div style={authLabel}>Email kerja</div>
                <input
                    type="email"
                    required
                    autoComplete="email"
                    placeholder="nama@perusahaan.co.id"
                    value={data.email}
                    onChange={(e) => setData('email', e.target.value)}
                    style={authField}
                />
                {errors.email && <div style={authError}>{errors.email}</div>}

                <div style={{ ...authLabel, marginTop: 14 }}>Kata sandi</div>
                <AuthPasswordInput value={data.password} onChange={(v) => setData('password', v)} autoComplete="new-password" />
                {errors.password && <div style={authError}>{errors.password}</div>}

                <div style={{ ...authLabel, marginTop: 14 }}>Ulangi kata sandi</div>
                <AuthPasswordInput
                    value={data.password_confirmation}
                    onChange={(v) => setData('password_confirmation', v)}
                    autoComplete="new-password"
                />

                <label
                    style={{
                        display: 'flex',
                        gap: 9,
                        marginTop: 16,
                        fontSize: 12,
                        fontWeight: 500,
                        color: '#99F6E4',
                        lineHeight: 1.55,
                        cursor: 'pointer',
                    }}
                >
                    <input type="checkbox" required style={{ marginTop: 2, accentColor: '#2DD4BF', width: 15, height: 15, flex: 'none' }} />
                    <span>
                        Saya setuju dengan{' '}
                        <a href="#" style={{ color: '#2DD4BF' }}>
                            Syarat Layanan
                        </a>{' '}
                        dan{' '}
                        <a href="#" style={{ color: '#2DD4BF' }}>
                            Kebijakan Privasi
                        </a>{' '}
                        Spekta.
                    </span>
                </label>

                <button type="submit" disabled={processing} style={{ ...authCta, opacity: processing ? 0.6 : 1 }}>
                    {processing ? 'Memproses…' : 'Buat akun & mulai'}
                </button>
            </form>
        </SpektaAuthShell>
    );
}
