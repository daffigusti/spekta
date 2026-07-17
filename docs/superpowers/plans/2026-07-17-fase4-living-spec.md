# Fase 4 — Living Spec Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** FR-09 AI impact analysis (chat + CR), FR-10 selective regeneration, FR-12 bilingual ID↔EN, FR-11 aturan lanjutan (d/e/f) di spekta-app.

**Architecture:** Satu engine impact di `SpecEngine` dipakai dua titik (panel impact + tombol CR). Regen selektif = `GenerationRun` dengan `trigger='regen'` + instruksi di kolom `meta`, node subset topoSort, job existing dengan branch revisi. Bilingual = baris varian di `document_versions` dengan `version_no` sama + kolom `language`. FR-11 d/e regex sync di validator; f = LLM async job.

**Tech Stack:** Laravel 12 + Inertia React (spekta-app), PHPUnit, stub LLM driver untuk test.

**Spec:** `docs/superpowers/specs/2026-07-17-fase4-living-spec-design.md`

## Global Constraints

- Working dir semua perintah: `/Users/macbook/Code/idea/spekta/spekta-app`.
- Deviasi dari spec (disetujui): pakai kolom `generation_runs.trigger` existing dengan nilai baru `regen` — TIDAK menambah kolom `kind`. Hanya tambah kolom `meta` JSON.
- WIREFRAMES dilewati saat terjemahan (kontennya JSON layout, bukan prosa).
- Test selalu jalan dengan driver stub (default testing env); tidak boleh butuh API key.
- Impact analysis stateless — tidak ada tabel baru; jalur CR menyimpan lewat `ChangeRequestService::setImpact()` existing.
- Bahasa komentar/istilah ikut kode existing (Indonesia, istilah teknis Inggris). Komentar simplifikasi sengaja pakai prefix `ponytail:` sesuai konvensi repo.
- Setiap task diakhiri `php artisan test` hijau + commit.

---

### Task 1: `SpecEngine::impact()` + stub

**Files:**
- Modify: `app/Services/SpecEngine.php` (sisipkan sebelum komentar `// ---------- FR-09 (subset): asisten chat spec ----------`, ±baris 282)
- Test: `tests/Feature/ImpactAnalysisTest.php` (create)

**Interfaces:**
- Consumes: `SpecEngine::json()`, `driver()`, `structureArray()` (existing).
- Produces: `SpecEngine::impact(Project $project, string $changeText): array` → `['summary' => string, 'delta_md' => float, 'affected' => [['doc_key','reason','manual_edit' => bool]]]`. Task 2, 3, 5 memakainya.

- [ ] **Step 1: Tulis failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\SpecEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImpactAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private function projectWithDocs(): Project
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['workspace_id' => $user->workspace_id]);
        foreach (['PRD' => 'ai', 'REQUIREMENTS' => 'user'] as $key => $source) {
            $doc = $project->documents()->create(['doc_key' => $key, 'title' => $key.'.md']);
            $v = $doc->versions()->create(['version_no' => 1, 'content_md' => "# $key\n## Isi", 'source' => $source]);
            $doc->update(['current_version_id' => $v->id]);
        }

        return $project;
    }

    public function test_impact_returns_affected_docs_with_manual_edit_flag(): void
    {
        $project = $this->projectWithDocs();

        $out = app(SpecEngine::class)->impact($project, 'Tambah fitur notifikasi email');

        $this->assertIsFloat($out['delta_md']);
        $this->assertNotEmpty($out['affected']);
        $byKey = collect($out['affected'])->keyBy('doc_key');
        $this->assertFalse($byKey['PRD']['manual_edit']);
        $this->assertTrue($byKey['REQUIREMENTS']['manual_edit']);
    }
}
```

Catatan: bila `Project::factory()` tidak ada, lihat pola pembuatan project di `tests/Feature/GenerateMissingTest.php` dan tiru persis (helper existing test lain adalah sumber kebenaran).

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=ImpactAnalysisTest`
Expected: FAIL — `Call to undefined method App\Services\SpecEngine::impact()`

- [ ] **Step 3: Implementasi `impact()`**

```php
    // ---------- FR-09: impact analysis ----------

    /** Dampak permintaan perubahan → dokumen terdampak + delta MD. Konteks outline (bukan isi penuh) — kejar p90 ≤ 20 dtk. */
    public function impact(Project $project, string $changeText): array
    {
        $docs = $project->documents()->with('currentVersion')->get();

        if ($this->driver() === 'stub') {
            $out = [
                'summary' => 'Dampak stub untuk: '.$changeText,
                'delta_md' => 1.5,
                'affected' => $docs->take(2)->map(fn ($d) => [
                    'doc_key' => $d->doc_key,
                    'reason' => 'Terdampak (stub)',
                ])->values()->all(),
            ];
        } else {
            $outline = $docs->map(function ($d) {
                preg_match_all('/^#{1,3}\s.*$/m', (string) $d->currentVersion?->content_md, $h);

                return "== {$d->doc_key} ==\n".implode("\n", array_slice($h[0], 0, 40));
            })->implode("\n\n");

            $out = $this->json('reasoning', <<<'SYS'
Kamu analis dampak perubahan spesifikasi software. Berdasar outline dokumen proyek dan permintaan perubahan,
tentukan dokumen mana saja yang harus direvisi. Balas JSON:
{"summary":"...","delta_md":<float perkiraan delta man-days, boleh negatif>,"affected":[{"doc_key":"...","reason":"..."}]}
doc_key HARUS dari daftar dokumen yang diberikan. Jangan sertakan dokumen yang tidak perlu berubah.
SYS, 'DAFTAR DOKUMEN: '.$docs->pluck('doc_key')->implode(', ')
                ."\n\nSTRUKTUR: ".json_encode($this->structureArray($project), JSON_UNESCAPED_UNICODE)
                ."\n\nOUTLINE DOKUMEN:\n".$outline
                ."\n\nPERMINTAAN PERUBAHAN:\n".$changeText);
        }

        // manual_edit dari kode, bukan LLM (design §1)
        $byKey = $docs->keyBy('doc_key');
        $out['affected'] = collect($out['affected'] ?? [])
            ->filter(fn ($a) => isset($byKey[$a['doc_key'] ?? '']))
            ->map(fn ($a) => $a + ['manual_edit' => $byKey[$a['doc_key']]->currentVersion?->source === 'user'])
            ->values()->all();
        $out['delta_md'] = round((float) ($out['delta_md'] ?? 0), 1);
        $out['summary'] = (string) ($out['summary'] ?? '');

        return $out;
    }
```

- [ ] **Step 4: Test hijau**

Run: `php artisan test --filter=ImpactAnalysisTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/SpecEngine.php tests/Feature/ImpactAnalysisTest.php
git commit -m "feat: SpecEngine::impact — analisa dampak perubahan (FR-09)"
```

---

### Task 2: Endpoint impact + `ImpactController`

**Files:**
- Create: `app/Http/Controllers/ImpactController.php`
- Modify: `routes/web.php` (dalam group `auth`, dekat blok assistant ±baris 85)
- Test: `tests/Feature/ImpactAnalysisTest.php` (tambah method)

**Interfaces:**
- Consumes: `SpecEngine::impact()` (Task 1), `ProjectController::authorizeProject()` (existing static).
- Produces: `POST projects/{project}/impact` name `projects.impact` → JSON body impact. Dipakai frontend Task 6.

