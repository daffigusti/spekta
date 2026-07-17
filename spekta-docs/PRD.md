# PRD: Spectra — AI Spec & Presales Engine

> **Status:** Draft v1 · **Author:** Muammar · **Tanggal:** 16 Juli 2026
> **Dokumen terkait:** `REQUIREMENTS.md` · `FEATURES.md` · `USER_FLOWS.md` · `BUSINESS_RULES.md` · `DATABASE.md` · `API.md` · `ARCHITECTURE.md` · `DESIGN.md` · `TESTING.md` · `ROADMAP.md`

## 1. Executive Summary & Product Vision

Spectra adalah platform AI yang mengubah percakapan klien (transkrip meeting, notulen, RFP, atau ide yang diketik) menjadi paket spesifikasi teknis lengkap, estimasi biaya (RAB), dan proposal siap kirim — dalam hitungan jam, bukan hari.

Berbeda dengan PRD generator yang ada di pasar (SpecKit, NgodingPakeAI, ChatPRD, Keeborg), Spectra dirancang untuk **alur kerja software house dan agency**: sumber requirement adalah meeting klien, output akhirnya adalah proposal yang di-approve klien, dan spesifikasi yang di-approve menjadi baseline scope kontrak. Tagline: **"From Meeting to Blueprint."**

**Visi produk:** setiap software house di Indonesia dapat mengubah satu jam meeting klien menjadi paket presales profesional (spec + wireframe + RAB + proposal) di hari yang sama, dengan kualitas konsisten standar perusahaan.

## 2. Problem Statement

Proses presales dan requirement engineering di software house saat ini:

1. **Lambat dan mahal.** Dari meeting klien ke proposal memakan 3–7 hari kerja engineer/analis senior — waktu yang tidak ditagihkan (unbillable).
2. **Kualitas tidak konsisten.** Kedalaman spec bergantung siapa yang menulis; acceptance criteria sering absen; asumsi tidak terdokumentasi → dispute scope di tengah proyek.
3. **Requirement hilang di perjalanan.** Detail yang disebut klien di meeting tidak semuanya tercatat; yang tercatat tidak semuanya masuk spec.
4. **Estimasi berbasis feeling.** Man-days dan harga dihitung kasar per proyek, tidak dari breakdown fitur yang terstruktur dan rate card baku.
5. **Tidak ada jejak approval.** Klien menyetujui scope via chat/email yang tercecer; perubahan scope tidak tercatat sebagai change request yang bisa ditagih.

Tools eksisting menyelesaikan sebagian masalah #2 (generate dokumen) tapi tidak menyentuh #1, #4, #5 — di situlah Spectra berbeda.

## 3. Target Users & Personas

| Persona | Deskripsi | Kebutuhan utama |
|---|---|---|
| **Owner / BD software house** | Pengambil keputusan komersial, memimpin meeting klien | Proposal cepat & profesional, kontrol scope, margin terjaga |
| **PM / Analis** | Menerjemahkan kebutuhan klien menjadi spec | Dokumen lengkap & konsisten, revisi mudah, jejak asumsi |
| **Engineer / Tech Lead** | Mengeksekusi (sering dengan AI coding agent) | Spec presisi, acceptance criteria terukur, export agent-ready |
| **Klien** (guest) | Pemilik bisnis yang memesan software | Memahami & menyetujui scope tanpa harus paham teknis |

## 4. System Scope & User Roles

Spectra adalah aplikasi web multi-tenant (workspace per perusahaan) dengan portal klien terpisah berbasis share-link.

**Permission Matrix:**

| Kemampuan | Owner | Admin | Member | Client (guest) |
|---|:---:|:---:|:---:|:---:|
| Kelola billing & langganan | ✓ | ✕ | ✕ | ✕ |
| Kelola anggota & role | ✓ | ✓ | ✕ | ✕ |
| Kelola rate card & template perusahaan | ✓ | ✓ | ✕ | ✕ |
| Buat/edit proyek & generate blueprint | ✓ | ✓ | ✓ | ✕ |
| Edit dokumen & regenerate | ✓ | ✓ | ✓ | ✕ |
| Lihat & konfigurasi estimasi/RAB | ✓ | ✓ | dibatasi* | ✕ |
| Kirim portal link ke klien | ✓ | ✓ | ✕ | ✕ |
| Baca dokumen yang di-share | ✓ | ✓ | ✓ | ✓ |
| Komentar per section | ✓ | ✓ | ✓ | ✓ |
| Approve / request change | ✕** | ✕** | ✕ | ✓ |
| Kelola integrasi (ClickUp/Jira) | ✓ | ✓ | ✕ | ✕ |

\* Member melihat man-days namun harga (rupiah) dapat disembunyikan per pengaturan workspace.
\** Approval internal ada sebagai langkah "siap dikirim ke klien" (lihat `BUSINESS_RULES.md` BR-30).

## 5. Functional Requirements (ringkasan)

Detail acceptance criteria per FR ada di `REQUIREMENTS.md`.

**Modul A — Input & Analisa**
- **FR-01 Multi-source input:** ide teks, transkrip meeting (txt/docx/pdf/audio), dokumen RFP, dan import dari Fireflies.
- **FR-02 AI Understanding:** ekstraksi roles, fitur, domain, kompleksitas + panel konfirmasi sebelum lanjut.
- **FR-03 Adaptive Interview:** maksimal 10 pertanyaan dinamis (multiple choice + free text), hanya untuk gap; skip menghasilkan asumsi tercatat.

