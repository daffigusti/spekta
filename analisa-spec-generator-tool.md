# Analisa & Blueprint: Membangun AI Spec Generator yang Lebih Baik dari SpecKit & NgodingPakeAI

**Disusun untuk:** Muammar (Owner Software House)
**Tanggal:** 16 Juli 2026
**Tujuan:** Analisa mendalam dua tools referensi, landscape kompetitor, rekomendasi fitur, tech stack, pilihan AI, dan strategi produk.

---

## 1. Analisa Referensi

### 1.1 SpecKit (speckit.tech)

**Konsep:** "Build software before writing code" — mengubah ide kasar menjadi spesifikasi teknis production-ready.

**Flow produk:**

```
Describe Idea → AI Interview (adaptive multiple-choice) → Tech Stack
Recommendation → Generation (real-time, progress tracking) → Workspace
3-panel (dokumen, preview, AI assistant) → Export ZIP markdown
```

**Output:** 4–15+ dokumen markdown tergantung kompleksitas proyek. Dari sampel ZIP yang Anda kirim (Kasir Pintar UMKM), 11 dokumen: PRD, REQUIREMENTS, FEATURES, USER_FLOWS, BUSINESS_RULES, DATABASE, API, ARCHITECTURE, DESIGN, TESTING, ROADMAP.

**Kekuatan (dari analisa sampel):**
- Kualitas output tinggi dan saling terhubung antar dokumen (FR-XX codes dirujuk konsisten dari PRD → REQUIREMENTS → FEATURES → ROADMAP).
- Acceptance criteria terukur (contoh: "QRIS code SHALL be generated within 1 second"), memakai bahasa RFC-style (SHALL/MUST).
- Ada permission matrix per role, business rules bernomor (BR-01 dst), edge cases per fitur, ERD mermaid lengkap, API spec dengan format response standar, testing strategy dengan coverage target, roadmap berfase dengan asumsi ukuran tim.
- Adaptive interview — pertanyaan klarifikasi menyesuaikan proyek, bukan kuesioner statis.
- AI assistant di workspace dengan impact analysis & version history.
- Pricing sangat murah: Free (1 blueprint/bln), $0.99/blueprint, Starter $3.99/bln, Pro $9.99/bln.

**Kelemahan yang saya temukan:**
- Output berbahasa Inggris walau target user bisa jadi Indonesia — tidak ada opsi bilingual.
- Dokumen bersifat **statis** — sekali digenerate, tidak sinkron dengan perubahan (masalah "spec drift" klasik).
- Tidak ada output visual: tidak ada wireframe/mockup, DESIGN.md hanya color palette & typography.
- Tidak ada estimasi biaya/man-days — padahal ini kebutuhan utama software house.
- Tidak ada kolaborasi tim, komentar, approval workflow, atau integrasi ke project management tools.
- Generic assumption: arsitektur microservices + AWS untuk aplikasi POS UMKM sebenarnya over-engineered — AI-nya tidak menyesuaikan kompleksitas arsitektur dengan skala klien.

### 1.2 NgodingPakeAI (ngodingpakeai.com/plan)

**Konsep:** Platform Indonesia — "Siapapun bisa ngoding pakai AI." Fokusnya menghasilkan PRD + task breakdown yang siap dieksekusi AI coding agent (Cursor, Claude Code, dsb).

**Flow produk (dari screenshot Anda):**

```
Struktur (mind-map visual: fitur per fase + sub-fitur) → PRD → Task
```

**Output:** Satu file PRD sederhana (contoh_prd.md yang Anda kirim: Overview, Requirements, Core Features, User Flow, Architecture sequence diagram, Database schema ERD, Design & Technical Constraints) + task breakdown.

**Kekuatan:**
- Visual mind-map/canvas untuk struktur fitur per fase — sangat intuitif untuk non-teknis, ini fitur yang TIDAK dimiliki SpecKit.
- Bahasa Indonesia — cocok pasar lokal.
- Multi-model AI (mengiklankan GPT-5.5, Opus 4.7, DeepSeek V4).
- Ada komunitas (terbesar di Indonesia untuk AI coding) + coaching — distribusi & retensi kuat.
- Output diarahkan agar langsung konsumsi AI coding agent.