- [ ] **Step 1: Tambah failing test di `ImpactAnalysisTest`**

```php
    public function test_impact_endpoint_returns_json(): void
    {
        $project = $this->projectWithDocs();
        $user = User::factory()->create(['workspace_id' => $project->workspace_id]);

        $res = $this->actingAs($user)->postJson(route('projects.impact', $project), [
            'change_text' => 'Tambah fitur notifikasi email',
        ]);

        $res->assertOk()->assertJsonStructure(['summary', 'delta_md', 'affected' => [['doc_key', 'reason', 'manual_edit']]]);
    }
```

Sesuaikan cara ambil `$user` dengan pola auth test existing (lihat `AssistantChatTest`).

- [ ] **Step 2: Gagal**

Run: `php artisan test --filter=impact_endpoint`
Expected: FAIL — route `projects.impact` not defined

- [ ] **Step 3: Controller + route**

`app/Http/Controllers/ImpactController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\ChangeRequestService;
use App\Services\GenerationPipeline;
use App\Services\SpecEngine;
use Illuminate\Http\Request;

/** FR-09/FR-10: analisa dampak + regen selektif. Stateless — hasil impact tidak disimpan (design §1). */
class ImpactController extends Controller
{
    public function analyze(Request $request, Project $project, SpecEngine $engine)
    {
        ProjectController::authorizeProject($request, $project);
        $data = $request->validate(['change_text' => 'required|string|max:5000']);

        return response()->json($engine->impact($project, $data['change_text']));
    }
}
```

`routes/web.php` (import `ImpactController` di atas, lalu dalam group auth):

```php
    // FR-09/FR-10: impact analysis + selective regeneration
    Route::post('projects/{project}/impact', [ImpactController::class, 'analyze'])->name('projects.impact');
```

- [ ] **Step 4: Hijau**

