# REQUIREMENTS.md: Spectra

Acceptance criteria memakai kata kunci RFC 2119 (MUST/SHALL/SHOULD). Referensi FR mengikuti `PRD.md`.

## Modul A — Input & Analisa

### FR-01: Multi-Source Input
**Deskripsi:** Sistem menerima empat jalur input untuk memulai proyek: teks ide, file transkrip/notulen, dokumen RFP, dan import Fireflies.

**Acceptance Criteria:**
- Sistem MUST menerima teks ide 50–10.000 karakter dengan deteksi bahasa otomatis (ID/EN).
- Sistem MUST menerima upload .txt, .docx, .pdf hingga 20 MB dan audio (.mp3/.m4a/.wav) hingga 120 menit; audio ditranskripsi otomatis dengan speaker diarization.
- Sistem SHALL menerima multi-file dalam satu proyek (mis. transkrip + RFP) dan menggabungkan konteksnya.
- Import Fireflies MUST menampilkan daftar meeting 30 hari terakhir setelah koneksi OAuth dan menarik transkrip terpilih ≤ 30 detik.
- Ekstraksi teks dari file MUST selesai ≤ 60 detik untuk dokumen 100 halaman; kegagalan parsing menampilkan error spesifik per file.

### FR-02: AI Understanding
**Deskripsi:** Sebelum interview, AI menampilkan hasil pemahamannya untuk dikonfirmasi/dikoreksi user.

**Acceptance Criteria:**
- Panel MUST menampilkan: user roles terdeteksi, daftar fitur terekstrak (dengan kutipan sumber dari transkrip), domain bisnis, skala kompleksitas (1–5), dan asumsi awal.
- Setiap item MUST dapat diedit/dihapus/ditambah sebelum lanjut.
- Untuk input transkrip, setiap fitur terekstrak SHALL menautkan cuplikan kalimat sumber (traceability).
- Analisa MUST selesai ≤ 45 detik untuk transkrip 60 menit (p90).

### FR-03: Adaptive Interview
**Deskripsi:** Pertanyaan klarifikasi dinamis hanya untuk gap informasi.

**Acceptance Criteria:**
- Sistem SHALL mengajukan maksimal 10 pertanyaan; tiap pertanyaan menampilkan alasan ("ditanya karena…") dan dampaknya (dokumen/RAB).
- Format MUST mendukung multiple-choice dengan opsi "jawaban lain" free-text.
- Tombol "lewati semua" MUST tersedia; tiap pertanyaan yang dilewati menghasilkan asumsi eksplisit yang tercetak di bagian Assumptions pada PRD hasil generate.
- Jawaban MUST tersimpan; user dapat kembali dan mengubah jawaban sebelum generate.

## Modul B — Structure & Stack

### FR-04: Structure Canvas
**Acceptance Criteria:**
- Canvas MUST merender node: root proyek → fase → fitur → sub-fitur dengan drag-reposition dan auto-layout.
- CRUD node MUST tersedia (tambah/ubah/hapus fase, fitur, sub-fitur) dengan undo ≥ 20 langkah.
- Tiap node fitur SHALL menampilkan estimasi MD kasar (dari model estimasi awal) dan totalnya teragregasi ke fase dan root.
- Perubahan canvas MUST tersimpan otomatis (autosave ≤ 2 detik setelah idle).
- Canvas MUST tetap responsif (≥ 30 fps interaksi) hingga 150 node.

### FR-05: Scope Toggle (MVP/Full)
**Acceptance Criteria:**
- Tiap fitur/sub-fitur MUST dapat ditandai MVP atau Full; toggle global menampilkan simulasi total (MD, biaya, durasi) untuk kedua skenario secara real-time.
- Status scope MUST terbawa ke ROADMAP (P0/P1) dan Estimator.

