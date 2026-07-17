<!DOCTYPE html>
<html lang="id" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Spekta — Meeting selesai, spec &amp; RAB ikut selesai</title>
<meta name="description" content="AI presales engine untuk software house Indonesia. Tempel transkrip meeting, dapatkan 11 dokumen spesifikasi + estimasi man-days & RAB.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
<style>
:root{--bg:#031716;--heading:#fff;--text:#D1FAF5;--text-strong:#CCFBF1;--muted:#99F6E4;--accent:#2DD4BF;--accent-2:#5EEAD4;--btn-bg:#2DD4BF;--btn-bg-h:#5EEAD4;--btn-text:#042F2E;--line:rgba(94,234,212,0.16);--line-soft:rgba(94,234,212,0.12);--line-strong:rgba(94,234,212,0.28);--line-accent:rgba(45,212,191,0.4);--line-accent-strong:rgba(45,212,191,0.6);--card-bg:rgba(255,255,255,0.02);--tint-1:rgba(45,212,191,0.07);--tint-2:rgba(45,212,191,0.13);--tint-deep:rgba(4,47,46,0.4);--glow:rgba(20,184,166,0.25);--grid-line:rgba(94,234,212,0.07);--nav-bg:rgba(4,47,46,0.75);--shadow-nav:0 8px 32px rgba(0,0,0,0.35);--shadow-panel:0 40px 90px -20px rgba(0,0,0,0.6);--accent-shadow:rgba(45,212,191,0.35);--code-bg:rgba(0,0,0,0.3);--panel-bg:linear-gradient(180deg,#062E2B,#04211F);--panel-bg-2:#04211F;--ring-track:rgba(255,255,255,0.1);--logo-grad:linear-gradient(135deg,#14B8A6,#5EEAD4);--headline-grad:linear-gradient(90deg,#2DD4BF,#5EEAD4 55%,#A7F3D0);--badge-bg:rgba(94,234,212,0.08)}
html[data-theme="light"]{--bg:#F8FAFA;--heading:#111827;--text:#374151;--text-strong:#1F2937;--muted:#6B7280;--accent:#0D9488;--accent-2:#0F766E;--btn-bg:#0D9488;--btn-bg-h:#0F766E;--btn-text:#fff;--line:#E5E7EB;--line-soft:#F3F4F6;--line-strong:#D1D5DB;--line-accent:#99F6E4;--line-accent-strong:#0D9488;--card-bg:#fff;--tint-1:rgba(13,148,136,0.05);--tint-2:rgba(13,148,136,0.09);--tint-deep:rgba(13,148,136,0.05);--glow:rgba(13,148,136,0.12);--grid-line:rgba(13,148,136,0.06);--nav-bg:rgba(255,255,255,0.85);--shadow-nav:0 8px 32px rgba(15,23,42,0.1);--shadow-panel:0 40px 90px -20px rgba(13,148,136,0.2);--accent-shadow:rgba(13,148,136,0.25);--code-bg:#F3F4F6;--panel-bg:#fff;--panel-bg-2:#fff;--ring-track:#E5E7EB;--logo-grad:linear-gradient(135deg,#0D9488,#2DD4BF);--headline-grad:linear-gradient(90deg,#0D9488,#0F766E);--badge-bg:rgba(13,148,136,0.06)}
html,body{margin:0;padding:0;background:var(--bg);-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale;text-rendering:optimizeLegibility}
body,input,button{font-family:ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,sans-serif}
a{color:var(--accent);text-decoration:none}a:hover{color:var(--accent-2)}
.wrap{min-height:100vh;font-size:15px;font-weight:500;color:var(--text);overflow-x:hidden}
.sora{font-family:'Sora',sans-serif}
.mono{font-family:'JetBrains Mono',monospace}

/* nav */
.nav-shell{position:sticky;top:14px;z-index:40;display:flex;justify-content:center;padding:0 20px}
.nav{display:flex;align-items:center;gap:22px;background:var(--nav-bg);backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);border:1px solid var(--line);border-radius:9999px;padding:9px 10px 9px 18px;box-shadow:var(--shadow-nav)}
.logo{width:28px;height:28px;border-radius:8px;background:var(--logo-grad);display:flex;align-items:center;justify-content:center;color:#042F2E;font-weight:800;font-size:13px;font-family:'Sora',sans-serif}
.nav-links{display:flex;gap:18px;font-size:13px;font-weight:600}
.nav-links a{color:var(--muted)}.nav-links a:hover{color:var(--accent)}
.theme-btn{width:32px;height:32px;flex:none;border-radius:50%;border:1px solid var(--line);background:transparent;color:var(--muted);display:flex;align-items:center;justify-content:center;cursor:pointer;padding:0}
.theme-btn:hover{color:var(--accent);border-color:var(--accent)}
html[data-theme="dark"] .icon-moon{display:none}
html[data-theme="light"] .icon-sun{display:none}
.btn-pill{background:var(--btn-bg);color:var(--btn-text);border-radius:9999px;padding:8px 18px;font-size:13px;font-weight:800;cursor:pointer}
.btn-pill:hover{background:var(--btn-bg-h);color:var(--btn-text)}
.btn-primary{background:var(--btn-bg);color:var(--btn-text);border-radius:12px;padding:15px 28px;font-size:15px;font-weight:800;box-shadow:0 8px 32px var(--accent-shadow)}
.btn-primary:hover{background:var(--btn-bg-h);color:var(--btn-text)}
.btn-ghost{border:1px solid var(--line-strong);color:var(--text-strong);border-radius:12px;padding:14px 26px;font-size:15px;font-weight:700;background:var(--card-bg)}
.btn-ghost:hover{border-color:var(--accent);color:var(--heading)}
.feat-sm{border:1px solid var(--line-soft);border-radius:16px;padding:24px;background:var(--card-bg)}
.feat-sm:hover{border-color:var(--line-accent);background:var(--tint-1)}
.plan{border:1px solid var(--line);border-radius:18px;padding:26px;background:var(--card-bg);position:relative}
.pf{display:flex;gap:9px;font-size:13px;font-weight:500;color:var(--text-strong);line-height:1.5}
.pf svg{flex:none;margin-top:2px}
.plan-cta{display:block;text-align:center;width:100%;margin-top:24px;border-radius:11px;padding:12px 0;font-size:13.5px;font-weight:800;box-sizing:border-box;border:1px solid var(--line-strong);background:transparent;color:var(--text-strong)}
.plan-cta:hover{border-color:var(--accent);color:var(--heading)}
.plan-cta.primary{border:1px solid transparent;background:var(--btn-bg);color:var(--btn-text)}
.plan-cta.primary:hover{background:var(--btn-bg-h);color:var(--btn-text)}

@keyframes floaty{0%,100%{transform:translateY(0)}50%{transform:translateY(-10px)}}
@media (prefers-reduced-motion:reduce){.floaty{animation:none !important}}
@media (max-width:640px){h1.hero{font-size:38px !important}.nav-links{display:none !important}}
</style>
</head>
<body>
<div class="wrap">

  <!-- NAV -->
  <div class="nav-shell">
    <nav class="nav">
      <div style="display:flex;align-items:center;gap:9px">
        <div class="logo">S</div>
        <span class="sora" style="font-size:15px;font-weight:800;color:var(--heading);letter-spacing:-0.01em">Spekta</span>
      </div>
      <div class="nav-links">
        <a href="#fitur">Fitur</a>
        <a href="#cara">Cara kerja</a>
        <a href="#harga">Harga</a>
      </div>
      <button class="theme-btn" title="Ganti tema" onclick="toggleTheme()">
        <svg class="icon-sun" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2"></path><path d="M12 20v2"></path><path d="m4.93 4.93 1.41 1.41"></path><path d="m17.66 17.66 1.41 1.41"></path><path d="M2 12h2"></path><path d="M20 12h2"></path><path d="m6.34 17.66-1.41 1.41"></path><path d="m19.07 4.93-1.41 1.41"></path></svg>
        <svg class="icon-moon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"></path></svg>
      </button>
      <a href="{{ route('login') }}" style="font-size:13px;font-weight:700;color:var(--text-strong)">Masuk</a>
      <a class="btn-pill" href="{{ route('register') }}">Coba gratis</a>
    </nav>
  </div>

  <!-- HERO -->
  <header style="position:relative;padding:84px 24px 40px;text-align:center">
    <div style="position:absolute;inset:0;pointer-events:none;background:radial-gradient(720px 420px at 50% -60px,var(--glow),transparent 70%)"></div>
    <div style="position:absolute;inset:0;pointer-events:none;opacity:0.35;background-image:linear-gradient(var(--grid-line) 1px,transparent 1px),linear-gradient(90deg,var(--grid-line) 1px,transparent 1px);background-size:56px 56px;-webkit-mask-image:radial-gradient(760px 500px at 50% 0,#000 40%,transparent 100%);mask-image:radial-gradient(760px 500px at 50% 0,#000 40%,transparent 100%)"></div>
    <div style="position:relative;max-width:900px;margin:0 auto">
      <div style="display:inline-flex;align-items:center;gap:8px;border:1px solid var(--line-strong);background:var(--badge-bg);color:var(--accent-2);border-radius:9999px;padding:7px 16px;font-size:12.5px;font-weight:700">
        <span style="width:7px;height:7px;border-radius:50%;background:var(--accent);box-shadow:0 0 12px var(--accent)"></span>
        AI presales engine untuk software house Indonesia
      </div>
      <h1 class="sora hero" style="margin:26px auto 0;max-width:860px;font-size:58px;line-height:1.08;font-weight:800;letter-spacing:-0.035em;color:var(--heading);text-wrap:balance">Meeting selesai,<br><span style="background:var(--headline-grad);-webkit-background-clip:text;background-clip:text;color:transparent">spec &amp; RAB ikut selesai.</span></h1>
      <p style="margin:22px auto 0;max-width:600px;font-size:17px;line-height:1.7;color:var(--muted);text-wrap:pretty">Tempel transkrip meeting klien — Spekta mengekstrak requirement, menanyakan yang belum jelas, lalu men-generate 11 dokumen spesifikasi + estimasi man-days &amp; RAB dari rate card Anda.</p>
      <div style="display:flex;gap:12px;justify-content:center;margin-top:32px;flex-wrap:wrap">
        <a class="btn-primary" href="{{ route('register') }}">Buat blueprint pertama →</a>
        <a class="btn-ghost" href="#demo">Lihat demo live</a>
      </div>
      <div style="display:flex;gap:32px;justify-content:center;margin-top:40px;flex-wrap:wrap">
        <div><div class="mono" style="font-size:26px;font-weight:700;color:var(--heading)">15 mnt</div><div style="font-size:12px;font-weight:600;color:var(--accent-2);margin-top:3px;letter-spacing:0.04em">MEETING → PROPOSAL</div></div>
        <div><div class="mono" style="font-size:26px;font-weight:700;color:var(--heading)">11 docs</div><div style="font-size:12px;font-weight:600;color:var(--accent-2);margin-top:3px;letter-spacing:0.04em">SEKALI GENERATE</div></div>
        <div><div class="mono" style="font-size:26px;font-weight:700;color:var(--heading)">±15%</div><div style="font-size:12px;font-weight:600;color:var(--accent-2);margin-top:3px;letter-spacing:0.04em">AKURASI ESTIMASI</div></div>
      </div>
    </div>

    <!-- product shot -->
    <div class="floaty" style="position:relative;max-width:960px;margin:56px auto 0;animation:floaty 7s ease-in-out infinite">
      <div style="position:absolute;inset:-40px -60px;background:radial-gradient(60% 70% at 50% 60%,var(--glow),transparent 75%);pointer-events:none"></div>
      <div style="position:relative;border:1px solid var(--line-strong);border-radius:18px;background:var(--panel-bg);box-shadow:var(--shadow-panel);overflow:hidden;text-align:left">
        <div style="display:flex;align-items:center;gap:7px;border-bottom:1px solid var(--line-soft);padding:12px 18px">
          <span style="width:10px;height:10px;border-radius:50%;background:var(--line-strong)"></span><span style="width:10px;height:10px;border-radius:50%;background:var(--line-strong)"></span><span style="width:10px;height:10px;border-radius:50%;background:var(--line-strong)"></span>
          <span class="mono" style="margin-left:10px;background:var(--code-bg);border:1px solid var(--line);border-radius:6px;font-size:11px;font-weight:500;color:var(--accent-2);padding:3px 12px">app.spekta.id / kasir-pintar / estimasi</span>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px;padding:22px">
          <div style="border:1px solid var(--line);border-radius:12px;padding:16px;background:var(--card-bg)">
            <div style="font-size:10px;font-weight:700;letter-spacing:0.1em;color:var(--accent-2)">TOTAL EFFORT</div>
            <div class="mono" style="font-size:26px;font-weight:700;color:var(--heading);margin-top:7px">124 <span style="font-size:12px;color:var(--muted)">MD</span></div>
            <div style="font-size:11px;color:var(--muted);opacity:0.7;margin-top:3px">±15%: 108–142 MD</div>
          </div>
          <div style="border:1px solid var(--line-accent);border-radius:12px;padding:16px;background:var(--tint-1)">
            <div style="font-size:10px;font-weight:700;letter-spacing:0.1em;color:var(--accent-2)">ESTIMASI BIAYA (RAB)</div>
            <div class="mono" style="font-size:26px;font-weight:700;color:var(--accent-2);margin-top:7px">Rp 486 jt</div>
            <div style="font-size:11px;color:var(--muted);opacity:0.7;margin-top:3px">rate card Anda · margin 30%</div>
          </div>
          <div style="border:1px solid var(--line);border-radius:12px;padding:16px;background:var(--card-bg)">
            <div style="font-size:10px;font-weight:700;letter-spacing:0.1em;color:var(--accent-2)">SPEC HEALTH</div>
            <div style="display:flex;align-items:center;gap:10px;margin-top:7px">
              <div style="width:40px;height:40px;border-radius:50%;background:conic-gradient(var(--accent) 92%,var(--ring-track) 0);display:flex;align-items:center;justify-content:center;flex:none"><div class="mono" style="width:30px;height:30px;border-radius:50%;background:var(--panel-bg-2);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:var(--heading)">92</div></div>
              <div style="font-size:11px;font-weight:600;color:var(--muted);line-height:1.5">14/14 FR tervalidasi<br>0 kontradiksi</div>
            </div>
          </div>
        </div>
        <div class="mono" style="padding:0 22px 20px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:11px;font-weight:500;color:var(--muted)">
          <span style="color:var(--accent-2)">PRD.md</span>·<span>REQUIREMENTS.md</span>·<span>API.md</span>·<span>ARCHITECTURE.md</span>·<span>TESTING.md</span>·<span style="opacity:0.6">+6 lainnya</span>
          <span style="margin-left:auto;background:var(--tint-2);color:var(--accent-2);border-radius:9999px;padding:3px 12px;font-size:10px;font-weight:700;font-family:ui-sans-serif,system-ui,sans-serif">✓ 11 DOKUMEN KONSISTEN</span>
        </div>
      </div>
    </div>
  </header>

  <!-- FEATURES BENTO -->
  <section id="fitur" style="max-width:1100px;margin:0 auto;padding:80px 24px 20px">
    <div style="max-width:560px">
      <div class="mono" style="font-size:12px;font-weight:700;letter-spacing:0.12em;color:var(--accent)">// FITUR</div>
      <h2 class="sora" style="margin:14px 0 0;font-size:36px;font-weight:800;letter-spacing:-0.025em;color:var(--heading);text-wrap:balance">Scope creep berhenti di dokumen yang benar</h2>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:14px;margin-top:36px">
      <div style="grid-column:1/-1;display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:14px">
        <div style="border:1px solid var(--line);border-radius:16px;padding:28px;background:linear-gradient(160deg,var(--tint-2),var(--card-bg) 60%)">
          <div class="mono" style="font-size:11px;font-weight:700;color:var(--accent);letter-spacing:0.1em">01 · MEETING-TO-SPEC</div>
          <div class="sora" style="font-size:20px;font-weight:700;color:var(--heading);margin-top:10px">Transkrip masuk, requirement keluar</div>
          <div style="font-size:13.5px;line-height:1.7;color:var(--muted);margin-top:8px;text-wrap:pretty">Fireflies, notulen, chat WhatsApp — fitur, role, dan asumsi terekstrak otomatis, tiap item tertaut timestamp sumber di meeting.</div>
          <div class="mono" style="margin-top:18px;border:1px solid var(--line);border-radius:10px;padding:12px 14px;background:var(--code-bg);font-size:11.5px;color:var(--muted);line-height:1.9">
            <span style="color:var(--accent-2)">✓ Transaksi kasir multi-cabang</span> <span style="opacity:0.55">— 00:04:12</span><br>
            <span style="color:var(--accent-2)">✓ Pembayaran QRIS terintegrasi</span> <span style="opacity:0.55">— 00:11:38</span><br>
            <span style="color:var(--accent-2)">✓ Prediksi stok (AI)</span> <span style="opacity:0.55">— 00:27:41</span>
          </div>
        </div>
        <div style="border:1px solid var(--line);border-radius:16px;padding:28px;background:linear-gradient(160deg,var(--tint-2),var(--card-bg) 60%)">
          <div class="mono" style="font-size:11px;font-weight:700;color:var(--accent);letter-spacing:0.1em">02 · ESTIMATOR</div>
          <div class="sora" style="font-size:20px;font-weight:700;color:var(--heading);margin-top:10px">RAB dari rate card Anda, bukan tebakan</div>
          <div style="font-size:13.5px;line-height:1.7;color:var(--muted);margin-top:8px;text-wrap:pretty">Effort per fitur dihitung dari struktur, dikalikan rate per role + margin perusahaan. Skenario MVP vs full scope satu klik.</div>
          <div style="margin-top:18px;display:flex;gap:10px;flex-wrap:wrap">
            <div class="mono" style="border:1px solid var(--accent-shadow);background:var(--tint-2);border-radius:10px;padding:10px 16px;font-size:13px;font-weight:700;color:var(--accent-2)">Full · Rp 486 jt</div>
            <div class="mono" style="border:1px solid var(--line);border-radius:10px;padding:10px 16px;font-size:13px;font-weight:700;color:var(--muted)">MVP · Rp 313 jt</div>
          </div>
        </div>
      </div>
      <div class="feat-sm">
        <div class="mono" style="font-size:11px;font-weight:700;color:var(--accent);letter-spacing:0.1em">03 · INTERVIEW ADAPTIF</div>
        <div class="sora" style="font-size:16.5px;font-weight:700;color:var(--heading);margin-top:9px">Bertanya hanya yang belum jelas</div>
        <div style="font-size:13px;line-height:1.65;color:var(--muted);margin-top:7px;text-wrap:pretty">Pertanyaan yang di-skip jadi asumsi eksplisit di PRD — pelindung Anda saat dispute scope.</div>
      </div>
      <div class="feat-sm">
        <div class="mono" style="font-size:11px;font-weight:700;color:var(--accent);letter-spacing:0.1em">04 · REGENERASI SELEKTIF</div>
        <div class="sora" style="font-size:16.5px;font-weight:700;color:var(--heading);margin-top:9px">Ubah satu FR, sisanya ikut sinkron</div>
        <div style="font-size:13px;line-height:1.65;color:var(--muted);margin-top:7px;text-wrap:pretty">Impact analysis ke API, testing, timeline, dan RAB — hanya dokumen terdampak yang ditulis ulang.</div>
      </div>
      <div class="feat-sm">
        <div class="mono" style="font-size:11px;font-weight:700;color:var(--accent);letter-spacing:0.1em">05 · CLIENT PORTAL</div>
        <div class="sora" style="font-size:16.5px;font-weight:700;color:var(--heading);margin-top:9px">Approval &amp; scope lock</div>
        <div style="font-size:13px;line-height:1.65;color:var(--muted);margin-top:7px;text-wrap:pretty">Klien membaca versi non-teknis, komentar per bagian, approve. Setelahnya semua perubahan = Change Request.</div>
      </div>
      <div class="feat-sm">
        <div class="mono" style="font-size:11px;font-weight:700;color:var(--accent);letter-spacing:0.1em">06 · SIAP AI CODING</div>
        <div class="sora" style="font-size:16.5px;font-weight:700;color:var(--heading);margin-top:9px">Export ke agen &amp; tim</div>
        <div style="font-size:13px;line-height:1.65;color:var(--muted);margin-top:7px;text-wrap:pretty">CLAUDE.md, .cursorrules, AGENTS.md, atau push task ke ClickUp/Jira — spec langsung jadi konteks kerja.</div>
      </div>
    </div>
  </section>

  <!-- HOW -->
  <section id="cara" style="max-width:1100px;margin:0 auto;padding:80px 24px 20px">
    <div style="display:flex;align-items:flex-end;justify-content:space-between;gap:20px;flex-wrap:wrap">
      <div style="max-width:520px">
        <div class="mono" style="font-size:12px;font-weight:700;letter-spacing:0.12em;color:var(--accent)">// CARA KERJA</div>
        <h2 class="sora" style="margin:14px 0 0;font-size:36px;font-weight:800;letter-spacing:-0.025em;color:var(--heading)">15 menit dari input ke proposal</h2>
      </div>
      <div style="font-size:13.5px;color:var(--muted);max-width:340px;line-height:1.65">Setiap langkah bisa di-review dan diedit — AI mengerjakan draf, Anda memegang keputusan.</div>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-top:36px">
      <div style="border:1px solid var(--line-soft);border-radius:14px;padding:18px;background:var(--card-bg)">
        <div class="mono" style="width:30px;height:30px;border-radius:9px;background:var(--tint-2);border:1px solid var(--line-accent);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:var(--accent-2)">01</div>
        <div class="sora" style="font-size:15px;font-weight:700;color:var(--heading);margin-top:12px">Input</div>
        <div style="font-size:12.5px;line-height:1.6;color:var(--muted);margin-top:5px;opacity:0.85">Ide, transkrip meeting, atau RFP</div>
      </div>
      <div style="border:1px solid var(--line-soft);border-radius:14px;padding:18px;background:var(--card-bg)">
        <div class="mono" style="width:30px;height:30px;border-radius:9px;background:var(--tint-2);border:1px solid var(--line-accent);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:var(--accent-2)">02</div>
        <div class="sora" style="font-size:15px;font-weight:700;color:var(--heading);margin-top:12px">Interview</div>
        <div style="font-size:12.5px;line-height:1.6;color:var(--muted);margin-top:5px;opacity:0.85">AI bertanya hanya yang belum jelas</div>
      </div>
      <div style="border:1px solid var(--line-soft);border-radius:14px;padding:18px;background:var(--card-bg)">
        <div class="mono" style="width:30px;height:30px;border-radius:9px;background:var(--tint-2);border:1px solid var(--line-accent);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:var(--accent-2)">03</div>
        <div class="sora" style="font-size:15px;font-weight:700;color:var(--heading);margin-top:12px">Struktur</div>
        <div style="font-size:12.5px;line-height:1.6;color:var(--muted);margin-top:5px;opacity:0.85">Canvas fitur — geser, hapus, tandai MVP</div>
      </div>
      <div style="border:1px solid var(--line-soft);border-radius:14px;padding:18px;background:var(--card-bg)">
        <div class="mono" style="width:30px;height:30px;border-radius:9px;background:var(--tint-2);border:1px solid var(--line-accent);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:var(--accent-2)">04</div>
        <div class="sora" style="font-size:15px;font-weight:700;color:var(--heading);margin-top:12px">Stack</div>
        <div style="font-size:12.5px;line-height:1.6;color:var(--muted);margin-top:5px;opacity:0.85">Rekomendasi sesuai skala nyata klien</div>
      </div>
      <div style="border:1px solid var(--line-soft);border-radius:14px;padding:18px;background:var(--card-bg)">
        <div class="mono" style="width:30px;height:30px;border-radius:9px;background:var(--tint-2);border:1px solid var(--line-accent);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:var(--accent-2)">05</div>
        <div class="sora" style="font-size:15px;font-weight:700;color:var(--heading);margin-top:12px">Generate</div>
        <div style="font-size:12.5px;line-height:1.6;color:var(--muted);margin-top:5px;opacity:0.85">11 dokumen konsisten + validator</div>
      </div>
      <div style="border:1px solid var(--line-soft);border-radius:14px;padding:18px;background:var(--card-bg)">
        <div class="mono" style="width:30px;height:30px;border-radius:9px;background:var(--tint-2);border:1px solid var(--line-accent);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:var(--accent-2)">06</div>
        <div class="sora" style="font-size:15px;font-weight:700;color:var(--heading);margin-top:12px">Estimasi</div>
        <div style="font-size:12.5px;line-height:1.6;color:var(--muted);margin-top:5px;opacity:0.85">MD, RAB &amp; proposal siap kirim</div>
      </div>
    </div>
  </section>

  <!-- PRICING -->
  <section id="harga" style="max-width:1100px;margin:0 auto;padding:80px 24px 30px">
    <div style="text-align:center;max-width:560px;margin:0 auto 40px">
      <div class="mono" style="font-size:12px;font-weight:700;letter-spacing:0.12em;color:var(--accent)">// HARGA</div>
      <h2 class="sora" style="margin:14px 0 0;font-size:36px;font-weight:800;letter-spacing:-0.025em;color:var(--heading);text-wrap:balance">Sepersepuluh biaya satu proposal yang gagal</h2>
    </div>
    @php $check = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>'; @endphp
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;max-width:1100px;margin:0 auto">

      <div class="plan">
        <div class="sora" style="font-size:15px;font-weight:700;color:var(--heading)">Free</div>
        <div style="margin-top:12px;display:flex;align-items:baseline;flex-wrap:wrap;gap:2px 8px">
          <span class="mono" style="font-size:30px;font-weight:700;color:var(--heading);white-space:nowrap">Rp 0</span>
          <span style="font-size:13px;font-weight:600;color:var(--muted);white-space:nowrap">/ bulan</span>
        </div>
        <div style="font-size:12.5px;color:var(--muted);opacity:0.8;margin-top:5px">Untuk mencoba alurnya</div>
        <div style="display:flex;flex-direction:column;gap:10px;margin-top:20px">
          <div class="pf">{!! $check !!}2 blueprint / bulan</div>
          <div class="pf">{!! $check !!}10 AI chat / bulan</div>
          <div class="pf">{!! $check !!}1 anggota</div>
          <div class="pf">{!! $check !!}Export Markdown</div>
        </div>
        <a href="{{ route('register') }}" class="plan-cta">Mulai gratis</a>
      </div>

      <div class="plan">
        <div class="sora" style="font-size:15px;font-weight:700;color:var(--heading)">Starter</div>
        <div style="margin-top:12px;display:flex;align-items:baseline;flex-wrap:wrap;gap:2px 8px">
          <span class="mono" style="font-size:30px;font-weight:700;color:var(--heading);white-space:nowrap">Rp 149 rb</span>
          <span style="font-size:13px;font-weight:600;color:var(--muted);white-space:nowrap">/ bulan</span>
        </div>
        <div style="font-size:12.5px;color:var(--muted);opacity:0.8;margin-top:5px">Untuk freelancer &amp; studio kecil</div>
        <div style="display:flex;flex-direction:column;gap:10px;margin-top:20px">
          <div class="pf">{!! $check !!}8 blueprint / bulan</div>
          <div class="pf">{!! $check !!}100 AI chat / bulan</div>
          <div class="pf">{!! $check !!}3 anggota</div>
          <div class="pf">{!! $check !!}Export ZIP + agent pack</div>
        </div>
        <a href="{{ route('register') }}" class="plan-cta">Pilih Starter</a>
      </div>

      <div class="plan" style="border-color:var(--line-accent-strong);background:linear-gradient(170deg,var(--tint-2),var(--card-bg))">
        <span style="position:absolute;top:-11px;left:50%;transform:translateX(-50%);background:var(--btn-bg);color:var(--btn-text);font-size:10px;font-weight:800;letter-spacing:0.08em;border-radius:9999px;padding:4px 14px;white-space:nowrap">PALING POPULER</span>
        <div class="sora" style="font-size:15px;font-weight:700;color:var(--heading)">Pro</div>
        <div style="margin-top:12px;display:flex;align-items:baseline;flex-wrap:wrap;gap:2px 8px">
          <span class="mono" style="font-size:30px;font-weight:700;color:var(--heading);white-space:nowrap">Rp 399 rb</span>
          <span style="font-size:13px;font-weight:600;color:var(--muted);white-space:nowrap">/ bulan</span>
        </div>
        <div style="font-size:12.5px;color:var(--muted);opacity:0.8;margin-top:5px">Untuk software house aktif</div>
        <div style="display:flex;flex-direction:column;gap:10px;margin-top:20px">
          <div class="pf">{!! $check !!}25 blueprint / bulan</div>
          <div class="pf">{!! $check !!}400 AI chat / bulan</div>
          <div class="pf">{!! $check !!}10 anggota</div>
          <div class="pf">{!! $check !!}Client portal &amp; scope lock</div>
          <div class="pf">{!! $check !!}Estimator RAB + rate card</div>
        </div>
        <a href="{{ route('register') }}" class="plan-cta primary">Pilih Pro</a>
      </div>

      <div class="plan">
        <div class="sora" style="font-size:15px;font-weight:700;color:var(--heading)">Team</div>
        <div style="margin-top:12px;display:flex;align-items:baseline;flex-wrap:wrap;gap:2px 8px">
          <span class="mono" style="font-size:30px;font-weight:700;color:var(--heading);white-space:nowrap">Rp 249 rb</span>
          <span style="font-size:13px;font-weight:600;color:var(--muted);white-space:nowrap">/ seat / bulan</span>
        </div>
        <div style="font-size:12.5px;color:var(--muted);opacity:0.8;margin-top:5px">Min. 3 seat · untuk tim presales &amp; PM</div>
        <div style="display:flex;flex-direction:column;gap:10px;margin-top:20px">
          <div class="pf">{!! $check !!}Anggota sesuai seat</div>
          <div class="pf">{!! $check !!}Template &amp; rate card multi</div>
          <div class="pf">{!! $check !!}White-label penuh</div>
          <div class="pf">{!! $check !!}Integrasi ClickUp / Jira</div>
          <div class="pf">{!! $check !!}Audit log</div>
        </div>
        <a href="{{ route('register') }}" class="plan-cta">Hubungi kami</a>
      </div>
    </div>
  </section>

  <!-- CTA + FOOTER -->
  <footer id="daftar" style="max-width:1100px;margin:0 auto;padding:50px 24px 30px">
    <div style="position:relative;border:1px solid var(--line-accent);border-radius:22px;padding:56px 32px;text-align:center;overflow:hidden;background:linear-gradient(160deg,var(--tint-2),var(--tint-deep))">
      <div style="position:absolute;inset:0;pointer-events:none;background:radial-gradient(480px 260px at 50% 110%,var(--glow),transparent 70%)"></div>
      <h2 class="sora" style="position:relative;margin:0;font-size:34px;font-weight:800;letter-spacing:-0.025em;color:var(--heading);text-wrap:balance">Meeting berikutnya, bawa pulang spec-nya</h2>
      <p style="position:relative;margin:14px auto 0;max-width:460px;font-size:15px;line-height:1.65;color:var(--muted)">Tempel transkrip meeting terakhir Anda dan lihat blueprint pertama jadi dalam 15 menit.</p>
      <a class="btn-primary" href="{{ route('register') }}" style="position:relative;display:inline-block;margin-top:26px">Mulai gratis sekarang</a>
    </div>
    <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;padding:30px 4px 10px">
      <div style="display:flex;align-items:center;gap:9px">
        <div class="logo" style="width:24px;height:24px;border-radius:7px;font-size:11px">S</div>
        <span class="sora" style="font-size:13px;font-weight:700;color:var(--heading)">Spekta</span>
        <span style="font-size:12px;color:var(--muted);opacity:0.7">— produk PT Amanah Karya Indonesia</span>
      </div>
      <div style="margin-left:auto;display:flex;gap:18px;font-size:12.5px;font-weight:600">
        <a href="#fitur" style="color:var(--muted)">Fitur</a>
        <a href="#demo" style="color:var(--muted)">Demo app</a>
        <a href="{{ route('login') }}" style="color:var(--muted)">Masuk</a>
      </div>
    </div>
  </footer>
</div>

<script>
function toggleTheme() {
  const el = document.documentElement;
  const next = el.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
  el.setAttribute('data-theme', next);
  try { localStorage.setItem('spekta-theme', next); } catch (e) {}
}
try {
  const saved = localStorage.getItem('spekta-theme');
  if (saved) document.documentElement.setAttribute('data-theme', saved);
} catch (e) {}
</script>
</body>
</html>
