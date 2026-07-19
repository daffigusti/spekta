<!DOCTYPE html>
<html lang="id" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title') — Spekta</title>
<meta name="robots" content="noindex">
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<style>
:root{--bg:#0B0D0D;--heading:#fff;--text:#DDE1E0;--muted:#A9B1AF;--accent:#2DD4BF;--line:rgba(255,255,255,0.10)}
html[data-theme="light"]{--bg:#F8FAFA;--heading:#111827;--text:#374151;--muted:#6B7280;--accent:#0D9488;--line:#E5E7EB}
body{margin:0;background:var(--bg);color:var(--text);font-family:ui-sans-serif,system-ui,-apple-system,sans-serif;font-size:14.5px;line-height:1.75;-webkit-font-smoothing:antialiased}
main{max-width:720px;margin:0 auto;padding:56px 24px 80px}
h1{color:var(--heading);font-size:28px;letter-spacing:-0.02em;margin:0 0 6px}
h3{color:var(--heading);font-size:15px;margin:26px 0 6px}
p,li{color:var(--muted)}
a{color:var(--accent);text-decoration:none}a:hover{text-decoration:underline}
.top{font-size:13px;font-weight:600;display:inline-block;margin-bottom:28px}
.updated{font-size:12.5px;color:var(--muted);opacity:0.7;border-bottom:1px solid var(--line);padding-bottom:20px;margin-bottom:8px}
.xlink{border-top:1px solid var(--line);margin-top:40px;padding-top:18px;font-size:13px}
</style>
</head>
<body>
<main>
  <a class="top" href="/">← Kembali ke beranda</a>
  <h1>@yield('title')</h1>
  <p class="updated">Spekta — produk PT Amanah Karya Indonesia · Terakhir diperbarui: {{ date('j F Y') }}</p>
  @yield('content')
  <p class="xlink">Lihat juga: @yield('crosslink')</p>
</main>
<script>
try {
  const saved = localStorage.getItem('spekta-theme');
  if (saved) document.documentElement.setAttribute('data-theme', saved);
} catch (e) {}
</script>
</body>
</html>