### FR-06: Tech Stack Recommendation
**Acceptance Criteria:**
- Rekomendasi MUST mencakup minimal: frontend, backend, database, auth, payment (jika relevan), deploy — masing-masing dengan justifikasi dan ≥ 1 alternatif beserta alasan tidak dipilih.
- Complexity governor MUST menyesuaikan kelas arsitektur dengan skala proyek (lihat BR-16); rekomendasi microservices untuk proyek kompleksitas ≤ 2 MUST tidak terjadi.
- Preset "stack standar perusahaan" MUST dapat disimpan di level workspace dan diterapkan sekali klik.
- Setiap layer MUST dapat di-override manual; override tercatat sebagai keputusan user (bukan AI) di ARCHITECTURE.md.

## Modul C — Generation & Dokumen

### FR-07: Pipeline Generasi DAG
**Acceptance Criteria:**
- Pipeline MUST menghasilkan set dokumen scale-adaptive: kompleksitas 1–2 → 4–6 dokumen; 3 → 8–11; 4–5 → 12–15 (tambahan SECURITY.md, COMPLIANCE.md, INTEGRATION.md sesuai domain).
- Urutan MUST mengikuti dependensi: PROJECT_BRIEF → PRD → (REQUIREMENTS, USER_FLOWS, BUSINESS_RULES) → (DATABASE, API, ARCHITECTURE) → (FEATURES, TESTING, DESIGN, ROADMAP) → validator → estimator. PROJECT_BRIEF = ringkasan presales non-teknis (akar pipeline, masuk semua doc set).
- Progres MUST streaming per dokumen (antre/berjalan/selesai/gagal) dan tetap berjalan bila tab ditutup; notifikasi email/in-app saat selesai.
- Kegagalan satu node MUST di-retry otomatis (maks 2×) tanpa mengulang node yang sudah selesai.
- PRD pertama MUST tampil ≤ 90 detik; seluruh pipeline ≤ 8 menit p90 (NFR-01).

### FR-08: Workspace Dokumen
**Acceptance Criteria:**
- Tampilan MUST menyediakan 3 panel: file tree, editor/preview, panel AI — dengan mode Preview / Raw Markdown / Diff.
- Diff MUST membandingkan dua versi manapun dari dokumen yang sama dengan highlight tambah/hapus.
- Edit manual MUST membuat versi baru (vN+1) dengan atribusi editor dan timestamp; mermaid dirender di preview.
- Versi MUST punya label semantik: otomatis ("Draf awal AI", "Regenerate AI", "Perbaikan spec health", "Restore dari vN") atau opsional saat edit manual (≤ 60 karakter).
- Workspace MUST menampilkan dependency dokumen (diturunkan dari / mempengaruhi, dari doc_pipeline) dan penanda STALE bila upstream punya versi lebih baru dari versi dokumen; STALE hanya penanda, tidak memblokir.

### FR-09: AI Assistant + Impact Analysis
**Acceptance Criteria:**
- Chat MUST menyediakan pilihan scope konteks: dokumen aktif (default, hemat token — dokumen aktif + yang disebut, cap 5) atau seluruh proyek (semua dokumen, pilihan sadar user).
- Permintaan perubahan MUST menghasilkan impact analysis SEBELUM eksekusi: daftar dokumen/section terdampak, delta man-days, delta biaya — user mengkonfirmasi atau membatalkan.
- Impact analysis MUST selesai ≤ 20 detik (p90).

### FR-10: Selective Regeneration
**Acceptance Criteria:**
- Regenerasi MUST hanya menjalankan node pipeline yang terdampak (berdasarkan graph dependensi dokumen).
- Dokumen hasil regenerasi MUST menjadi versi baru; versi lama tetap dapat diakses dan di-diff.
- Bagian yang diedit manual oleh user MUST tidak ditimpa tanpa konfirmasi eksplisit (konflik ditampilkan sebagai pilihan).