Run: `php artisan test --filter=ImpactAnalysisTest`
Expected: PASS (2 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/ImpactController.php routes/web.php tests/Feature/ImpactAnalysisTest.php
git commit -m "feat: endpoint POST projects/{project}/impact (FR-09)"
```

---

### Task 3: `startRegeneration` + branch regen di job

**Files:**
- Modify: `database/migrations/` → create `2026_07_17_000011_add_meta_to_generation_runs.php`
- Modify: `app/Models/GenerationRun.php` (cast `meta`)
- Modify: `app/Services/GenerationPipeline.php`
- Modify: `app/Services/SpecEngine.php` (method `regenerateDocument`, sisipkan setelah `repairDocument`)
- Modify: `app/Jobs/GenerateDocumentJob.php`
- Test: `tests/Feature/RegenerationTest.php` (create)

**Interfaces:**
- Consumes: `topoSort`/`dispatchChain` (private existing), `config('spekta.doc_pipeline')`.
- Produces:
  - `GenerationPipeline::startRegeneration(Project $project, array $docKeys, string $instruction): GenerationRun` — run `trigger='regen'`, `meta=['instruction'=>...]`, node hanya `docKeys` yang valid & sudah punya dokumen. Throw `\InvalidArgumentException` bila kosong.
  - `SpecEngine::regenerateDocument(Project $project, string $docKey, array $upstreamDocs, string $instruction, string $currentMd, ?callable $onDelta = null): array` — return `[md, meta]`, `generated_by => 'ai-regen'`.

- [ ] **Step 1: Migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generation_runs', function (Blueprint $table) {
            $table->json('meta')->nullable(); // trigger=regen: ['instruction' => …]
        });
    }

    public function down(): void
    {
        Schema::table('generation_runs', function (Blueprint $table) {
            $table->dropColumn('meta');
        });
    }
};
```

`app/Models/GenerationRun.php`: tambah `'meta' => 'array'` ke `$casts` (buat properti `$casts` bila belum ada, gabung dengan cast existing bila ada).

- [ ] **Step 2: Failing test**

`tests/Feature/RegenerationTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Jobs\GenerateDocumentJob;
use App\Models\Project;
use App\Models\User;
use App\Services\GenerationPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RegenerationTest extends TestCase
{
    use RefreshDatabase;

    private function projectWithDocs(): Project
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['workspace_id' => $user->workspace_id]);
        foreach (['PRD', 'REQUIREMENTS', 'ROADMAP'] as $key) {
            $doc = $project->documents()->create(['doc_key' => $key, 'title' => $key.'.md']);
            $v = $doc->versions()->create(['version_no' => 1, 'content_md' => "# $key isi awal", 'source' => 'ai']);
            $doc->update(['current_version_id' => $v->id]);
        }

        return $project;
    }

    public function test_start_regeneration_creates_subset_run(): void
    {
        $project = $this->projectWithDocs();

        $run = app(GenerationPipeline::class)->startRegeneration($project, ['REQUIREMENTS', 'ROADMAP'], 'Tambah FR notifikasi');

        $this->assertSame('regen', $run->trigger);
        $this->assertSame('Tambah FR notifikasi', $run->meta['instruction']);
        $this->assertEqualsCanonicalizing(['REQUIREMENTS', 'ROADMAP'], $run->nodes()->pluck('doc_key')->all());
    }

    public function test_regen_job_creates_new_version_as_revision(): void
    {
        $project = $this->projectWithDocs();
        $run = app(GenerationPipeline::class)->startRegeneration($project, ['REQUIREMENTS'], 'Tambah FR notifikasi');

        // queue sync di testing: job sudah jalan saat dispatchChain. Verifikasi hasil.
        $doc = $project->documents()->where('doc_key', 'REQUIREMENTS')->first();
        $this->assertSame(2, $doc->fresh()->currentVersion->version_no);
        $this->assertSame('ai', $doc->fresh()->currentVersion->source);
        $this->assertStringContainsString('regen', $doc->fresh()->currentVersion->content_md);
        $this->assertSame('done', $run->fresh()->status);
    }
}
```

Catatan: bila queue testing bukan sync (cek `phpunit.xml` / `.env.testing`), jalankan job manual: `(new GenerateDocumentJob($run->nodes()->first()->id))->handle(app(\App\Services\SpecEngine::class));`

- [ ] **Step 3: Gagal**

Run: `php artisan test --filter=RegenerationTest`
Expected: FAIL — `startRegeneration` undefined

- [ ] **Step 4: Implementasi**

`GenerationPipeline` (setelah `startMissing`):

```php
    /** FR-10: regen selektif dokumen terdampak dengan instruksi perubahan (design §2). */
    public function startRegeneration(Project $project, array $docKeys, string $instruction): GenerationRun
    {
        $graph = config('spekta.doc_pipeline');
        $existing = $project->documents()->pluck('doc_key')->all();
        $keys = array_values(array_intersect($docKeys, array_keys($graph), $existing));
        if (! $keys) {
            throw new \InvalidArgumentException('Tidak ada dokumen valid untuk diregenerasi.');
        }

        $run = $project->generationRuns()->create([
            'trigger' => 'regen',
            'status' => 'queued',
            'meta' => ['instruction' => $instruction],
        ]);

        $nodes = [];
        foreach ($this->topoSort($keys, $graph) as $key) {
            $nodes[] = $run->nodes()->create([
                'doc_key' => $key,
                // upstream boleh dokumen yang tak ikut regen — job baca isinya dari DB
                'depends_on' => array_values(array_intersect($graph[$key] ?? [], $existing)),
            ]);
        }

        $this->dispatchChain($nodes);

        return $run;
    }
```

`SpecEngine::regenerateDocument` (setelah `repairDocument`, pola sama):

```php
    // ---------- FR-10: selective regeneration ----------

    /** Revisi dokumen existing sesuai instruksi perubahan — bukan tulis ulang dari nol. Return [md, meta]. */
    public function regenerateDocument(Project $project, string $docKey, array $upstreamDocs, string $instruction, string $currentMd, ?callable $onDelta = null): array
    {
        if ($this->driver() === 'stub') {
            return [$currentMd."\n\n<!-- regen: $instruction -->",
                ['model' => 'stub', 'tokens_in' => 0, 'tokens_out' => 0, 'generated_by' => 'ai-regen']];
        }

        $started = microtime(true);
        $format = $docKey === 'WIREFRAMES'
            ? 'Konten dokumen ini JSON berskema {"screens":[…]} — balas HANYA JSON valid lengkap tanpa code fence.'
            : 'Balas HANYA markdown lengkap hasil revisi, tanpa pembuka/penutup.';

        $md = $this->text('standard', <<<SYS
Kamu technical writer software house Indonesia. Revisi dokumen $docKey sesuai INSTRUKSI PERUBAHAN.
Revisi = salin seluruh dokumen lalu ubah bagian yang terdampak; DILARANG meringkas, memotong, atau menghapus seksi yang tidak berkaitan.
Jaga konsistensi nomor FR/BR, entity, dan istilah dengan dokumen upstream di konteks.
$format
SYS, "INSTRUKSI PERUBAHAN:\n$instruction\n\nKONTEKS PROYEK:\n".$this->documentContext($project, $upstreamDocs)
            ."\n\n=== DOKUMEN SAAT INI ($docKey) ===\n".$currentMd, $ti, $to, $onDelta);

        if ($docKey === 'WIREFRAMES') {
            $md = preg_replace('/^```(json)?\s*|```\s*$/m', '', trim($md));
        }

        return [$md, [
            'model' => config('spekta.llm.models.standard'),
            'tokens_in' => $ti,
            'tokens_out' => $to,
            'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            'generated_by' => 'ai-regen',
        ]];
    }
```

`GenerateDocumentJob::handle()` — ganti blok try generate (±baris 62-69) jadi:

```php
        try {
            [$md, $meta] = $run->trigger === 'regen'
                ? $engine->regenerateDocument(
                    $project,
                    $node->doc_key,
                    $upstream,
                    (string) ($run->meta['instruction'] ?? ''),
                    (string) $project->documents()->where('doc_key', $node->doc_key)->first()?->currentVersion?->content_md,
                    $onDelta,
                )
                : $engine->generateDocument($project, $node->doc_key, $upstream, $onDelta);
        } catch (\App\Exceptions\LlmTruncated $e) {
            // Deterministik — retry pasti kena batas yang sama; fail langsung, jangan bakar 2 panggilan LLM lagi
            $this->fail($e);

            return;
        }
```

- [ ] **Step 5: Hijau + full suite**

Run: `php artisan test --filter=RegenerationTest && php artisan test`
Expected: PASS semua (regresi `GenerateMissingTest` dkk tetap hijau)

- [ ] **Step 6: Commit**

```bash
git add database/migrations app/Models/GenerationRun.php app/Services/GenerationPipeline.php app/Services/SpecEngine.php app/Jobs/GenerateDocumentJob.php tests/Feature/RegenerationTest.php
git commit -m "feat: selective regeneration via trigger=regen (FR-10)"
```

---

### Task 4: Endpoint regenerate + guard BR-25

**Files:**
- Modify: `app/Http/Controllers/ImpactController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/RegenerationTest.php` (tambah)

**Interfaces:**
- Consumes: `startRegeneration` (Task 3), `ChangeRequestService::editAllowed()` (existing).
- Produces: `POST projects/{project}/regenerate` name `projects.regenerate`, body `{change_text, doc_keys[]}` → redirect back. Frontend Task 6 memakai.

- [ ] **Step 1: Failing test**

```php
    public function test_regenerate_endpoint_starts_run(): void
    {
        $project = $this->projectWithDocs();
        $user = User::factory()->create(['workspace_id' => $project->workspace_id]);

        $this->actingAs($user)->post(route('projects.regenerate', $project), [
            'change_text' => 'Tambah FR notifikasi',
            'doc_keys' => ['REQUIREMENTS'],
        ])->assertRedirect();

        $this->assertSame('regen', $project->generationRuns()->latest()->first()->trigger);
    }

    public function test_regenerate_blocked_on_baselined_doc_without_cr(): void
    {
        $project = $this->projectWithDocs();
        $project->update(['status' => 'approved']); // BR-25 aktif
        $user = User::factory()->create(['workspace_id' => $project->workspace_id]);

        $this->actingAs($user)->post(route('projects.regenerate', $project), [
            'change_text' => 'x',
            'doc_keys' => ['REQUIREMENTS'],
        ])->assertForbidden();
    }
```

- [ ] **Step 2: Gagal** — Run: `php artisan test --filter=regenerate` → route not defined.

- [ ] **Step 3: Implementasi**

`ImpactController` tambah:

```php
    public function regenerate(Request $request, Project $project, GenerationPipeline $pipeline, ChangeRequestService $crs)
    {
        ProjectController::authorizeProject($request, $project);
        $data = $request->validate([
            'change_text' => 'required|string|max:5000',
            'doc_keys' => 'required|array|min:1',
            'doc_keys.*' => 'string',
        ]);

        // BR-25: dokumen ter-baseline hanya boleh berubah lewat CR yang mencakupnya
        foreach ($data['doc_keys'] as $key) {
            abort_unless($crs->editAllowed($project, $key), 403,
                "Proyek sudah di-approve — regenerasi $key wajib lewat Change Request (BR-25).");
        }

        try {
            $pipeline->startRegeneration($project, $data['doc_keys'], $data['change_text']);
        } catch (\InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return back();
    }
```

Route:

```php
    Route::post('projects/{project}/regenerate', [ImpactController::class, 'regenerate'])->name('projects.regenerate');
```

- [ ] **Step 4: Hijau** — `php artisan test --filter=RegenerationTest`

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/ImpactController.php routes/web.php tests/Feature/RegenerationTest.php
git commit -m "feat: endpoint regenerate selektif + guard BR-25 (FR-10)"
```

---

### Task 5: CR "hitung impact AI"

**Files:**
- Modify: `app/Http/Controllers/ImpactController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/ImpactAnalysisTest.php` (tambah)

**Interfaces:**
- Consumes: `SpecEngine::impact()`, `ChangeRequestService::setImpact()` (existing).
- Produces: `POST projects/{project}/change-requests/{crId}/impact-ai` name `projects.cr.impact-ai` → mengisi `delta_md` + `affected_doc_keys` CR.

- [ ] **Step 1: Failing test**

```php
    public function test_cr_impact_ai_fills_delta_and_docs(): void
    {
        $project = $this->projectWithDocs();
        $user = User::factory()->create(['workspace_id' => $project->workspace_id]);
        $cr = app(\App\Services\ChangeRequestService::class)->create($project, [
            'title' => 'Tambah notifikasi', 'source' => 'team', 'requested_by' => $user->email,
        ]);

        $this->actingAs($user)->post(route('projects.cr.impact-ai', [$project, $cr->id]))->assertRedirect();

        $cr->refresh();
        $this->assertNotNull($cr->delta_md);
        $this->assertNotEmpty($cr->affected_doc_keys);
    }
```

- [ ] **Step 2: Gagal** — route not defined.

- [ ] **Step 3: Implementasi**

`ImpactController` tambah:

```php
    /** FR-20 + FR-09: isi impact CR otomatis dari engine — tim tetap bisa koreksi via update() existing. */
    public function forChangeRequest(Request $request, Project $project, string $crId, SpecEngine $engine, ChangeRequestService $service)
    {
        ProjectController::authorizeProject($request, $project);
        $cr = $project->changeRequests()->where('status', 'proposed')->findOrFail($crId);

        $impact = $engine->impact($project, trim($cr->title."\n".(string) $cr->description));
        $service->setImpact($cr, (float) $impact['delta_md'], array_column($impact['affected'], 'doc_key'));

        return back();
    }
```

Route:

```php
    Route::post('projects/{project}/change-requests/{crId}/impact-ai', [ImpactController::class, 'forChangeRequest'])->name('projects.cr.impact-ai');
```

- [ ] **Step 4: Hijau + full suite** — `php artisan test`

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/ImpactController.php routes/web.php tests/Feature/ImpactAnalysisTest.php
git commit -m "feat: tombol impact AI mengisi delta CR otomatis (FR-09/FR-20)"
```

---

### Task 6: Frontend impact — dialog + tombol CR

**Files:**
- Create: `resources/js/components/impact-dialog.tsx`
- Modify: `resources/js/pages/project.tsx`

**Interfaces:**
- Consumes: route names `projects.impact`, `projects.regenerate`, `projects.cr.impact-ai` (Task 2/4/5); tipe `ChangeRequestData` existing di project.tsx.
- Produces: komponen `<ImpactDialog projectId={string} open={boolean} onClose={() => void}>`.

- [ ] **Step 1: Komponen `impact-dialog.tsx`**

```tsx
import { router } from '@inertiajs/react';
import { useState } from 'react';

type Affected = { doc_key: string; reason: string; manual_edit: boolean };
type Impact = { summary: string; delta_md: number; affected: Affected[] };

function xsrf(): string {
    return decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
}

export default function ImpactDialog({ projectId, open, onClose }: { projectId: string; open: boolean; onClose: () => void }) {
    const [text, setText] = useState('');
    const [impact, setImpact] = useState<Impact | null>(null);
    const [selected, setSelected] = useState<Record<string, boolean>>({});
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    if (!open) return null;

    const analyze = async () => {
        setBusy(true);
        setError('');
        try {
            const res = await fetch(route('projects.impact', projectId), {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
                body: JSON.stringify({ change_text: text }),
            });
            if (!res.ok) throw new Error(`Analisa gagal (${res.status})`);
            const data: Impact = await res.json();
            setImpact(data);
            // Dokumen ber-edit manual default TIDAK dicentang — keputusan sadar user (design §2)
            setSelected(Object.fromEntries(data.affected.map((a) => [a.doc_key, !a.manual_edit])));
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Analisa gagal');
        } finally {
            setBusy(false);
        }
    };

    const regenerate = () => {
        const doc_keys = Object.keys(selected).filter((k) => selected[k]);
        router.post(
            route('projects.regenerate', projectId),
            { change_text: text, doc_keys },
            { preserveScroll: true, onSuccess: () => { onClose(); setImpact(null); setText(''); } },
        );
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40" onClick={onClose}>
            <div className="w-full max-w-lg rounded-xl bg-white p-5 shadow-xl dark:bg-neutral-900" onClick={(e) => e.stopPropagation()}>
                <h3 className="mb-3 text-sm font-semibold">Usulkan perubahan — analisa dampak</h3>
                {!impact ? (
                    <>
                        <textarea
                            className="h-28 w-full rounded-md border p-2 text-sm"
                            placeholder="Jelaskan perubahan yang diinginkan…"
                            value={text}
                            onChange={(e) => setText(e.target.value)}
                        />
                        {error && <p className="mt-1 text-xs text-red-600">{error}</p>}
                        <div className="mt-3 flex justify-end gap-2">
                            <button className="rounded-md px-3 py-1.5 text-sm" onClick={onClose}>Batal</button>
                            <button
                                className="rounded-md bg-neutral-900 px-3 py-1.5 text-sm text-white disabled:opacity-50 dark:bg-white dark:text-neutral-900"
                                disabled={busy || text.trim().length < 5}
                                onClick={analyze}
                            >
                                {busy ? 'Menganalisa…' : 'Analisa dampak'}
                            </button>
                        </div>
                    </>
                ) : (
                    <>
                        <p className="text-sm">{impact.summary}</p>
                        <p className="mt-1 text-xs text-neutral-500">Perkiraan delta effort: {impact.delta_md} MD</p>
                        <ul className="mt-3 space-y-1">
                            {impact.affected.map((a) => (
                                <li key={a.doc_key} className="flex items-start gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        className="mt-0.5"
                                        checked={!!selected[a.doc_key]}
                                        onChange={(e) => setSelected({ ...selected, [a.doc_key]: e.target.checked })}
                                    />
                                    <span>
                                        <b>{a.doc_key}</b> — {a.reason}
                                        {a.manual_edit && (
                                            <span className="ml-1 rounded bg-amber-100 px-1 text-[10px] text-amber-800">ada edit manual</span>
                                        )}
                                    </span>
                                </li>
                            ))}
                        </ul>
                        <div className="mt-4 flex justify-end gap-2">
                            <button className="rounded-md px-3 py-1.5 text-sm" onClick={() => setImpact(null)}>Kembali</button>
                            <button
                                className="rounded-md bg-neutral-900 px-3 py-1.5 text-sm text-white disabled:opacity-50 dark:bg-white dark:text-neutral-900"
                                disabled={!Object.values(selected).some(Boolean)}
                                onClick={regenerate}
                            >
                                Regenerate terpilih
                            </button>
                        </div>
                    </>
                )}
            </div>
        </div>
    );
}
```

Sesuaikan className dengan pola styling existing di project.tsx (lihat modal/tombol existing — tiru kelasnya, bukan berkreasi baru).

- [ ] **Step 2: Integrasi di `project.tsx`**

1. Import: `import ImpactDialog from '@/components/impact-dialog';`
2. State: `const [impactOpen, setImpactOpen] = useState(false);`
3. Tombol di header dokumen/toolbar (dekat tombol existing sekitar area health/share): `<button onClick={() => setImpactOpen(true)}>Usulkan perubahan</button>` — pakai kelas tombol sekunder existing.
4. Render sebelum `<AssistantDrawer …>`: `<ImpactDialog projectId={project.id} open={impactOpen} onClose={() => setImpactOpen(false)} />`
5. Di kartu CR (dekat tombol "Isi impact" ±baris 511) tambah tombol:

```tsx
<button
    className="…kelas tombol kecil existing…"
    onClick={() => router.post(route('projects.cr.impact-ai', [project.id, cr.id]), {}, { preserveScroll: true })}
>
    Impact AI
</button>
```

- [ ] **Step 3: Build**

Run: `npm run build`
Expected: sukses tanpa error TS.

- [ ] **Step 4: Commit**

```bash
git add resources/js/components/impact-dialog.tsx resources/js/pages/project.tsx
git commit -m "feat: dialog impact analysis + tombol Impact AI di CR (FR-09/FR-10)"
```

---

### Task 7: FR-12 — migration language + `translate` + job

**Files:**
- Create: `database/migrations/2026_07_17_000012_add_language_to_document_versions.php`
- Modify: `app/Models/Project.php` (helper bahasa)
- Modify: `app/Services/SpecEngine.php` (method `translate`)
- Create: `app/Jobs/TranslateDocumentJob.php`
- Test: `tests/Feature/TranslateDocumentTest.php` (create)

**Interfaces:**
- Produces:
  - Kolom `document_versions.language` (string 5, default `'id'`), unique `(document_id, version_no, language)`.
  - `Project::primaryLanguage(): string` (`'en'` bila `language==='en'`, selain itu `'id'`), `Project::variantLanguage(): string` (kebalikannya).
  - `SpecEngine::translate(Project $project, DocumentVersion $version, string $target): array` → `[md, meta]`, `generated_by => 'ai-translate'`. Stub: prefix `[[EN]] ` / `[[ID]] `.
  - `TranslateDocumentJob(string $documentId)` — buat baris varian `version_no` sama, `language` = variant, idempotent (skip bila sudah ada).

- [ ] **Step 1: Migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_versions', function (Blueprint $table) {
            $table->string('language', 5)->default('id');
        });
        // Backfill: proyek berbahasa EN → versi existing dianggap EN
        DB::statement("update document_versions set language = 'en' where document_id in (
            select d.id from documents d join projects p on p.id = d.project_id where p.language = 'en')");
        Schema::table('document_versions', function (Blueprint $table) {
            $table->dropUnique(['document_id', 'version_no']);
            $table->unique(['document_id', 'version_no', 'language']);
        });
    }

    public function down(): void
    {
        Schema::table('document_versions', function (Blueprint $table) {
            $table->dropUnique(['document_id', 'version_no', 'language']);
            $table->unique(['document_id', 'version_no']);
            $table->dropColumn('language');
        });
    }
};
```

- [ ] **Step 2: Failing test**

```php
<?php

