# BUSINESS_RULES.md: Spectra

## Paket, Kredit & Billing

**BR-01:** Paket tersedia: **Free** (2 blueprint/bln, 10 AI chat, 1 anggota, tanpa portal klien), **Starter** (Rp 149rb/bln — 8 blueprint, 100 chat, 3 anggota), **Pro** (Rp 399rb/bln — 25 blueprint, 400 chat, 10 anggota, portal klien + estimator), **Team** (Rp 249rb/seat/bln, min 3 seat — unlimited anggota sesuai seat, template & rate card multi, white-label penuh, integrasi PM tools, audit log). Harga USD mengikuti tabel terpisah untuk pasar global.

**BR-02:** 1 kredit blueprint = 1 kali pipeline generasi penuh. Regenerasi selektif ≤ 3 dokumen tidak memakai kredit (fair-use: maks 30 regenerasi/blueprint/bulan); regenerasi > 3 dokumen memakai 0,5 kredit.

**BR-03:** Kredit paket berlaku 1 bulan (tidak rollover). Kredit top-up berlaku 12 bulan dan dipakai setelah kredit paket habis.

**BR-04:** Downgrade berlaku pada siklus billing berikutnya; upgrade berlaku seketika dengan penyesuaian prorata.

**BR-05:** Gagal bayar → grace period 7 hari (fitur penuh) → mode read-only (data tidak dihapus, export tetap bisa) → data dihapus permanen hanya atas permintaan user atau 12 bulan tidak aktif (dengan 3 email peringatan).

## Proyek & Generasi

**BR-10:** Satu proyek memiliki tepat satu structure canvas dan satu set dokumen aktif; dokumen berversi (v1, v2, …) dan versi lama immutable.

**BR-11:** Pipeline generasi mengikuti dependency graph dokumen; sebuah node hanya berjalan setelah semua upstream-nya selesai. Kegagalan node di-retry maks 2×; setelahnya pipeline berstatus paused-error tanpa membatalkan hasil node lain.

**BR-12:** Setiap dokumen hasil generate menyimpan metadata: model AI, versi prompt, token in/out, durasi, node pipeline — untuk audit kualitas dan biaya.

**BR-13:** Asumsi (dari interview yang dilewati atau gap data) wajib tercetak di section "Assumptions" pada PRD dan ikut ter-share ke klien. Asumsi adalah bagian dari scope kontrak.

**BR-14:** Edit manual user tidak boleh ditimpa regenerasi tanpa konfirmasi eksplisit (pilihan: pertahankan/timpa/gabung).

**BR-15:** Spec Health dihitung ulang setiap ada perubahan dokumen. Temuan critical tidak memblokir share, namun wajib ditampilkan ke tim internal sebelum pengiriman portal.

**BR-16 (Complexity governor):** Kelas arsitektur yang direkomendasikan dibatasi oleh skor kompleksitas proyek (1–5): skor ≤ 2 → monolith; 3 → monolith modular; ≥ 4 → boleh services terpisah dengan justifikasi tertulis. AI tidak boleh merekomendasikan di atas batas kelasnya.

## Estimasi & Rate Card

**BR-20:** Estimasi MD dihitung bottom-up dari sub-fitur; total fitur = Σ sub-fitur + overhead integrasi (default 10%). Proyek selalu menyertakan baris "setup, deploy, UAT & buffer" (default 15% dari total, dapat diubah 5–30%).

**BR-21:** RAB = Σ (MD per peran × tarif rate card) × (1 + margin). Margin default per workspace; angka margin tidak pernah tampil di dokumen klien.

**BR-22:** Estimasi yang tampil ke klien selalu menyertakan confidence range; angka tunggal hanya untuk internal.

**BR-23:** Snapshot estimasi dibuat saat proposal digenerate dan saat baseline approval; perubahan rate card setelahnya tidak mengubah snapshot.

## Approval, Baseline & Change Request

**BR-24:** Baseline terbentuk hanya melalui approval klien via portal (OTP terverifikasi). Baseline berisi: daftar versi dokumen, total RAB, timeline, daftar asumsi — disimpan immutable dengan hash.

**BR-25:** Setelah baseline, setiap perubahan scope (dari klien maupun internal) wajib melalui CR bernomor dengan impact analysis. Tidak ada regenerasi dokumen ter-baseline tanpa CR.

**BR-26:** CR yang di-approve membentuk baseline baru; baseline lama tetap tersimpan. Selisih RAB antar baseline adalah dasar penagihan tambahan.

**BR-27:** Approval memerlukan satu approver utama dari pihak klien (ditunjuk saat share). Komentar terbuka untuk kontak klien lain, namun tombol approve hanya untuk approver utama.

**BR-28:** Pencabutan share-link tidak menghapus jejak komentar/approval yang sudah terjadi.

**BR-29:** Dokumen berstatus Shared/Approved tidak dapat dihapus; proyek Approved hanya bisa diarsipkan.

**BR-30:** Sebelum share ke klien, minimal satu Owner/Admin harus menandai "Internal Review selesai" (empat mata).

## Portal Klien & Data

**BR-40:** Akses portal = token link + OTP email; sesi portal 24 jam. Maksimal 5 kontak klien per proyek.

**BR-41:** Data harga dapat disembunyikan per dokumen per share. Field margin, biaya token, dan telemetry internal tidak pernah terekspos ke portal.

**BR-42:** Transkrip meeting dan file klien terenkripsi at-rest; hanya anggota workspace proyek terkait yang dapat mengaksesnya. File sumber dapat dihapus permanen oleh Owner tanpa menghapus dokumen turunannya.

**BR-43:** Data pelanggan tidak dipakai melatih model AI. Prompt ke provider AI memakai mode zero/limited retention bila tersedia.

## AI & Biaya

**BR-50:** Model routing per node pipeline ditentukan konfigurasi server (bukan hard-code): kelas "reasoning" (PRD, ARCHITECTURE, impact analysis), kelas "standard" (dokumen turunan), kelas "economy" (validator, ekstraksi, chat ringan). Failover antar provider otomatis.

**BR-51:** Budget guard: satu pipeline dihentikan-jeda bila biaya token melebihi 3× median historis; internal alert terkirim. Per workspace ada soft-cap biaya harian.

**BR-52:** Prompt caching wajib untuk konteks dokumen upstream yang dipakai berulang antar node.

**BR-53:** Setiap output AI yang tampil ke user diberi penanda visual (amber) dan metadata "generated by AI"; user-generated edit dibedakan di version history.