### FR-11: Consistency Validator (Spec Health)
**Acceptance Criteria:**
- Validator MUST memeriksa minimal: (a) setiap FR punya acceptance criteria; (b) setiap FR muncul di ROADMAP; (c) setiap FR P0/P1 punya skenario di TESTING; (d) setiap entity ERD dirujuk di API; (e) konsistensi penomoran/istilah; (f) kontradiksi antar-requirement.
- Skor MUST 0–100 dengan formula terdokumentasi; tiap temuan memiliki severity (info/warning/critical), lokasi, dan saran perbaikan satu-klik ("fix with AI").
- Skor MUST dipecah per dimensi bernama (Kelengkapan, Konsistensi, Keterlacakan, Cakupan) via mapping rule → dimensi di konfigurasi; temuan tampil tergroup per dimensi.
- Sistem MUST menyediakan Requirement Traceability Matrix: baris per FR dari PRD × kolom dokumen turunan (REQUIREMENTS/USER_FLOWS/API/TESTING/ROADMAP); sel = disebut/tidak/dokumen belum ada.
- Rule heuristik (mis. fact drift berbasis pairing keyword) MUST berbobot info, bukan warning/critical — hanya rule deterministik-terverifikasi yang boleh menghukum skor berat (BR-54).
- Validator MUST otomatis berjalan setelah generate/regenerate dan dapat dipicu manual.

### FR-12: Bilingual (ID ↔ EN)
> **Status: dicabut dari implementasi.** Fitur translation dibangun lalu dihapus (keputusan produk); kolom `language` tersisa sebagai bahasa primer proyek (id/en/bilingual saat generate).

**Acceptance Criteria:**
- Konversi seluruh set dokumen MUST mempertahankan struktur, penomoran FR/BR, tabel, dan diagram; istilah teknis tidak diterjemahkan.
- Kedua bahasa MUST tersimpan sebagai varian dari versi yang sama (bukan versi baru).

## Modul D — Estimasi & Proposal

### FR-13: Rate Card
**Acceptance Criteria:**
- Rate card MUST berisi peran (mis. FE, BE, QA, PM, DevOps) dengan tarif per man-day dan persentase margin; multiple rate card per workspace dengan satu default.
- Perubahan rate card MUST tidak mengubah estimasi proyek yang sudah di-approve (snapshot).

### FR-14: Estimator
**Acceptance Criteria:**
- Estimasi MUST dihitung per fitur dari task breakdown (bukan tebakan agregat) dan menampilkan confidence range ±X% yang ikut tercetak di proposal.
- Output MUST mencakup: total MD per peran, komposisi tim yang disarankan, durasi kalender, dan RAB (tarif × MD × margin) dalam IDR/USD.
- Owner/Admin MUST dapat meng-override MD per fitur; override mengubah RAB secara real-time dan tercatat.

### FR-15: Timeline Otomatis
**Acceptance Criteria:**
- Gantt MUST tergenerate dari fase + estimasi dengan dependensi antar fase, termasuk slot UAT & buffer (default 15%, dapat diubah).
- Perubahan scope/estimasi MUST memperbarui timeline otomatis.

### FR-16: Proposal Generator
**Acceptance Criteria:**
- Proposal DOCX/PDF MUST mengikuti template workspace (logo, warna, format halaman) dan memuat: ringkasan eksekutif, scope, deliverables, timeline, RAB, skema pembayaran, asumsi & eksklusi, syarat garansi.
- RAB MUST dapat diekspor sebagai Excel dengan formula hidup (bukan nilai statis).
- Generate proposal MUST selesai ≤ 60 detik.

## Modul E — Kolaborasi & Portal Klien

### FR-17: Portal Klien
**Acceptance Criteria:**
- Share-link MUST memakai token unik + verifikasi email OTP; masa berlaku default 30 hari (dapat diubah/dicabut).
- Portal MUST menampilkan branding perusahaan (white-label) dan HANYA dokumen yang dipilih untuk dibagikan.
- Klien MUST dapat melihat versi terbaru yang di-share; angka harga dapat disembunyikan per dokumen.
- Portal MUST menampilkan open questions proyek (pertanyaan interview yang dilewati, asumsi, kontradiksi input); kontak terverifikasi dapat menjawab langsung — jawaban tercatat (siapa, kapan) dan tampil di panel internal sebagai bahan update dokumen (BR-44).

