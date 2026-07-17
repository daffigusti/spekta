# Fase 4 — Living Spec: Design

**Tanggal:** 2026-07-17
**Scope:** FR-09 (AI Impact Analysis), FR-10 (Selective Regeneration), FR-12 (Bilingual ID↔EN), FR-11 aturan lanjutan (d/e/f).
**Di luar scope:** webhook keluar (Team plan), wireframe generator (sudah ada), audio/Fireflies (Fase 2, ditunda).

## Keputusan produk

| Keputusan | Pilihan |
|---|---|
| Urutan build | FR-09+10 → FR-12 → FR-11 lanjutan |
| Titik picu impact analysis | Assistant chat + Change Request (bukan canvas) |
| Konflik edit manual saat regen | 2 pilihan upfront di preview: lewati / regen (non-destruktif, versi lama tetap ada) |
| Webhook keluar | Skip |

## Fondasi existing yang dipakai ulang

- `document_versions.source` (`ai|user`) — deteksi edit manual.
- Versioning + diff + restore di workspace (`DocumentController`, `project.tsx`).
- Dependency graph dokumen + `topoSort` di `GenerationPipeline`.
- `startMissing(only)` sebagai pola run subset; streaming + `runStatus` polling.
- `ChangeRequestService::setImpact()` untuk menyimpan delta MD + affected docs pada CR.
- Stub driver `SpecEngine` untuk test tanpa API key.

## 1. FR-09 — Impact Analysis

### Engine

`SpecEngine::impact(Project $project, string $changeText): array`

- Konteks LLM: heading + ringkasan tiap dokumen (bukan isi penuh; target p90 ≤ 20 detik), structure nodes, estimate aktif.
- Output JSON:

```json
{
  "summary": "Ringkasan dampak perubahan",
  "delta_md": 3.5,
  "affected": [
    { "doc_key": "REQUIREMENTS", "reason": "FR baru untuk ...", "manual_edit": true }
  ]
}
```

- `manual_edit` diisi dari kode (`currentVersion.source === 'user'`), bukan dari LLM.
- Stub driver mengembalikan impact deterministik untuk test.

### Titik pakai

1. **Assistant chat** — mode "usulkan perubahan" di panel asisten:
   `POST projects/{project}/impact` (body: `change_text`) → preview kartu impact (dokumen terdampak, alasan, delta MD, badge "ada edit manual") → user centang/lepas dokumen → konfirmasi → regen.
2. **Change Request** — tombol "hitung impact AI" di panel CR internal:
   engine sama, hasil mengisi `setImpact()` existing; tim tetap bisa koreksi manual sesudahnya.

### Penyimpanan

Stateless. Hasil impact tidak disimpan; frontend mengirim balik `doc_keys` terpilih + `change_text` saat konfirmasi. Jalur CR menyimpan lewat `change_requests` existing.

## 2. FR-10 — Selective Regeneration

### Pipeline

`GenerationPipeline::startRegeneration(Project $project, array $docKeys, string $instruction): GenerationRun`

- Run berisi node hanya untuk `docKeys`, diurutkan `topoSort` existing.
- Migration: `generation_runs` tambah kolom `kind` (`full|missing|regen`, default `full`) dan `meta` JSON (menyimpan `instruction`).

### Job

`GenerateDocumentJob` saat `kind = regen`:

- Prompt menyertakan konten versi sekarang dokumen tersebut + blok "Instruksi perubahan: {instruction}" — revisi terarah, bukan tulis ulang buta.
- Hasil menjadi versi baru `source=ai`; versi lama tetap dapat di-diff/restore.

### Konflik edit manual

Dokumen dengan `currentVersion.source === 'user'` ditandai di preview impact. User memilih per dokumen: lewati (pertahankan edit) atau regen (timpa — aman karena versi lama tersimpan). Tidak ada mode gabung AI.

### Progress

Pakai `runStatus` polling + streaming existing. Tidak ada UI progres baru.

## 3. FR-12 — Bilingual ID↔EN

### Skema

- `document_versions` tambah kolom `language` (string 5, default `'id'`).
- Unique berubah: `(document_id, version_no)` → `(document_id, version_no, language)`.
- Varian EN = baris baru dengan `version_no` sama — sesuai AC "kedua bahasa tersimpan sebagai varian dari versi yang sama, bukan versi baru".

### Engine & Job

- `SpecEngine::translate(Project $project, DocumentVersion $version, string $target): string` — aturan keras di prompt: struktur, penomoran FR/BR, tabel, blok mermaid dipertahankan; istilah teknis tidak diterjemahkan.
- `TranslateDocumentJob` per dokumen; tombol per dokumen + "terjemahkan semua" (loop dispatch per dokumen).

### UI

- Toggle ID/EN di viewer dokumen bila varian tersedia.
- Regen dokumen tidak ikut menerjemahkan; varian yang `version_no`-nya tertinggal dari current ditandai "terjemahan usang".

## 4. FR-11 — Aturan lanjutan

| Aturan | Implementasi | Jalur |
|---|---|---|
| (d) entity ERD dirujuk di API | Parse nama entity dari blok mermaid `erDiagram` di DATABASE, cek kemunculan di API.md | Regex murni, sync di `SpecHealthValidator` |
| (e) konsistensi penomoran | FR/BR dirujuk tapi tidak terdefinisi di REQUIREMENTS (dangling ref) + nomor duplikat | Regex murni, sync |
| (f) kontradiksi antar-requirement | `SpecEngine::findContradictions(Project): array` — LLM JSON | Job async setelah run generate selesai + tombol manual "cek kontradiksi"; temuan masuk `health_findings` dengan `rule_key: 'contradiction'`, severity warning |

Aturan (f) tidak masuk jalur sync `SpecHealthValidator` karena terlalu lambat untuk jalur `storeVersion`.

## 5. Error handling

- Impact/translate gagal → toast error, tanpa perubahan state.
- Node regen gagal → mekanisme retry/resume existing (`RepairRunJob`/`resume`).
- Terjemahan gagal per dokumen → dokumen tetap satu bahasa, error ditampilkan.

## 6. Testing

Feature test dengan stub driver:

- `impact()` mengembalikan daftar affected + `manual_edit` flag benar.
- `startRegeneration` membuat run berisi hanya node terpilih, hasil jadi versi baru.
- Jalur CR: "hitung impact AI" mengisi `delta_md` + `affected_doc_keys`.
- `translate` menyimpan varian dengan `version_no` sama, `language='en'`; deteksi "terjemahan usang".
- Rule (d)/(e): unit test fungsi parse murni.
- Rule (f): stub contradiction → temuan masuk `health_findings`.