namespace Tests\Feature;

use App\Jobs\TranslateDocumentJob;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TranslateDocumentTest extends TestCase
{
    use RefreshDatabase;

    private function docProject(): Project
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['workspace_id' => $user->workspace_id, 'language' => 'id']);
        $doc = $project->documents()->create(['doc_key' => 'PRD', 'title' => 'PRD.md']);
        $v = $doc->versions()->create(['version_no' => 1, 'content_md' => '# PRD', 'source' => 'ai']);
        $doc->update(['current_version_id' => $v->id]);

        return $project;
    }

    public function test_translate_job_creates_variant_same_version_no(): void
    {
        $project = $this->docProject();
        $doc = $project->documents()->first();

        (new TranslateDocumentJob($doc->id))->handle(app(\App\Services\SpecEngine::class));

        $variant = $doc->versions()->where('language', 'en')->first();
        $this->assertNotNull($variant);
        $this->assertSame(1, $variant->version_no);
        $this->assertStringContainsString('[[EN]]', $variant->content_md);
        // current_version tetap versi utama
        $this->assertSame('id', $doc->fresh()->currentVersion->language);
    }

    public function test_translate_job_idempotent(): void
    {
        $project = $this->docProject();
        $doc = $project->documents()->first();

        (new TranslateDocumentJob($doc->id))->handle(app(\App\Services\SpecEngine::class));
        (new TranslateDocumentJob($doc->id))->handle(app(\App\Services\SpecEngine::class));

        $this->assertSame(1, $doc->versions()->where('language', 'en')->count());
    }
}
```

- [ ] **Step 3: Gagal** — `php artisan test --filter=TranslateDocumentTest` → class not found.

- [ ] **Step 4: Implementasi**

`app/Models/Project.php` tambah:

```php
    /** FR-12: bahasa utama dokumen ('bilingual' dianggap id). */
    public function primaryLanguage(): string
    {
        return $this->language === 'en' ? 'en' : 'id';
    }

    public function variantLanguage(): string
    {
        return $this->primaryLanguage() === 'id' ? 'en' : 'id';
    }
