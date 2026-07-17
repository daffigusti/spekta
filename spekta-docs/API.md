# API.md: Spectra

REST API `https://api.spectra.id/v1`. Auth: Bearer JWT (sesi Better Auth). Portal klien memakai token share + OTP session terpisah. Semua response JSON.

## Konvensi

**Sukses:**
```json
{ "success": true, "data": { ... }, "meta": { "page": 1, "per_page": 20, "total": 134 } }
```

**Error:**
```json
{ "success": false, "error": { "code": "VALIDATION_ERROR", "message": "…", "details": [{"field": "email", "issue": "invalid"}] } }
```

Kode error utama: `UNAUTHORIZED`, `FORBIDDEN`, `NOT_FOUND`, `VALIDATION_ERROR`, `QUOTA_EXCEEDED`, `PAYMENT_REQUIRED`, `CONFLICT`, `RATE_LIMITED`, `PIPELINE_ERROR`.

Rate limit: 120 req/menit per user; 10 generasi konkuren per workspace. Header `X-RateLimit-*` disertakan. Endpoint mutasi menerima `Idempotency-Key`.

## Auth & Workspace

| Method | Endpoint | Deskripsi |
|---|---|---|
| POST | `/auth/register` · `/auth/login` · `/auth/logout` | Email+password / OAuth Google |
| GET | `/me` | Profil + daftar workspace |
| POST | `/workspaces` | Buat workspace |
| GET/PATCH | `/workspaces/:id` | Detail / update (logo, brand, currency, margin) |
| GET/POST | `/workspaces/:id/members` | Daftar / invite anggota |
| PATCH/DELETE | `/workspaces/:id/members/:userId` | Ubah role / hapus |
| GET/POST/PATCH | `/workspaces/:id/rate-cards[/:rcId]` | Kelola rate card |
| GET/POST/PATCH | `/workspaces/:id/stack-presets[/:pId]` | Kelola preset stack |
| GET | `/workspaces/:id/audit-logs?actor=&from=&to=` | Audit log (Team plan) |

## Projects & Input

| Method | Endpoint | Deskripsi |
|---|---|---|
| GET/POST | `/projects` | List (filter status/klien) / buat proyek |
| GET/PATCH/DELETE | `/projects/:id` | Detail / update / arsip |
| POST | `/projects/:id/inputs` | Multipart upload (txt/docx/pdf/audio) atau `{kind:"idea", text}` |
| POST | `/projects/:id/inputs/fireflies` | `{meeting_id}` — tarik transkrip |
| GET | `/projects/:id/inputs/:inputId` | Status ekstraksi + hasil |
| DELETE | `/projects/:id/inputs/:inputId?purge=true` | Hapus permanen file sumber (BR-42) |
| POST | `/projects/:id/analyze` | Jalankan AI Understanding |
| GET/PATCH | `/projects/:id/understanding` | Ambil / koreksi hasil analisa |

## Interview & Structure

| Method | Endpoint | Deskripsi |
|---|---|---|
| GET | `/projects/:id/interview` | Daftar pertanyaan + progres |
| POST | `/projects/:id/interview/:seq/answer` | `{answer}` atau `{skip:true}` |
| POST | `/projects/:id/interview/skip-all` | Lewati semua → asumsi |
| GET | `/projects/:id/structure` | Seluruh node canvas |
| POST/PATCH/DELETE | `/projects/:id/structure/nodes[/:nodeId]` | CRUD node (batch PATCH untuk drag) |
| POST | `/projects/:id/structure/scope` | `{mode:"mvp"\|"full"}` + simulasi total |
| POST | `/projects/:id/stack/recommend` | Minta rekomendasi AI |
| PATCH | `/projects/:id/stack/:layer` | Override pilihan layer |

## Generation & Documents