**Kelemahan:**
- Kedalaman dokumen jauh di bawah SpecKit — hanya 1 PRD ringkas, tidak ada business rules, API spec, testing, roadmap terpisah.
- Acceptance criteria tidak terukur; tidak ada permission matrix, edge cases, atau NFR.
- Tidak ada adaptive interview yang dalam.
- Positioning-nya ke individual "vibe coder", bukan tim software house profesional.

### 1.3 Kesimpulan perbandingan

| Dimensi | SpecKit | NgodingPakeAI | Peluang Anda |
|---|---|---|---|
| Kedalaman dokumen | ★★★★★ (11 docs) | ★★☆☆☆ (1–2 docs) | Samai SpecKit + tambah dokumen bisnis |
| Visual planning | ✕ | ★★★★☆ (mind-map) | Gabungkan keduanya |
| Bahasa lokal | ✕ | ✓ | Bilingual ID/EN |
| Estimasi biaya/timeline | ✕ | ✕ | **Diferensiasi utama** |
| Kolaborasi tim/klien | ✕ | ✕ | **Diferensiasi utama** |
| Sinkronisasi spec (living spec) | ✕ | ✕ | Diferensiasi jangka panjang |
| Input dari meeting/transkrip | ✕ | ✕ | **Diferensiasi utama utk software house** |
| Wireframe/mockup | ✕ | ✕ | Diferensiasi |

---

## 2. Landscape Kompetitor Global (supaya tidak reinvent the wheel)

- **Keeborg** — 8 dokumen saling terhubung + export `CLAUDE.md` dan `.cursorrules`, generate <90 detik, $49 one-time. Kekuatannya: konsistensi antar-dokumen + format siap konsumsi AI agent.
- **ChatPRD** — PRD conversational, kuat untuk dokumen stakeholder-facing (PM), bukan spec teknis dalam.
- **Amazon Kiro** — spec 3-dokumen dengan notasi EARS ("THE SYSTEM SHALL...") dan formal analysis untuk deteksi kontradiksi requirement.
- **GitHub Spec Kit** (open source, MIT) — CLI spec-driven development: `/speckit.constitution` → `/specify` → `/plan` → `/tasks` → `/implement`. Agent-agnostic. Kelemahan: overhead 1–3 jam per fitur, spec statis.
- **BMAD-METHOD** (open source) — 21+ agent persona (analyst, PM, architect, dev, QA) dengan 34+ workflow; "scale-adaptive" menyesuaikan rigor dokumentasi dengan kompleksitas.
- **Augment Cosmos** — konsep "living specs" yang auto-update mengikuti implementasi (mencegah spec drift).
- Kompetitor lokal Indonesia selain NgodingPakeAI: PRDify (useprdify.com), Ngevibe (ngevibe.id), dibuatin-ai.com — semuanya masih level PRD generator sederhana.

**Insight penting:** tren 2026 bergeser dari "PRD generator" → "spec-driven development platform" yang outputnya dikonsumsi AI coding agent DAN manusia. Dua kualitas pembeda menurut review industri: (1) konsistensi lintas-dokumen, (2) acceptance criteria eksplisit + edge cases yang bisa jadi test cases.

---

## 3. Rekomendasi Fitur Produk Anda

### 3.1 Fitur inti (paritas dengan referensi — wajib ada)

1. **Input ide natural language** (ID/EN) dengan deteksi otomatis: user roles, kompleksitas, model bisnis, domain.
2. **Adaptive AI Interview** — pertanyaan klarifikasi dinamis multiple-choice + free text, dengan opsi "skip, pakai asumsi AI" (asumsi dicatat eksplisit di dokumen).
3. **Visual Structure Canvas** (ambil dari NgodingPakeAI) — mind-map fitur per fase yang bisa di-drag, edit, tambah/hapus node SEBELUM generate dokumen. Perubahan di canvas = regenerate bagian terdampak saja.
4. **Tech stack recommendation** yang bisa di-override, dengan justifikasi + alternatif (dan preset "stack standar perusahaan" — lihat 3.2).
5. **Generasi dokumen berskala** (scale-adaptive): proyek kecil = 4–5 dokumen, kompleks = 12–15. Set dokumen minimal: PRD, Requirements (dengan acceptance criteria terukur), Features + edge cases, User Flows, Business Rules, Database (ERD), API spec, Architecture, Design, Testing, Roadmap.
6. **Workspace 3-panel**: file tree, rich preview/raw markdown/diff compare, AI assistant chat dengan impact analysis ("kalau saya ubah X, dokumen apa saja yang terdampak?") + version history.
7. **Export**: ZIP markdown, PDF, DOCX; plus export agent-ready (`CLAUDE.md`, `.cursorrules`, `AGENTS.md`, task list format untuk Claude Code/Cursor).