```

`SpecEngine::translate` (setelah `regenerateDocument`):

```php
    // ---------- FR-12: bilingual ----------

    /** Terjemahkan satu versi dokumen — struktur, nomor FR/BR, tabel, mermaid dipertahankan. Return [md, meta]. */
    public function translate(Project $project, DocumentVersion $version, string $target): array
    {
        if ($this->driver() === 'stub') {
            return ['[['.strtoupper($target).']] '.$version->content_md,
                ['model' => 'stub', 'tokens_in' => 0, 'tokens_out' => 0, 'generated_by' => 'ai-translate']];
        }

        $started = microtime(true);
        $lang = $target === 'en' ? 'English' : 'Bahasa Indonesia';
        $md = $this->text('economy', <<<SYS
Kamu penerjemah dokumen spesifikasi software. Terjemahkan seluruh dokumen ke $lang.
ATURAN KERAS: struktur heading, penomoran FR/BR, tabel, dan blok kode/mermaid TIDAK berubah;
istilah teknis (nama API, entity, library, istilah engineering baku) TIDAK diterjemahkan.
Balas HANYA hasil terjemahan lengkap, tanpa pembuka/penutup.
SYS, $version->content_md, $ti, $to);

        return [$md, [
            'model' => config('spekta.llm.models.economy'),
            'tokens_in' => $ti,
            'tokens_out' => $to,
            'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            'generated_by' => 'ai-translate',
        ]];
    }