**Modul B — Structure & Stack**
- **FR-04 Structure Canvas:** mind-map fase → fitur → sub-fitur, drag & edit, estimasi MD kasar per node.
- **FR-05 Scope toggle:** penandaan MVP vs Full per node dengan simulasi total biaya real-time.
- **FR-06 Tech stack recommendation:** rekomendasi + justifikasi + alternatif tertolak (ADR-style), preset stack perusahaan, complexity governor.

**Modul C — Generation & Dokumen**
- **FR-07 Pipeline generasi DAG:** 4–15 dokumen scale-adaptive, streaming progres per dokumen, berjalan di background.
- **FR-08 Workspace dokumen:** file tree, rich preview / raw markdown / diff antar versi, edit langsung.
- **FR-09 AI assistant + impact analysis:** perubahan pada satu bagian menampilkan dokumen terdampak + delta MD/biaya sebelum regenerate.
- **FR-10 Selective regeneration:** hanya dokumen/section terdampak yang digenerate ulang; versi baru per dokumen.
- **FR-11 Consistency validator (Spec Health):** linter lintas-dokumen dengan skor 0–100 dan daftar temuan.
- **FR-12 Bilingual:** seluruh set dokumen dapat dialihkan ID ↔ EN.

**Modul D — Estimasi & Proposal**
- **FR-13 Rate card per workspace:** peran, tarif harian, margin, dapat lebih dari satu (mis. enterprise vs UMKM).
- **FR-14 Estimator:** man-days per fitur dengan confidence range, agregasi RAB, komposisi tim, durasi.
- **FR-15 Timeline otomatis:** gantt per fase dari struktur + estimasi.
- **FR-16 Proposal generator:** DOCX/PDF white-label mengikuti template & logo perusahaan; RAB export Excel.

**Modul E — Kolaborasi & Portal Klien**
- **FR-17 Portal klien via share-link:** akses baca tanpa akun penuh (email OTP), branding perusahaan.
- **FR-18 Komentar per section** dengan thread, mention, dan status resolve.
- **FR-19 Approval workflow:** approve per dokumen → approve keseluruhan → scope lock (baseline).
- **FR-20 Change request:** perubahan pasca-approval otomatis dihitung dampak MD/biaya dan tercatat sebagai CR.

**Modul F — Export & Integrasi**
- **FR-21 Export:** ZIP markdown, PDF, DOCX, dan agent-ready (`CLAUDE.md`, `.cursorrules`, `AGENTS.md`, task list).
- **FR-22 Integrasi PM tools:** push epic/story/task + acceptance criteria + estimasi ke ClickUp, Jira, Linear, Trello, GitHub Issues.

**Modul G — Platform**
- **FR-23 Billing:** langganan (Free/Starter/Pro/Team) + kredit blueprint, pembayaran Midtrans (IDR) dan Stripe (USD).
- **FR-24 Administrasi workspace:** invite anggota, role, template dokumen, audit log.

## 6. Non-Functional Requirements

- **NFR-01 Performa generasi:** blueprint 11 dokumen selesai ≤ 8 menit (p90); dokumen pertama (PRD) tampil ≤ 90 detik.
- **NFR-02 Ketersediaan:** uptime 99,5%/bulan; generasi berjalan di background dan resume otomatis jika worker restart.
- **NFR-03 Keamanan data:** isolasi data per workspace (row-level); transkrip klien terenkripsi at-rest (AES-256) dan in-transit (TLS 1.2+); share-link portal memakai token + email OTP dengan masa berlaku.
- **NFR-04 Privasi AI:** data pelanggan tidak dipakai untuk training model; opsi zero-retention provider diaktifkan bila tersedia.
- **NFR-05 Biaya AI terkendali:** biaya token per blueprint ≤ USD 2 (p90) melalui model routing + prompt caching; ada circuit breaker per workspace.
- **NFR-06 Skalabilitas:** 100 generasi paralel tanpa degradasi; antrean adil antar workspace.
- **NFR-07 Auditabilitas:** semua approval, perubahan versi, dan CR memiliki jejak waktu + aktor yang immutable.
- **NFR-08 Aksesibilitas & responsif:** WCAG 2.1 AA untuk portal klien; workspace dioptimalkan desktop, portal klien mobile-friendly.

## 7. Success Metrics

| Metrik | Target 6 bulan pasca-launch |
|---|---|
| North Star: waktu meeting → proposal terkirim | ≤ 4 jam (median) |
| Blueprint digenerate / workspace aktif / bulan | ≥ 6 |
| % blueprint yang berlanjut ke approval klien | ≥ 40% |
| Spec Health rata-rata | ≥ 85 |
| Biaya token / blueprint (p90) | ≤ USD 2 |
| Konversi Free → berbayar | ≥ 8% |

## 8. Out of Scope (v1)

- AI coding / implementasi otomatis (Spectra berhenti di spec + task; eksekusi diserahkan ke Cursor/Claude Code).
- Real-time collaborative editing ala Google Docs.
- Aplikasi mobile native.
- Brownfield mode (reverse-engineering repo existing) — direncanakan Fase 4, lihat `ROADMAP.md`.
- E-signature legal untuk proposal (integrasi pihak ketiga menyusul).