### 3.2 Fitur diferensiasi (yang membuat Anda LEBIH BAIK — diurutkan berdasarkan nilai untuk software house)

1. **Meeting-to-Spec** ⭐ killer feature untuk software house: upload transkrip meeting klien (atau tarik dari Fireflies/rekaman), notulen, chat WhatsApp, atau dokumen RFP → AI ekstrak requirement → jadi draft struktur + interview hanya untuk gap yang belum terjawab. Kompetitor semua mulai dari "ketik ide" — padahal di software house, sumber requirement adalah MEETING KLIEN.
2. **Estimasi otomatis: man-days, biaya (RAB), timeline** — dari task breakdown, hitung estimasi effort per fitur (dengan rate card yang bisa dikonfigurasi per perusahaan), hasilkan draft proposal/quotation. Tidak ada satupun kompetitor yang punya ini. Ini mengubah tool dari "spec generator" jadi "presales weapon".
3. **Client Portal & Approval Workflow** — share link read-only ke klien, klien bisa komentar per section, approve/request change per dokumen. Status: Draft → In Review → Approved. Spec yang di-approve jadi baseline kontrak (scope lock, perubahan setelahnya tercatat sebagai change request — bahan tagihan tambahan!).
4. **Wireframe/Mockup low-fidelity otomatis** — generate wireframe HTML/excalidraw-style per user flow utama. Klien non-teknis jauh lebih mudah memvalidasi gambar daripada teks.
5. **Task breakdown → integrasi PM tools** — push epic/story/task langsung ke ClickUp, Jira, Linear, Trello, GitHub Issues, lengkap dengan acceptance criteria dan estimasi. (NgodingPakeAI berhenti di "Task" sebagai teks.)
6. **Multi-workspace & template per perusahaan** — software house punya stack standar, format dokumen standar, bahasa standar, rate card. Sekali set, semua blueprint konsisten dengan standar perusahaan. Ini membuka pricing B2B per-seat.
7. **Konsistensi & validasi lintas-dokumen** — linter otomatis: setiap FR harus punya acceptance criteria, muncul di roadmap, punya test scenario; setiap entity di ERD dirujuk API; deteksi kontradiksi requirement (terinspirasi Kiro). Tampilkan "Spec Health Score".
8. **Living Spec / Change Request mode** — edit satu requirement → AI kasih impact analysis + regenerate hanya section terdampak di dokumen lain, dengan diff view. Versi v1, v2 per dokumen (SpecKit sudah punya versioning dasar; Anda buat propagasinya lintas dokumen).
9. **Bilingual output** — satu klik switch dokumen ID ↔ EN (dokumen internal tim bahasa Indonesia, dokumen klien enterprise/asing bahasa Inggris).
10. **Brownfield mode** (fase lanjut) — connect repo GitHub existing → AI reverse-engineer arsitektur & fitur existing → spec untuk fitur BARU ditulis dalam konteks sistem lama (terinspirasi OpenSpec: penanda ADDED/MODIFIED).

### 3.3 Fitur yang sebaiknya TIDAK dikejar dulu

- AI coding/implementasi langsung (bersaing dengan Cursor/Claude Code — biarkan jadi komplemen, bukan kompetitor).
- Mobile app — desktop web dulu.
- Real-time collaborative editing ala Google Docs (mahal secara engineering; komentar + approval sudah cukup untuk v1).

---

## 4. Rekomendasi Flow Produk

```
1. INPUT     : Ketik ide  |  Upload transkrip/notulen/RFP  |  Voice note
2. ANALYZE   : AI ekstrak roles, fitur, domain, kompleksitas → tampilkan
               "AI Understanding" untuk dikonfirmasi
3. INTERVIEW : Adaptive Q&A hanya untuk gap (max 5-10 pertanyaan,
               bisa skip dengan asumsi tercatat)
4. STRUCTURE : Visual canvas fitur per fase (editable mind-map)
               + pilih scope MVP vs full
5. STACK     : Rekomendasi tech stack + preset perusahaan (override-able)
6. GENERATE  : Dokumen dirakit real-time, scale-adaptive (4-15 docs)
7. VALIDATE  : Spec Health Score + linter konsistensi + deteksi kontradiksi
8. ESTIMATE  : Man-days, RAB, timeline, draft proposal
9. REVIEW    : Client portal → komentar → approve → scope baseline
10. DELIVER  : Export (ZIP/PDF/DOCX/agent-files) | Push ke PM tools
11. EVOLVE   : Change request → impact analysis → regenerate terdampak → diff
```