```

Import `DocumentVersion` di SpecEngine bila belum: `use App\Models\DocumentVersion;`

`app/Jobs/TranslateDocumentJob.php`:

```php
<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\SpecEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** FR-12: buat varian bahasa untuk versi current — version_no sama, baris terpisah. */
class TranslateDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 540;

    public function __construct(public string $documentId) {}

    public function handle(SpecEngine $engine): void
    {
        $document = Document::with('currentVersion', 'project')->findOrFail($this->documentId);
        $current = $document->currentVersion;
        if (! $current) {
            return;
        }
        $target = $document->project->variantLanguage();
        if ($document->versions()->where('version_no', $current->version_no)->where('language', $target)->exists()) {
            return; // idempotent — varian versi ini sudah ada
        }

        [$md, $meta] = $engine->translate($document->project, $current, $target);
        $document->versions()->create([
            'version_no' => $current->version_no,
            'content_md' => $md,
            'source' => 'ai',
            'language' => $target,
            'generated_meta' => $meta,
        ]);
    }
}
```

- [ ] **Step 5: Hijau + full suite** — `php artisan test`
Perhatian regresi: `DocumentController::showVersion` dan daftar versi di `ProjectController::show` kini bisa kena baris varian. Bila ada test merah, tambahkan filter `->where('language', $project->primaryLanguage())` pada query version-list/lookup tersebut (JANGAN pada `max('version_no')` — varian tak pernah melebihi versi utama).

- [ ] **Step 6: Commit**

```bash
git add database/migrations app/Models/Project.php app/Services/SpecEngine.php app/Jobs/TranslateDocumentJob.php tests/Feature/TranslateDocumentTest.php
git commit -m "feat: varian bahasa document_versions + TranslateDocumentJob (FR-12)"
```

---

### Task 8: Endpoint translate + payload varian

**Files:**
- Modify: `app/Http/Controllers/DocumentController.php`
- Modify: `app/Http/Controllers/ProjectController.php` (payload `show`)
- Modify: `routes/web.php`
- Test: `tests/Feature/TranslateDocumentTest.php` (tambah)

**Interfaces:**
- Produces:
  - `POST projects/{project}/documents/{docKey}/translate` name `projects.documents.translate` — dispatch job satu dokumen.
  - `POST projects/{project}/translate-all` name `projects.translate-all` — dispatch semua kecuali WIREFRAMES.
  - `DocumentController::showByKey` menerima query `?lang=<variant>` → kembalikan konten varian (fallback: varian version_no tertinggi bila varian current belum ada).
  - Payload `ProjectController::show` per dokumen bertambah: `variant_language` (string), `variant_version_no` (int|null). Frontend Task 9: `available = variant_version_no !== null`, `stale = variant_version_no < current version_no`.

- [ ] **Step 1: Failing test**

```php
    public function test_translate_all_dispatches_per_doc_except_wireframes(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $project = $this->docProject();
        $project->documents()->create(['doc_key' => 'WIREFRAMES', 'title' => 'WIREFRAMES']);
        $user = User::factory()->create(['workspace_id' => $project->workspace_id]);

        $this->actingAs($user)->post(route('projects.translate-all', $project))->assertRedirect();

        \Illuminate\Support\Facades\Queue::assertPushed(TranslateDocumentJob::class, 1); // PRD saja
    }

    public function test_show_by_key_returns_variant_content(): void
    {
        $project = $this->docProject();
        $doc = $project->documents()->first();
        (new TranslateDocumentJob($doc->id))->handle(app(\App\Services\SpecEngine::class));
        $user = User::factory()->create(['workspace_id' => $project->workspace_id]);

        $res = $this->actingAs($user)->getJson(route('projects.documents.show', [$project, 'PRD']).'?lang=en');

        $res->assertOk();
        $this->assertStringContainsString('[[EN]]', $res->json('content_md') ?? json_encode($res->json()));
    }
```

Sesuaikan assertion `content_md` dengan bentuk respons `showByKey` existing (baca method itu dulu; ambil key JSON yang benar).

- [ ] **Step 2: Gagal** — route not defined.

- [ ] **Step 3: Implementasi**

`DocumentController` tambah:

```php
    /** FR-12 */
    public function translate(Request $request, Project $project, string $docKey)
    {
        ProjectController::authorizeProject($request, $project);
        $document = $project->documents()->where('doc_key', $docKey)->firstOrFail();
        \App\Jobs\TranslateDocumentJob::dispatch($document->id);

        return back();
    }

    /** FR-12: seluruh set. WIREFRAMES dilewati — kontennya JSON layout. */
    public function translateAll(Request $request, Project $project)
    {
        ProjectController::authorizeProject($request, $project);
        foreach ($project->documents()->where('doc_key', '!=', 'WIREFRAMES')->pluck('id') as $id) {
            \App\Jobs\TranslateDocumentJob::dispatch($id);
        }

        return back();
    }
```

`showByKey`: setelah dokumen ditemukan, bila `$request->query('lang')` dan ≠ `primaryLanguage()`:

```php
        $lang = $request->query('lang');
        if ($lang && $lang !== $project->primaryLanguage()) {
            $variant = $document->versions()->where('language', $lang)->orderByDesc('version_no')->first();
            abort_unless($variant, 404, 'Varian bahasa belum tersedia.');
            // kembalikan konten varian pada struktur respons yang sama dengan jalur normal
        }
```

(Sesuaikan dengan bentuk respons existing method itu — ganti sumber `content_md` ke `$variant->content_md`.)

`ProjectController::show` — pada map dokumen tambahkan:

```php
            'variant_language' => $project->variantLanguage(),
            'variant_version_no' => $d->versions()->where('language', $project->variantLanguage())->max('version_no'),
```

Routes:

```php
    // FR-12: bilingual
    Route::post('projects/{project}/documents/{docKey}/translate', [DocumentController::class, 'translate'])->name('projects.documents.translate');
    Route::post('projects/{project}/translate-all', [DocumentController::class, 'translateAll'])->name('projects.translate-all');
```

- [ ] **Step 4: Hijau + full suite** — `php artisan test`

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/DocumentController.php app/Http/Controllers/ProjectController.php routes/web.php tests/Feature/TranslateDocumentTest.php
git commit -m "feat: endpoint translate per-dokumen & translate-all + payload varian (FR-12)"
```

---

### Task 9: Frontend bilingual — toggle + tombol terjemah + badge usang

**Files:**
- Modify: `resources/js/pages/project.tsx`

**Interfaces:**
- Consumes: `variant_language`/`variant_version_no` di props dokumen (Task 8), route `projects.documents.show` dengan `?lang=`, routes translate (Task 8).

- [ ] **Step 1: Implementasi UI**

1. Tipe dokumen di project.tsx: tambah `variant_language: string; variant_version_no: number | null;`.
2. State: `const [docLang, setDocLang] = useState<'primary' | 'variant'>('primary');` — reset ke `'primary'` saat ganti dokumen aktif.
3. Di toolbar viewer dokumen:
   - Bila `doc.variant_version_no !== null`: render toggle dua tombol kecil `ID | EN` (label dari `variant_language`); klik variant → fetch `route('projects.documents.show', [project.id, doc.doc_key]) + '?lang=' + doc.variant_language` (fetch JSON pola sama dengan viewer existing memuat konten; bila viewer pakai props Inertia, pakai `router.get` dengan `preserveState` + query lang mengikuti pola existing halaman).
   - Bila `doc.variant_version_no < currentVersionNo`: badge kecil `terjemahan usang` di sebelah toggle + tombol kecil "Perbarui" → `router.post(route('projects.documents.translate', [project.id, doc.doc_key]))`.
   - Bila varian belum ada: tombol kecil "Terjemahkan ({doc.variant_language.toUpperCase()})" → route sama.
4. Di header daftar dokumen: tombol "Terjemahkan semua" → `router.post(route('projects.translate-all', project.id))`.
5. Mode edit hanya untuk bahasa utama — saat `docLang === 'variant'`, sembunyikan tombol Edit (varian selalu hasil AI dari versi utama).

- [ ] **Step 2: Build** — `npm run build` sukses.

- [ ] **Step 3: Commit**

```bash
git add resources/js/pages/project.tsx
git commit -m "feat: toggle bahasa dokumen + terjemahkan + badge usang (FR-12)"
```

---

### Task 10: FR-11 (d)/(e) — rule regex sync

**Files:**
- Modify: `app/Services/SpecHealthValidator.php`
- Test: `tests/Unit/SpecHealthRulesTest.php` (create)

**Interfaces:**
- Produces (static, pure — testable tanpa DB):
  - `SpecHealthValidator::erdApiFindings(string $database, string $api): array`
  - `SpecHealthValidator::numberingFindings(array $docs): array` (key = doc_key)
  - Keduanya dipanggil dari `run()`.

- [ ] **Step 1: Failing test**

