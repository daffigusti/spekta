# DESIGN.md: Spectra

Mengacu pada brand system & hi-fi mockup (`hifi-design-spectra.html`).

## 1. Identitas Brand

- **Nama:** Spectra ("spec" + "spectrum" — satu ide menjadi spektrum dokumen lengkap).
- **Tagline:** *From Meeting to Blueprint.*
- **Kepribadian:** presisi teknis, tenang, profesional; AI terasa sebagai asisten yang transparan, bukan sulap.
- **Logo:** mark garis-garis dokumen dengan titik amber (node AI) di kanan bawah; wordmark `spectra` dengan suku "tra" berwarna brand-300.

## 2. Prinsip Desain

1. **Dokumen adalah bintangnya** — chrome UI netral dan minim; konten dokumen mendapat kontras tertinggi.
2. **AI selalu ber-badge amber** — semua aksi/output AI (generate, saran, insight, impact) memakai amber; user selalu tahu mana buatan mesin (selaras BR-53).
3. **Angka bisnis selalu terlihat** — man-days & rupiah tampil di tiap level (node canvas, dokumen, chat impact), menyatukan dunia teknis dan komersial.
4. **Dark untuk berpikir, light untuk membaca** — canvas/struktur bermode gelap; dokumen/portal bermode terang.

## 3. Design Tokens

```css
:root {
  /* Brand */
  --brand-900:#191667; --brand-700:#3730C4; --brand-600:#4F46E5; /* primer */
  --brand-500:#5D55F1; --brand-300:#A5A0F8; --brand-100:#E4E2FD; --brand-50:#F2F1FE;
  /* AI accent — HANYA untuk momen AI */
  --amber-500:#F5A623; --amber-100:#FDEED2; --amber-ink:#3D2A00;
  /* Ink (dark surfaces & teks) */
  --ink-900:#101019; --ink-800:#191926; --ink-700:#232334;
  --ink-500:#5C5C70; --ink-400:#8B8B9E; --ink-300:#C5C5D2;
  /* Surface & semantic */
  --paper:#FBFBFD; --card:#FFFFFF; --border:#E7E7EF;
  --ok:#1F9D63; --ok-bg:#E3F6ED; --warn:#D9820B; --warn-bg:#FCF0DB;
  --err:#DC4444; --err-bg:#FCE8E8;
  /* Radius & shadow */
  --r-lg:16px; --r-md:12px; --r-sm:8px;
  --shadow-sm:0 1px 2px rgba(16,16,25,.05), 0 1px 6px rgba(16,16,25,.04);
  --shadow-md:0 4px 14px rgba(16,16,25,.07), 0 1px 3px rgba(16,16,25,.05);
}
```

## 4. Tipografi

| Peran | Font | Pemakaian |
|---|---|---|
| Display | **Space Grotesk** 500–700 | Judul halaman, angka besar (RAB, MD, skor), wordmark |
| UI/Body | **Inter** 400–800 | Seluruh antarmuka & isi dokumen |
| Mono | **JetBrains Mono** 400–600 | ID teknis (FR-02, BR-14), endpoint, kode, URL |

Skala: 11 / 12 / 13.5 (body) / 15 / 17 / 20 / 24 / 34 px. Line-height body 1.6–1.75. Letter-spacing display -0.02em.

## 5. Aturan Komponen Kunci

- **Tombol primer** = brand-600; **tombol AI** = amber dengan ikon ✦ (satu-satunya pemakaian amber pada tombol); **ghost** = putih + border.
- **Badge status dokumen:** DRAFT (abu), INTERNAL REVIEW (biru brand-100), SHARED/IN REVIEW (amber-bg), APPROVED (hijau), CHANGE REQUEST (merah muda).
- **Spec Health ring:** conic-gradient; ≥85 hijau, 70–84 amber, <70 merah. Skor selalu disertai jumlah temuan.
- **FR card:** border kiri 4px brand; yang baru diedit → border amber + latar hangat + label "belum sinkron" bila dokumen turunan tertinggal.
- **Node canvas:** kartu gelap ink-800, badge fase amber, estimasi MD dalam mono-amber; node terpilih ber-ring amber; root ber-ring brand.
- **Komentar klien vs tim:** avatar klien ber-outline amber, tim ber-outline brand — pembeda cepat di thread.

## 6. Layout Utama

| Layar | Pola |
|---|---|
| Dashboard | Sidebar gelap 224px + konten; KPI row 4 kartu; grid proyek 3 kolom |
| Wizard (input→generate) | Stepper 6 langkah di atas; konten 2 kolom (input kiri, pengaturan kanan) |
| Structure Canvas | Full-bleed gelap; toolbar kiri; inspector kanan 268px; scope bar bawah tengah |
| Workspace | 3 panel: file tree 212px · dokumen fleksibel · panel AI 288px |
| Portal klien | Single column max 760px, header brand perusahaan, sticky approve bar |

## 7. Portal Klien (white-label)

- Logo, warna primer, dan nama perusahaan pengguna menggantikan brand Spectra ("powered by Spectra" kecil di footer, dapat dihilangkan di Team plan).
- Bahasa portal mengikuti bahasa dokumen; istilah teknis diberi tooltip penjelasan awam.
- Mobile-first: dokumen dibaca nyaman di ponsel; tombol approve sticky bottom.
- Aksesibilitas WCAG 2.1 AA: kontras ≥ 4.5:1, fokus ring jelas, semua aksi dapat via keyboard (NFR-08).

## 8. Konten & Suara (UX writing)

- Bahasa Indonesia baku-santai ("Anda"), istilah teknis tetap Inggris (man-days, blueprint, approve).
- AI tidak pernah menyamar: saran ditulis "AI menyarankan…", bukan kalimat imperatif anonim.
- Setiap angka estimasi ke klien selalu didampingi range dan asumsi — jangan pernah menampilkan kepastian palsu (selaras BR-22).
- Empty state selalu menawarkan aksi berikutnya + contoh (mis. proyek sample di onboarding).