Bedanya dengan SpecKit: langkah 4 (canvas visual), 8 (estimasi), 9 (client portal), 11 (living spec). Bedanya dengan NgodingPakeAI: kedalaman langkah 6–7 dan seluruh layer B2B.

---

## 5. Rekomendasi Tech Stack

| Layer | Rekomendasi | Alasan |
|---|---|---|
| Frontend | **Next.js 15 (App Router) + TypeScript + Tailwind + shadcn/ui** | Standar industri, SSR untuk landing/SEO, ekosistem matang |
| Visual canvas | **React Flow (xyflow)** | Library terbaik untuk mind-map/node editor seperti NgodingPakeAI |
| Editor markdown | **TipTap / Milkdown** + `react-diff-viewer` | Rich preview + raw + diff compare seperti SpecKit |
| Diagram | **Mermaid.js** (render ERD, sequence, flowchart dari teks) | Sama seperti kedua referensi; mudah digenerate LLM |
| Backend | **NestJS (Node/TS)** atau **FastAPI (Python)** | NestJS jika tim Anda JS-heavy; FastAPI jika mau dekat dengan ekosistem AI |
| AI orchestration | **Vercel AI SDK** atau **LangGraph** + antarmuka provider-agnostic | Wajib multi-model & streaming; jangan hard-code satu vendor |
| Job queue | **BullMQ + Redis** (atau Trigger.dev/Inngest) | Generasi 11 dokumen = long-running job; wajib queue + progress streaming (SSE/WebSocket) |
| Database | **PostgreSQL** + **pgvector** | Relational untuk workspace/billing; pgvector untuk RAG (template, spec lama, knowledge domain) |
| Storage | S3-compatible (AWS S3 / Cloudflare R2) | Simpan ZIP export, upload transkrip |
| Auth | **Better Auth / Auth.js** (atau Clerk jika mau cepat) | Multi-tenant, invite team member |
| Billing | **Stripe** (global) + **Midtrans/Xendit** (pasar Indonesia) | Kombinasi credit-based + subscription |
| Deploy | **Vercel (FE) + Railway/Fly.io (BE)** dulu; VPS/AWS saat scale | Mulai sederhana — jangan ulangi kesalahan over-engineering |
| Observability AI | **Langfuse** (self-host bisa) | Tracking biaya token, kualitas output, eval per prompt — krusial untuk margin |

**Arsitektur kunci — pipeline generasi dokumen:**

Jangan generate 11 dokumen dalam satu prompt raksasa. Pakai pola DAG (mirip workflow):

```
Idea+Interview → PRD (master) → paralel: [Requirements, User Flows, Business Rules]
              → dari Requirements: [Database, API, Architecture]
              → dari semuanya: [Features detail, Testing, Design, Roadmap]
              → pass terakhir: Consistency Validator (cross-check FR codes,
                entity ERD vs API, kontradiksi) → Estimator (man-days/RAB)
```

Setiap node = 1 call LLM dengan konteks dokumen upstream + structured output (JSON schema → render ke markdown template). Ini yang membuat output konsisten antar dokumen — kelemahan utama kompetitor murah.

---

## 6. Rekomendasi AI Model

Prinsip: **multi-model routing berdasarkan tugas** (seperti yang diiklankan NgodingPakeAI), bukan satu model untuk semua. Per pertengahan 2026:

| Tugas | Model utama | Alternatif hemat |
|---|---|---|
| Analisa ide + adaptive interview | Claude Sonnet (4.5+) / GPT-5.x mini | Gemini Flash |
| Generasi dokumen inti (PRD, Requirements, Architecture) | **Claude Opus/Sonnet terbaru** — konsisten terbaik untuk long-form terstruktur, instruksi format ketat, dan reasoning arsitektur | GPT-5.x |
| Dokumen mekanis (API spec, DB schema, testing) | Claude Sonnet / GPT-5.x | **DeepSeek V3/V4** — kualitas kode bagus, biaya ~80% lebih murah; penting untuk jaga margin di harga pasar Indonesia |
| Ekstraksi transkrip meeting (konteks panjang) | **Gemini (2.5+/3) Pro** — context window terbesar & termurah untuk input panjang | Claude Sonnet |
| Consistency validator / linter | Model kecil (Haiku / GPT mini / DeepSeek) | rule-based + LLM hybrid |
| Chat assistant workspace | Claude Sonnet / GPT-5.x mini | DeepSeek |