```php
<?php

namespace Tests\Unit;

use App\Services\SpecHealthValidator;
use PHPUnit\Framework\TestCase;

class SpecHealthRulesTest extends TestCase
{
    public function test_erd_entity_missing_in_api_flagged(): void
    {
        $db = "```mermaid\nerDiagram\n  users {\n    uuid id PK\n  }\n  invoices {\n    uuid id PK\n  }\n```";
        $api = '## GET /api/users';

        $findings = SpecHealthValidator::erdApiFindings($db, $api);

        $this->assertCount(1, $findings);
        $this->assertSame('erd_entity_in_api', $findings[0]['rule_key']);
        $this->assertStringContainsString('invoices', $findings[0]['message']);
    }

    public function test_erd_rule_silent_without_erd(): void
    {
        $this->assertSame([], SpecHealthValidator::erdApiFindings('# DATABASE tanpa diagram', '## API'));
    }

    public function test_duplicate_fr_flagged(): void
    {
        $docs = ['REQUIREMENTS' => "### FR-01: A\n- ac\n### FR-01: B\n- ac", 'PRD' => 'FR-01'];

        $keys = array_column(SpecHealthValidator::numberingFindings($docs), 'rule_key');

        $this->assertContains('fr_duplicate', $keys);
    }

    public function test_dangling_fr_ref_flagged_but_prd_frs_excluded(): void
    {
        $docs = [
            'PRD' => 'FR-01 dan FR-02',                       // FR-02 hilang = urusan rule fr_has_ac, bukan dangling
            'REQUIREMENTS' => "### FR-01: A\n- ac",
            'ROADMAP' => 'FR-01, FR-09 di fase 2',            // FR-09 tidak terdefinisi & tidak di PRD → dangling
        ];

        $findings = SpecHealthValidator::numberingFindings($docs);

        $this->assertCount(1, $findings);
        $this->assertSame('fr_dangling_ref', $findings[0]['rule_key']);
        $this->assertStringContainsString('FR-09', $findings[0]['message']);
    }
}
```

- [ ] **Step 2: Gagal** — `php artisan test --filter=SpecHealthRulesTest` → method undefined.

- [ ] **Step 3: Implementasi**

Tambahkan ke `SpecHealthValidator` (setelah method `run`, sebelum helper private):

```php
    /** FR-11(d): tiap entity di erDiagram DATABASE dirujuk di API.md. Static murni — unit-testable. */
    public static function erdApiFindings(string $database, string $api): array
    {
        if ($database === '' || $api === '' || ! str_contains($database, 'erDiagram')) {
            return [];
        }
        // ponytail: entity = "nama {" di dalam blok erDiagram; regex cukup, parser mermaid berlebihan
        preg_match_all('/^\s{0,8}([A-Za-z_][A-Za-z0-9_]*)\s*\{/m', $database, $m);
        $findings = [];
        foreach (array_unique($m[1]) as $entity) {
            if (stripos($api, $entity) === false) {
                $findings[] = ['rule_key' => 'erd_entity_in_api', 'severity' => 'warning', 'location' => "DATABASE / $entity",
                    'message' => "Entity \"$entity\" di ERD tidak dirujuk di API.md",
                    'suggestion' => "Tambahkan endpoint/schema yang memakai $entity di API.md, atau hapus entity tak terpakai dari ERD."];
            }
        }

        return $findings;
    }

    /** FR-11(e): FR dirujuk harus terdefinisi di REQUIREMENTS; nomor FR tidak dobel. */
    public static function numberingFindings(array $docs): array
    {
        $req = $docs['REQUIREMENTS'] ?? '';
        if ($req === '') {
            return [];
        }
        preg_match_all('/^#{2,4}\s.*?\b(FR-\d+)\b/m', $req, $m);
        $defined = $m[1];
        $findings = [];
        foreach (array_unique(array_diff_assoc($defined, array_unique($defined))) as $dup) {
            $findings[] = ['rule_key' => 'fr_duplicate', 'severity' => 'warning', 'location' => "REQUIREMENTS / $dup",
                'message' => "$dup terdefinisi lebih dari satu kali di REQUIREMENTS.md",
                'suggestion' => 'Gabungkan section duplikat atau renumber agar tiap FR unik.'];
        }
        preg_match_all('/\bFR-\d+\b/', implode("\n", $docs), $refs);
        preg_match_all('/\bFR-\d+\b/', $docs['PRD'] ?? '', $prd); // FR di PRD sudah dijaga rule fr_has_ac
        foreach (array_diff(array_unique($refs[0]), $defined, array_unique($prd[0])) as $ref) {
            $findings[] = ['rule_key' => 'fr_dangling_ref', 'severity' => 'warning', 'location' => "REQUIREMENTS / $ref",
                'message' => "$ref dirujuk di dokumen tapi tidak terdefinisi di REQUIREMENTS.md",
                'suggestion' => "Tambahkan section $ref di REQUIREMENTS atau perbaiki rujukan yang salah nomor."];
        }

        return $findings;
    }
```

Integrasi di `run()` — setelah blok SECURITY (±baris 91), sebelum hitung skor:

```php
        // FR-11 aturan lanjutan (d)+(e)
        foreach (self::erdApiFindings($docs['DATABASE'] ?? '', $docs['API'] ?? '') as $f) {
            $findings[] = $f;
        }
        foreach (self::numberingFindings($docs->all()) as $f) {
            $findings[] = $f;
        }
```

- [ ] **Step 4: Hijau + full suite** — `php artisan test`
Perhatian: aturan baru bisa menurunkan skor di test existing yang assert `health_score` (mis. `MvpFlowTest`). Bila merah karena skor, periksa temuannya — dokumen stub mungkin memicu dangling ref; perbaiki stub dokumen di `SpecEngine::stubDocument` agar konsisten ATAU longgarkan assertion skor test itu (pilih yang membuat stub lebih realistis).

- [ ] **Step 5: Commit**

```bash
git add app/Services/SpecHealthValidator.php tests/Unit/SpecHealthRulesTest.php
git commit -m "feat: spec health aturan (d) erd-api & (e) penomoran FR (FR-11)"
```

---

### Task 11: FR-11 (f) — kontradiksi async

**Files:**
- Modify: `app/Services/SpecEngine.php` (method `findContradictions`)
- Modify: `app/Services/SpecHealthValidator.php` (`run()` preserve contradiction + `recomputeScore`)
- Create: `app/Jobs/ContradictionCheckJob.php`
- Modify: `app/Jobs/GenerateDocumentJob.php` (dispatch saat run selesai)
- Modify: `app/Http/Controllers/ProjectController.php` + `routes/web.php` (trigger manual)
- Test: `tests/Feature/ContradictionCheckTest.php` (create)

**Interfaces:**
- Produces:
  - `SpecEngine::findContradictions(Project $project): array` → `[['location','message','suggestion']]`; stub → `[]`.
  - `SpecHealthValidator::recomputeScore(Project $project): int` — skor dari seluruh findings tersimpan `resolved=false`.
  - `ContradictionCheckJob(string $projectId)` — replace findings `rule_key='contradiction'` lalu `recomputeScore`.
  - `POST projects/{project}/health/contradictions` name `projects.health.contradictions`.

- [ ] **Step 1: Failing test**