| Method | Endpoint | Deskripsi |
|---|---|---|
| POST | `/projects/:id/generate` | Mulai pipeline `{scope, depth:"auto"\|"minimal"\|"full"}` → `{run_id}` |
| GET | `/projects/:id/runs/:runId` | Status run + node |
| GET | `/projects/:id/runs/:runId/stream` | **SSE**: progres node + konten streaming |
| POST | `/projects/:id/runs/:runId/resume` | Lanjutkan paused/error |
| GET | `/projects/:id/documents` | Daftar dokumen + versi aktif + status |
| GET | `/documents/:docId/versions[/:vNo]` | Riwayat / konten versi |
| POST | `/documents/:docId/versions` | Simpan edit manual (versi baru) |
| GET | `/documents/:docId/diff?from=2&to=3` | Diff terstruktur |
| POST | `/documents/:docId/translate` | `{lang:"en"\|"id"}` varian bilingual |
| POST | `/projects/:id/chat` | AI assistant `{message}` → jawaban / draft impact |
| POST | `/projects/:id/impact` | `{change_description}` → `{affected_docs, delta_md, delta_cost}` |
| POST | `/projects/:id/impact/:impactId/apply` | Eksekusi selective regeneration |
| GET | `/projects/:id/health` | Skor + temuan |
| POST | `/projects/:id/health/:findingId/fix` | Perbaiki via AI |

## Estimasi & Proposal

| Method | Endpoint | Deskripsi |
|---|---|---|
| POST | `/projects/:id/estimates` | Hitung `{scope, rate_card_id}` |
| GET | `/projects/:id/estimates?scope=` | Hasil (MD, range, RAB, tim, durasi) |
| PATCH | `/estimates/:estId/lines/:lineId` | Override MD `{md, reason}` |
| GET | `/projects/:id/timeline` | Data gantt |
| POST | `/projects/:id/proposals` | Generate DOCX/PDF + RAB xlsx → URL |

## Portal Klien (public, token-scoped)

| Method | Endpoint | Deskripsi |
|---|---|---|
| POST | `/projects/:id/shares` | Buat share `{doc_keys, contacts, hide_prices, expires_days}` |
| PATCH | `/shares/:shareId` | Revoke / perpanjang / ubah dokumen |
| POST | `/portal/:token/otp` → `/portal/:token/verify` | Kirim & verifikasi OTP (sesi 24 jam) |
| GET | `/portal/:token/documents[/:docKey]` | Konten yang di-share (harga sesuai flag) |
| POST | `/portal/:token/comments` | Komentar klien per section |
| POST | `/portal/:token/approve` | Approve semua → buat baseline (approver utama, OTP ulang) |
| POST | `/portal/:token/change-request` | Ajukan CR `{description}` |

## Baseline, CR, Export & Integrasi

| Method | Endpoint | Deskripsi |
|---|---|---|
| GET | `/projects/:id/baselines[/:seq]` | Daftar / detail snapshot |
| GET/POST | `/projects/:id/change-requests[/:crId]` | List / buat CR internal |
| POST | `/change-requests/:crId/send` · `/approve` · `/reject` | Alur CR |
| POST | `/projects/:id/exports` | `{kind:"zip"\|"pdf"\|"docx"\|"agent_pack"}` → job |
| GET | `/exports/:jobId` | Status + URL unduhan (signed, 24 jam) |
| GET/POST/DELETE | `/workspaces/:id/integrations[/:provider]` | OAuth connect ClickUp/Jira/Fireflies |
| POST | `/projects/:id/push/:provider` | Push task `{target}` — idempotent |
| GET | `/projects/:id/push/:pushId` | Laporan per-item |

## Billing

| Method | Endpoint | Deskripsi |
|---|---|---|
| GET | `/workspaces/:id/billing` | Paket, kuota, saldo kredit |
| POST | `/workspaces/:id/billing/checkout` | `{plan\|topup, provider:"midtrans"\|"stripe"}` → URL pembayaran |
| POST | `/webhooks/midtrans` · `/webhooks/stripe` | Callback (signature-verified, idempotent) |
| GET | `/workspaces/:id/billing/invoices` | Riwayat tagihan |

## Webhook Keluar (opsional, Team plan)

`POST` ke URL workspace dengan HMAC signature untuk event: `generation.completed`, `client.commented`, `client.approved`, `change_request.created`. Retry eksponensial 5× dalam 24 jam.
