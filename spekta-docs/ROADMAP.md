# ROADMAP.md: Spectra

Asumsi tim: 2 FE, 2 BE, 1 fullstack/AI engineer, 0.5 designer, 0.5 PM (dapat dirangkap). Total ~34 minggu hingga GA, dengan produk sudah dipakai internal sejak minggu ke-10.

## Fase & Timeline

| Fase | Durasi | Tujuan | Exit criteria |
|---|---|---|---|
| **Fase 0 — Fondasi** | 3 minggu | Infrastruktur & kerangka | Auth + workspace + billing skeleton jalan di staging; CI/CD + observability aktif |
| **Fase 1 — Core Blueprint** | 8 minggu | Paritas SpecKit + canvas | Input ide → interview → canvas → generate 6–11 dokumen → workspace + export ZIP/agent-pack. Dogfooding internal dimulai |
| **Fase 2 — Presales Engine** | 6 minggu | Diferensiasi utama | Meeting-to-Spec (audio/transkrip/Fireflies) + estimator + rate card + proposal DOCX/RAB Excel. **Beta tertutup** |
| **Fase 3 — Kolaborasi Klien** | 7 minggu | Siklus approval penuh | Portal klien + komentar + approval/baseline + CR + Spec Health penuh + push ClickUp/Jira. **Public launch** |
| **Fase 4 — Living Spec** | 6 minggu | Retensi & moat | Impact analysis penuh + selective regeneration + bilingual + wireframe generator. **GA** |
| **Fase 5 — Ekspansi** | berkelanjutan | Pasar & kedalaman | Brownfield mode, Linear/Trello/GitHub, API publik, EN market, template marketplace |

## Prioritas Fitur

### P0 — Wajib untuk Public Launch (Fase 1–3)

- FR-01 Multi-source input (teks + file; audio menyusul akhir Fase 2)
- FR-02 AI Understanding, FR-03 Adaptive Interview
- FR-04 Structure Canvas, FR-05 Scope toggle
- FR-06 Tech stack recommendation + preset + complexity governor
- FR-07 Pipeline DAG + streaming, FR-08 Workspace dokumen (preview/raw/diff)
- FR-11 Spec Health (aturan inti a–d)
- FR-13 Rate card, FR-14 Estimator, FR-15 Timeline, FR-16 Proposal generator
- FR-17 Portal klien, FR-18 Komentar, FR-19 Approval/baseline
- FR-21 Export (ZIP, agent-pack, PDF)
- FR-23 Billing (Free/Starter/Pro + Midtrans), FR-24 Administrasi dasar

### P1 — ≤ 2 bulan pasca-launch (Fase 3 akhir–4)

- FR-09 AI assistant + impact analysis penuh, FR-10 selective regeneration
- FR-20 Change request end-to-end
- FR-22 Integrasi ClickUp & Jira
- FR-12 Bilingual ID↔EN
- FR-11 Spec Health aturan lanjutan (kontradiksi antar-requirement)
- Team plan (seat, white-label penuh, audit log, webhook keluar)
- Wireframe low-fi generator per user flow

### P2 — Backlog strategis (Fase 5)

- Brownfield mode (connect repo → spec dalam konteks existing)
- Stripe/USD + versi EN penuh (pasar global)
- Integrasi Linear, Trello, GitHub Issues; API publik + webhook lengkap
- Template marketplace antar workspace
- Analitik presales (win-rate per jenis proyek, akurasi estimasi vs aktual)
- E-signature proposal (integrasi Privy/DocuSign)

## Milestone Bisnis

| Kapan | Milestone |
|---|---|
| Minggu 10 | Dogfooding: semua proposal AmanahCorp dibuat via Spectra |
| Minggu 17 | Beta tertutup 5–8 software house; testimonial & studi kasus pertama |
| Minggu 24 | Public launch (komunitas dev Indonesia); target 300 workspace terdaftar bulan pertama |
| Minggu 30 | GA + Team plan; target 30 workspace berbayar |
| Bulan 12 | 150 workspace berbayar · MRR ≥ Rp 60 jt · time-to-proposal median ≤ 4 jam |

## Risiko Utama & Mitigasi

| Risiko | Dampak | Mitigasi |
|---|---|---|
| Biaya token menggerus margin | Tinggi | Routing economy-class, prompt caching, budget guard (BR-50–52); pantau per blueprint sejak Fase 1 |
| Kualitas output generik | Tinggi | Golden set eval di CI (TESTING.md §2); complexity governor; dogfooding di proyek nyata |
| Kompetitor gratis (GitHub Spec Kit, BMAD) | Sedang | Moat di workflow B2B (portal, RAB, baseline/CR) — bukan di generate markdown |
| Adopsi klien terhadap portal | Sedang | Portal tanpa akun (OTP), mobile-first, bahasa awam; fallback PDF tetap ada |
| Ketergantungan provider AI | Sedang | Abstraction layer + failover multi-provider (ADR-3) |
| Scope creep produk sendiri | Sedang | Roadmap ini dibaseline-kan; perubahan lewat proses CR internal — dogfooding prinsip produk |