Catatan penting:

- Semua akses via **OpenRouter** atau langsung ke provider dengan abstraction layer — supaya bisa ganti model tanpa refactor, dan bisa menawarkan "pilih model" sebagai fitur premium.
- **Prompt caching** (Anthropic/OpenAI/Gemini) memotong biaya besar karena konteks dokumen upstream dipakai berulang antar node pipeline.
- Bangun **eval set** sejak awal (10–20 proyek contoh + rubrik kualitas) supaya tiap ganti model/prompt bisa diukur, bukan feeling.
- Struktur biaya kasar: 1 blueprint lengkap (11 dokumen) ≈ 150–400 ribu token output. Dengan routing campuran, biaya per blueprint bisa ditekan ke kisaran $0.5–2 — masih sehat dijual Rp 30–100 ribu per blueprint atau via subscription.

---

## 7. Saran Strategi dari Saya

**Positioning.** Jangan bersaing head-to-head dengan NgodingPakeAI di segmen "siapapun bisa ngoding" (mereka menang komunitas) atau dengan SpecKit di harga global murah. Positioning terkuat Anda: **"Presales & Requirement Engine untuk Software House / Agency / Tim Produk"** — dari meeting klien jadi spec + proposal + estimasi dalam satu jam. Anda sendiri adalah ICP-nya; pakai internal dulu (dogfooding) di proyek nyata perusahaan Anda, itu QA terbaik sekaligus studi kasus marketing.

**Monetisasi.** Hybrid: subscription per-seat untuk tim (mis. Free 1 blueprint/bln → Starter Rp 99rb/bln → Pro Rp 299rb/bln → Team Rp 199rb/seat/bln dengan client portal + template perusahaan + integrasi PM) + top-up credit. Fitur B2B (portal klien, RAB, white-label export dengan logo perusahaan) di tier atas — itu yang willingness-to-pay-nya tinggi.

**Urutan build (roadmap MVP):**

- **Fase 1 (6–8 minggu):** input ide → interview → canvas struktur → generate 6 dokumen inti → workspace + export ZIP/agent-files. Paritas "SpecKit + canvas NgodingPakeAI".
- **Fase 2 (4–6 minggu):** Meeting-to-Spec (upload transkrip) + estimasi man-days/RAB + export proposal DOCX/PDF. Mulai jadi produk yang berbeda.
- **Fase 3 (6–8 minggu):** client portal + approval + integrasi ClickUp/Jira + spec health score.
- **Fase 4:** living spec/change request, wireframe generator, brownfield mode.

**Risiko yang perlu diantisipasi.** (1) Moat tipis — fitur generate dokumen mudah ditiru; moat sebenarnya ada di workflow B2B (portal, estimasi, template perusahaan, integrasi) dan data (template & rate card per perusahaan). (2) Biaya token — tanpa routing + caching + observability, margin habis. (3) Kualitas generik — seperti sampel SpecKit yang merekomendasikan microservices AWS untuk POS UMKM; buat "complexity governor" yang menyesuaikan arsitektur dengan skala nyata proyek. (4) GitHub Spec Kit & BMAD gratis — pastikan value Anda di UX, bahasa, estimasi, dan kolaborasi, bukan sekadar output markdown.

---

## 8. Sumber

- https://speckit.tech
- https://www.ngodingpakeai.com / https://www.ngodingpakeai.com/pricing
- https://www.augmentcode.com/tools/best-spec-driven-development-tools
- https://www.keeborg.com/blog/ai-prd-tools-compared-2026
- https://guptadeepak.com/tools/best-llm-for-each-use-case-2026/
- https://blog.buildbetter.ai/best-chatprd-alternatives-in-2026-ai-prd-generators-for-product-teams/
- https://www.marktechpost.com/2026/05/08/9-best-ai-tools-for-spec-driven-development-in-2026-kiro-bmad-gsd-and-more-compare/
- Sampel: contoh_prd.md (NgodingPakeAI) & prd-kasir-pintar-umkm.zip (SpecKit) yang Anda lampirkan