```php
<?php

namespace Tests\Feature;

use App\Jobs\ContradictionCheckJob;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ContradictionCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_replaces_contradiction_findings_and_recomputes_score(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['workspace_id' => $user->workspace_id, 'health_score' => 100]);
        $project->healthFindings()->create([
            'rule_key' => 'contradiction', 'severity' => 'warning', 'message' => 'lama', 'location' => 'X',
        ]);

        (new ContradictionCheckJob($project->id))->handle(
            app(\App\Services\SpecEngine::class),
            app(\App\Services\SpecHealthValidator::class),
        );

        // stub → tidak ada kontradiksi; temuan lama terhapus, skor pulih
        $this->assertSame(0, $project->healthFindings()->where('rule_key', 'contradiction')->count());
        $this->assertSame(100, $project->fresh()->health_score);
    }

    public function test_validator_run_preserves_contradiction_findings(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['workspace_id' => $user->workspace_id]);
        $project->healthFindings()->create([
            'rule_key' => 'contradiction', 'severity' => 'warning', 'message' => 'tetap ada', 'location' => 'X',
        ]);

        app(\App\Services\SpecHealthValidator::class)->run($project);

        $this->assertSame(1, $project->healthFindings()->where('rule_key', 'contradiction')->count());
    }

    public function test_manual_endpoint_dispatches_job(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $project = Project::factory()->create(['workspace_id' => $user->workspace_id]);

        $this->actingAs($user)->post(route('projects.health.contradictions', $project))->assertRedirect();

        Queue::assertPushed(ContradictionCheckJob::class);
    }
}
```

- [ ] **Step 2: Gagal** — class/route not found.

- [ ] **Step 3: Implementasi**

`SpecEngine::findContradictions` (setelah `translate`):

```php
    // ---------- FR-11(f): kontradiksi antar-requirement ----------

    /** Deteksi kontradiksi nyata antar dokumen — async (terlalu lambat untuk jalur sync validator). */
    public function findContradictions(Project $project): array
    {
        if ($this->driver() === 'stub') {
            return [];
        }

        $ctx = $project->documents()->with('currentVersion')->get()
            ->filter(fn ($d) => $d->doc_key !== 'WIREFRAMES')
            ->map(fn ($d) => "=== {$d->doc_key}.md ===\n".Str::limit((string) $d->currentVersion?->content_md, 15000, '… [dipotong]'))
            ->implode("\n\n");

        $out = $this->json('reasoning', <<<'SYS'
Kamu auditor spesifikasi software. Temukan KONTRADIKSI NYATA antar requirement/dokumen:
dua pernyataan yang tidak mungkin sama-sama benar (angka berbeda, aturan bertentangan, alur mustahil).
Bukan soal gaya bahasa atau kelengkapan. Balas JSON:
{"contradictions":[{"location":"DOC-A / DOC-B","message":"…","suggestion":"…"}]}
Bila tidak ada: {"contradictions":[]}.
SYS, $ctx);

        return array_values(array_filter($out['contradictions'] ?? [], 'is_array'));
    }
```

`SpecHealthValidator` — dua perubahan:

1. Baris pertama `run()`: `$project->healthFindings()->delete();` → `$project->healthFindings()->where('rule_key', '!=', 'contradiction')->delete();`
2. Ganti blok hitung skor di akhir `run()` (loop `$penalty`/`$score`/`update`) menjadi:

```php
        foreach ($findings as $f) {
            $project->healthFindings()->create($f);
        }

        return $this->recomputeScore($project);
    }

    /** Skor dari seluruh findings tersimpan — termasuk kontradiksi yang diisi async. */
    public function recomputeScore(Project $project): int
    {
        $penalty = ['critical' => 15, 'warning' => 7, 'info' => 2];
        $score = 100;
        foreach ($project->healthFindings()->where('resolved', false)->get() as $f) {
            $score -= $penalty[$f->severity] ?? 0;
        }
        $score = max(0, $score);
        $project->update(['health_score' => $score]);

        return $score;
    }
```

(Perhatikan penutup method `run()` existing — pindahkan sisa statement setelah blok skor bila ada, jangan ada kode mati.)

`app/Jobs/ContradictionCheckJob.php`:

```php
<?php

namespace App\Jobs;

use App\Models\Project;
use App\Services\SpecEngine;
use App\Services\SpecHealthValidator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** FR-11(f): cek kontradiksi via LLM, replace temuan lama, hitung ulang skor. */
class ContradictionCheckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 540;

    public function __construct(public string $projectId) {}

    public function handle(SpecEngine $engine, SpecHealthValidator $validator): void
    {
        $project = Project::findOrFail($this->projectId);
        $found = $engine->findContradictions($project);

        $project->healthFindings()->where('rule_key', 'contradiction')->delete();
        foreach ($found as $f) {
            $project->healthFindings()->create([
                'rule_key' => 'contradiction',
                'severity' => 'warning',
                'location' => $f['location'] ?? null,
                'message' => $f['message'] ?? '-',
                'suggestion' => $f['suggestion'] ?? null,
            ]);
        }
        $validator->recomputeScore($project);
    }
}
```

`GenerateDocumentJob` — di blok finalisasi run (setelah `app(SpecHealthValidator::class)->run($project);`):

```php
            ContradictionCheckJob::dispatch($project->id); // FR-11(f) — async, stub no-op
```

`ProjectController` tambah:

```php
    /** FR-11(f): trigger manual cek kontradiksi. */
    public function checkContradictions(Request $request, Project $project)
    {
        self::authorizeProject($request, $project);
        \App\Jobs\ContradictionCheckJob::dispatch($project->id);

        return back();
    }
```

Route:

```php
    Route::post('projects/{project}/health/contradictions', [ProjectController::class, 'checkContradictions'])->name('projects.health.contradictions');
```

- [ ] **Step 4: Hijau + full suite** — `php artisan test`

- [ ] **Step 5: Commit**

```bash
git add app/Services/SpecEngine.php app/Services/SpecHealthValidator.php app/Jobs/ContradictionCheckJob.php app/Jobs/GenerateDocumentJob.php app/Http/Controllers/ProjectController.php routes/web.php tests/Feature/ContradictionCheckTest.php
git commit -m "feat: cek kontradiksi antar-requirement async (FR-11f)"
```

---

### Task 12: Frontend health — tombol cek kontradiksi + verifikasi akhir

**Files:**
- Modify: `resources/js/pages/project.tsx`

**Interfaces:**
- Consumes: route `projects.health.contradictions` (Task 11). Temuan `rule_key='contradiction'` sudah tampil otomatis lewat panel findings existing (`FindingRow`).

- [ ] **Step 1: Tombol di panel Spec Health**

Di dekat header panel health (cari `healthColor` usage / daftar `FindingRow`), tambah tombol kecil:

```tsx
<button
    className="…kelas tombol kecil existing…"
    onClick={() => router.post(route('projects.health.contradictions', project.id), {}, { preserveScroll: true })}
>
    Cek kontradiksi
</button>
```

- [ ] **Step 2: Build + suite penuh**

Run: `npm run build && php artisan test`
Expected: build sukses, seluruh suite PASS.

- [ ] **Step 3: Commit**

```bash
git add resources/js/pages/project.tsx
git commit -m "feat: tombol cek kontradiksi di panel spec health (FR-11f)"
```

---

## Cakupan spec → task

| Spec § | Task |
|---|---|
| §1 FR-09 engine + chat + CR | 1, 2, 5, 6 |
| §2 FR-10 pipeline + konflik manual-edit | 3, 4, 6 (checkbox default off utk edit manual) |
| §3 FR-12 skema + translate + UI + usang | 7, 8, 9 |
| §4 FR-11 d/e sync | 10 |
| §4 FR-11 f async | 11, 12 |
| §5 error handling | 2/4 (validasi+abort), 3 (fail/resume existing), 7 (idempotent job) |
| §6 testing | tersebar per task, stub driver |
