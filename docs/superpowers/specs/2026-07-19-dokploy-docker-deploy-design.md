# Deploy Spekta ke Dokploy via Docker — Design

Tanggal: 2026-07-19. Status: disetujui.

## Keputusan

- **Database**: PostgreSQL sebagai Dokploy database service terpisah (bukan di compose).
- **Arsitektur**: Dokploy Compose project — service `web` (nginx+fpm) dan `worker` (queue) dari satu image.
- **Queue/cache/session**: semua driver `database` di Postgres. Tanpa Redis (YAGNI).
- **Scheduler/cron**: tidak ada scheduled task di `routes/console.php` — di-skip.

## Artefak

| File | Isi |
|---|---|
| `spekta-app/Dockerfile` | Multi-stage: `node:22-alpine` (vite build, mandiri — `ziggy-js` import type-only, di-strip esbuild) → `serversideup/php:8.4-fpm-nginx` + ekstensi `pdo_pgsql gd zip intl bcmath` (gd: dompdf, zip: phpspreadsheet/phpword), composer install --no-dev dua fase (layer cache), copy `public/build` dari stage node. |
| `spekta-app/docker-compose.yml` | `web`: build `.`, `image: spekta-app`, `AUTORUN_ENABLED=true` (serversideup: `migrate --force` + `storage:link` + config/route/view cache saat start), `env_file: .env` (ditulis Dokploy dari tab Environment). `worker`: image sama, `command: php artisan queue:work --tries=3 --timeout=600` (600 < `retry_after` 630 per BR repo), `depends_on: web: service_healthy` (tunggu migrasi). Volume `storage_app` → `/var/www/html/storage/app` (named volume; isi image ter-copy saat mount pertama). Network `dokploy-network` external (Traefik + Postgres). |
| `spekta-app/.dockerignore` | `.git`, `node_modules`, `vendor`, `public/build`, sqlite lokal, log. |
| `spekta-app/bootstrap/app.php` (edit) | `$middleware->trustProxies(at: '*')` — di belakang Traefik; tanpa ini scheme https tidak terdeteksi (redirect, ziggy URL, cookie secure). Aman karena hanya reachable via network internal Docker. |

## Env di Dokploy (tab Environment compose project)

```
APP_NAME=Spekta
APP_ENV=production
APP_DEBUG=false
APP_KEY=            # php artisan key:generate --show
APP_URL=https://<domain>
LOG_CHANNEL=stderr
DB_CONNECTION=pgsql
DB_HOST=<internal-host-postgres-dokploy>
DB_PORT=5432
DB_DATABASE=spekta
DB_USERNAME=...
DB_PASSWORD=...
QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database
ANTHROPIC_API_KEY=...
MIDTRANS_SERVER_KEY=...
MIDTRANS_CLIENT_KEY=...
MIDTRANS_IS_PRODUCTION=true
```

## Langkah Dokploy

1. Buat database PostgreSQL (catat internal host + kredensial).
2. Buat Compose project dari repo git **spekta-app** (repo terpisah dari repo docs — push dulu ke remote), compose path `./docker-compose.yml`.
3. Isi tab Environment (daftar di atas).
4. Deploy; tambah domain → service `web`, port `8080` (HTTPS on).
5. Webhook Midtrans arahkan ke `https://<domain>/midtrans/notify`.

## Batasan sadar

- Satu replika web + satu worker; scale horizontal butuh pindah queue/cache dari driver database (belum perlu).
- `AUTORUN` migrate hanya di `web`; worker menunggu healthy web sehingga tidak balapan migrasi.