### FR-18: Komentar per Section
**Acceptance Criteria:**
- Komentar MUST menempel pada section (anchor heading), mendukung thread balasan, mention (@nama), dan status open/resolved.
- Tim MUST menerima notifikasi (in-app + email) untuk komentar klien baru ≤ 1 menit.

### FR-19: Approval Workflow
**Acceptance Criteria:**
- Status dokumen MUST mengikuti alur: Draft → Internal Review → Shared → Client Approved / Change Requested.
- Approve keseluruhan MUST mengunci baseline: versi tiap dokumen + total RAB + timeline tersimpan sebagai snapshot immutable dengan timestamp dan identitas approver (BR-24).

### FR-20: Change Request
**Acceptance Criteria:**
- Setiap permintaan perubahan pasca-approval MUST melalui impact analysis dan tercatat sebagai CR bernomor (CR-001, …) berisi: deskripsi, dokumen terdampak, delta MD, delta biaya, status (proposed/approved/rejected).
- CR yang di-approve klien MUST menghasilkan baseline baru (v-baseline+1) tanpa menghapus baseline lama.

## Modul F — Export & Integrasi

### FR-21: Export
**Acceptance Criteria:**
- Export ZIP MUST berisi seluruh dokumen markdown + gambar/diagram; PDF/DOCX per dokumen atau gabungan.
- ZIP MUST menyertakan `README.md` branding ("Spekta Blueprint"): tabel urutan baca, grup dokumen, dependency antar dokumen, versi, dan Spec Health — nama file dokumen tetap vocabulary standar industri.
- Export agent-ready MUST menghasilkan `CLAUDE.md`, `.cursorrules`, `AGENTS.md` berisi ringkasan arsitektur + konvensi + rujukan dokumen, dan `tasks.md` breakdown siap eksekusi.

### FR-22: Integrasi PM Tools
**Acceptance Criteria:**
- Push MUST memetakan fase → epic, fitur → story/task, sub-fitur → subtask, dengan acceptance criteria di deskripsi dan estimasi di field effort.
- Integrasi minimal v1: ClickUp dan Jira (OAuth); hasil push menampilkan link balik dan menghindari duplikasi saat push ulang (idempotent berdasarkan external_id).

## Modul G — Platform

### FR-23: Billing
**Acceptance Criteria:**
- Paket MUST sesuai `BUSINESS_RULES.md` BR-01..BR-05 (Free/Starter/Pro/Team + top-up kredit).
- Pembayaran MUST mendukung Midtrans (QRIS, VA, e-wallet, kartu) untuk IDR dan Stripe untuk USD; kegagalan pembayaran tidak menghapus data, hanya menurunkan paket sesuai grace period 7 hari.

### FR-24: Administrasi Workspace
**Acceptance Criteria:**
- Owner/Admin MUST dapat invite via email dengan role; audit log mencatat aksi sensitif (perubahan role, rate card, approval, export) dan dapat difilter per aktor/tanggal.

## Non-Functional Requirements

Lihat `PRD.md` bagian 6 (NFR-01 s.d. NFR-08). Tambahan teknis:

- **NFR-09 Observability:** setiap panggilan LLM tercatat (model, token, biaya, latensi, node pipeline) di Langfuse; dashboard biaya per workspace tersedia untuk internal.
- **NFR-10 Backup:** database di-backup harian dengan retensi 30 hari; object storage versioned.
- **NFR-11 Kompatibilitas:** Chrome/Edge/Safari/Firefox 2 versi terakhir; portal klien berjalan baik di mobile browser.
