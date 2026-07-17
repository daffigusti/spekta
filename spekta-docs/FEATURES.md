# FEATURES.md: Spectra

User stories, acceptance criteria ringkas, dan edge cases per modul. Referensi FR dari `PRD.md`/`REQUIREMENTS.md`.

## 1. Input & Analisa (FR-01..FR-03)

### 1.1 Meeting-to-Spec
**User stories:**
- Sebagai Owner, saya ingin meng-upload rekaman/transkrip meeting klien agar requirement terekstrak otomatis tanpa mencatat ulang.
- Sebagai PM, saya ingin melihat kutipan kalimat sumber untuk tiap fitur terekstrak agar bisa memverifikasi interpretasi AI.

**Edge cases:**
- Audio dengan >2 pembicara dan bahasa campur ID/EN → diarization tetap memisahkan speaker; bahasa dominan menentukan bahasa analisa.
- Transkrip berisi >1 topik proyek → AI menawarkan pemecahan menjadi beberapa proyek.
- File terenkripsi/korup → error spesifik per file, file lain dalam batch tetap diproses.
- Transkrip sangat pendek (<200 kata) → sistem memperingatkan konteks minim dan menambah porsi interview.

### 1.2 Adaptive Interview
**User stories:**
- Sebagai PM, saya ingin pertanyaan hanya untuk informasi yang belum ada agar tidak membuang waktu.
- Sebagai Owner yang buru-buru, saya ingin melewati interview dan membiarkan AI berasumsi, asalkan asumsi tercatat.

**Edge cases:**
- Jawaban user bertentangan dengan isi transkrip → AI menampilkan konflik dan meminta konfirmasi mana yang benar.
- User keluar di tengah interview → progres tersimpan; dapat dilanjutkan dari pertanyaan terakhir.

## 2. Structure Canvas (FR-04, FR-05)

**User stories:**
- Sebagai PM, saya ingin mengatur fitur ke dalam fase secara visual agar mudah mendiskusikan scope dengan tim/klien.
- Sebagai Owner, saya ingin melihat perubahan total biaya saat memindah fitur antar scope MVP/Full secara langsung.

**Acceptance criteria kunci:** lihat FR-04/FR-05. Tambahan: node yang dihapus ditampung di "parkir ide" (tidak hilang permanen).

**Edge cases:**
- Node fitur tanpa sub-fitur → tetap valid; estimasi dihitung di level fitur.
- Fitur dipindah antar fase setelah dokumen digenerate → sistem menandai ROADMAP.md & estimasi "perlu sinkronisasi".
- Dua user mengedit canvas bersamaan → last-write-wins per node + indikator presence; konflik node yang sama menampilkan banner.

## 3. Generation & Workspace (FR-07..FR-12)

### 3.1 Pipeline Generasi
**User stories:**
- Sebagai PM, saya ingin melihat progres per dokumen dan bisa meninggalkan halaman tanpa membatalkan proses.
- Sebagai Engineer, saya ingin dokumen saling merujuk konsisten (FR codes, entity, endpoint) agar bisa dipakai AI coding agent tanpa halusinasi.

**Edge cases:**
- Provider AI timeout/limit → fallback ke model alternatif pada node yang sama; tercatat di metadata dokumen.
- Kredit habis di tengah pipeline → pipeline pause (bukan gagal), dokumen selesai tetap tersimpan; resume setelah top-up.
- Input mengandung data sensitif (PII) → masking otomatis pada log & telemetry.

### 3.2 Impact Analysis & Selective Regeneration
**User stories:**
- Sebagai PM, saya ingin tahu dokumen apa yang terdampak sebelum menyetujui perubahan, termasuk dampak biaya.
- Sebagai Engineer, saya ingin edit manual saya tidak ditimpa regenerasi.

**Edge cases:**
- Perubahan menyentuh dokumen yang diedit manual → tampilkan 3-way choice: pertahankan edit / timpa / gabung dengan AI.
- Rantai dampak melingkar (A→B→A) → dependency graph memutus siklus dan memproses sekali per dokumen.

### 3.3 Spec Health
**User stories:**
- Sebagai Owner, saya ingin skor kualitas objektif sebelum dokumen dikirim ke klien.

**Edge cases:**
- Proyek kecil (4 dokumen) → aturan lint yang tidak relevan otomatis nonaktif; skor tetap sebanding antar proyek.
- User mengabaikan temuan critical → dokumen tetap bisa di-share, namun banner peringatan tampil di halaman share internal (bukan di portal klien).

## 4. Estimasi & Proposal (FR-13..FR-16)

**User stories:**
- Sebagai Owner, saya ingin RAB dihitung dari breakdown fitur dan rate card baku agar harga konsisten antar proposal.
- Sebagai Owner, saya ingin dua skenario harga (MVP vs Full) untuk negosiasi.
- Sebagai PM, saya ingin meng-override estimasi AI pada fitur yang saya tahu lebih baik.

**Edge cases:**
- Rate card belum diisi → estimator berjalan dalam mode MD-only (tanpa rupiah) dan mengarahkan ke pengaturan rate card.
- Mata uang klien berbeda (USD) → konversi memakai kurs manual yang di-set workspace (bukan kurs live) demi konsistensi proposal.
- Estimasi override lebih kecil 50% dari estimasi AI → peringatan risiko underestimate (tidak memblokir).

## 5. Portal Klien & Approval (FR-17..FR-20)

**User stories:**
- Sebagai Klien non-teknis, saya ingin membaca scope dalam bahasa saya dan menyetujuinya tanpa membuat akun.
- Sebagai Owner, saya ingin approval klien tercatat permanen agar menjadi dasar kontrak.
- Sebagai Owner, saya ingin permintaan perubahan setelah approval otomatis dihitung biayanya.

**Edge cases:**
- Link diteruskan ke orang lain → OTP terikat email yang diundang; email lain ditolak.
- Klien approve lalu meminta perubahan verbal (di luar sistem) → tim dapat membuat CR manual atas nama klien; CR tetap butuh konfirmasi klien via portal.
- Link kadaluarsa saat klien sedang review → halaman menawarkan "minta akses baru"; tim mendapat notifikasi.
- Dua kontak klien dengan pendapat berbeda → approval memakai satu approver utama yang ditunjuk; komentar tetap terbuka untuk semua.

## 6. Export & Integrasi (FR-21, FR-22)

**User stories:**
- Sebagai Engineer, saya ingin export `CLAUDE.md`/`.cursorrules` agar AI coding agent langsung paham konteks proyek.
- Sebagai PM, saya ingin push task ke ClickUp dengan estimasi terisi agar sprint planning cepat.

**Edge cases:**
- Push ulang setelah spec berubah → task existing di-update (bukan duplikat) berdasarkan external_id; task yang dihapus di spec ditandai label "removed-from-spec" (tidak dihapus otomatis).
- Token integrasi kadaluarsa → antrean push tertahan dengan instruksi re-auth, tidak gagal senyap.

## 7. Billing & Workspace (FR-23, FR-24)

**User stories:**
- Sebagai Owner, saya ingin membayar via QRIS/transfer (IDR) tanpa kartu kredit.
- Sebagai Owner, saya ingin melihat siapa mengubah rate card dan kapan.

**Edge cases:**
- Downgrade saat kredit tersisa → kredit dibawa (carry-over) hingga habis, kuota bulanan mengikuti paket baru.
- Webhook pembayaran dobel → idempotency key mencegah kredit ganda.
- Anggota di-remove saat sedang mengedit → sesi ditutup paksa ≤ 60 detik; draft edit tersimpan sebagai versi.
