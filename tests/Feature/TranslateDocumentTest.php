<?php

namespace Tests\Feature;

use App\Jobs\TranslateDocumentJob;
use App\Models\CreditLedger;
use App\Models\Project;
use App\Models\User;
use App\Services\SpecEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TranslateDocumentTest extends TestCase
{
    use RefreshDatabase;

    // Project::factory() belum ada (lihat ImpactAnalysisTest) — tiru pola register + POST /projects.
    // BUGFIX FR-12: projects.language TIDAK PERNAH ditulis aplikasi (selalu default 'id') — bahasa
    // primer proyek ditentukan via blueprint['language'] (Project::primaryLanguage()), bukan kolom
    // itu. Tanpa blueprint sama sekali, rantai fallback sudah jatuh ke 'id' — tidak perlu di-set.
    private function docProject(): Project
    {
        config(['spekta.llm.driver' => 'stub']);
        $this->post('/register', [
            'name' => 'M', 'company' => 'AC', 'email' => 'o@a.co',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ]);
        $user = User::firstOrFail();
        $this->actingAs($user)->post('/projects');
        $project = Project::firstOrFail();

        $doc = $project->documents()->create(['doc_key' => 'PRD', 'title' => 'PRD.md']);
        $v = $doc->versions()->create(['version_no' => 1, 'content_md' => '# PRD', 'source' => 'ai']);
        $doc->update(['current_version_id' => $v->id]);

        return $project->fresh();
    }

    // Varian docProject() berbahasa primer EN — dipakai untuk uji gate storeVersion/restoreVersion
    // (default kolom document_versions.language = 'id', salah untuk proyek berbahasa EN).
    // Bahasa primer di-set lewat blueprint (sumber nyata Project::primaryLanguage()), BUKAN kolom
    // projects.language yang mati — lihat ProjectLanguageTest utk jalur writer ASLI (wizard input).
    private function docProjectEn(): Project
    {
        $project = $this->docProject();
        $project->update(['blueprint' => ['language' => 'en']]);
        $project->documents()->first()->versions()->first()->update(['language' => 'en']);

        return $project->fresh();
    }

    public function test_translate_job_creates_variant_same_version_no(): void
    {
        $project = $this->docProject();
        $doc = $project->documents()->first();

        (new TranslateDocumentJob($doc->id))->handle(app(SpecEngine::class));

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

        (new TranslateDocumentJob($doc->id))->handle(app(SpecEngine::class));
        (new TranslateDocumentJob($doc->id))->handle(app(SpecEngine::class));

        $this->assertSame(1, $doc->versions()->where('language', 'en')->count());
    }

    public function test_translate_dispatches_single_document_job(): void
    {
        Queue::fake();
        $project = $this->docProject();
        $doc = $project->documents()->first();
        $user = User::firstOrFail(); // owner user dari docProject() — sudah terikat ke workspace via pivot

        $this->actingAs($user)->post(route('projects.documents.translate', [$project, 'PRD']))->assertRedirect();

        Queue::assertPushed(TranslateDocumentJob::class, fn ($job) => $job->documentId === $doc->id);
    }

    public function test_translate_all_dispatches_per_doc_except_wireframes(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $project = $this->docProject();
        $project->documents()->create(['doc_key' => 'WIREFRAMES', 'title' => 'WIREFRAMES']);
        $user = User::firstOrFail(); // owner user dari docProject() — sudah terikat ke workspace via pivot

        $this->actingAs($user)->post(route('projects.translate-all', $project))->assertRedirect();

        \Illuminate\Support\Facades\Queue::assertPushed(TranslateDocumentJob::class, 1); // PRD saja
    }

    public function test_show_by_key_returns_variant_content(): void
    {
        $project = $this->docProject();
        $doc = $project->documents()->first();
        (new TranslateDocumentJob($doc->id))->handle(app(\App\Services\SpecEngine::class));
        $user = User::firstOrFail(); // owner user dari docProject() — sudah terikat ke workspace via pivot

        $res = $this->actingAs($user)->getJson(route('projects.documents.show', [$project, 'PRD']).'?lang=en');

        $res->assertOk();
        $this->assertStringContainsString('[[EN]]', $res->json('content_md') ?? json_encode($res->json()));
    }

    public function test_show_by_key_variant_missing_returns_404(): void
    {
        // Belum ada TranslateDocumentJob dijalankan → varian 'en' belum ada
        $project = $this->docProject();
        $user = User::firstOrFail(); // owner user dari docProject() — sudah terikat ke workspace via pivot

        $this->actingAs($user)->getJson(route('projects.documents.show', [$project, 'PRD']).'?lang=en')
            ->assertNotFound();
    }

    public function test_show_by_key_lang_equal_primary_returns_normal_content(): void
    {
        $project = $this->docProject();
        $user = User::firstOrFail(); // owner user dari docProject() — sudah terikat ke workspace via pivot

        $res = $this->actingAs($user)->getJson(route('projects.documents.show', [$project, 'PRD']).'?lang=id');

        $res->assertOk();
        $this->assertSame('# PRD', $res->json('content_md'));
    }

    public function test_translate_blocked_when_readonly(): void
    {
        // BR-05: workspace read-only setelah grace period habis — translate juga panggilan LLM, wajib diblok
        $project = $this->docProject();
        $user = User::firstOrFail(); // owner user dari docProject() — sudah terikat ke workspace via pivot
        $project->workspace->subscription->update([
            'plan' => 'starter',
            'period_end' => now()->subDays(10)->toDateString(),
        ]);

        $this->actingAs($user)->post(route('projects.documents.translate', [$project, 'PRD']))->assertForbidden();
        $this->actingAs($user)->post(route('projects.translate-all', $project))->assertForbidden();
    }

    public function test_translate_blocked_when_credits_exhausted(): void
    {
        // BR-02: translate butuh kredit tersedia (meski tidak dikonsumsi) — sama seperti ImpactController::analyze
        $project = $this->docProject();
        $user = User::firstOrFail(); // owner user dari docProject() — sudah terikat ke workspace via pivot
        $workspace = $project->workspace;

        CreditLedger::create([
            'workspace_id' => $workspace->id,
            'delta' => -$workspace->creditBalance(),
            'kind' => 'consume',
            'ref_type' => 'project',
            'ref_id' => $project->id,
            'idempotency_key' => 'test-zero-'.$project->id,
        ]);
        $this->assertSame(0.0, $workspace->fresh()->creditBalance());

        $this->actingAs($user)->post(route('projects.documents.translate', [$project, 'PRD']))->assertStatus(402);
        $this->actingAs($user)->post(route('projects.translate-all', $project))->assertStatus(402);
    }

    public function test_translate_does_not_consume_credit(): void
    {
        // Terjemahan bagian dari nilai blueprint yang sudah dibayar — tidak memotong kredit
        Queue::fake();
        $project = $this->docProject();
        $user = User::firstOrFail(); // owner user dari docProject() — sudah terikat ke workspace via pivot
        $workspace = $project->workspace;
        $before = $workspace->creditBalance();

        $this->actingAs($user)->post(route('projects.documents.translate', [$project, 'PRD']))->assertRedirect();

        $this->assertSame($before, $workspace->fresh()->creditBalance());
    }

    public function test_show_version_and_history_return_primary_language_row_after_variant_exists(): void
    {
        // Gate review Task 7: setelah TranslateDocumentJob membuat baris varian dengan version_no
        // yang SAMA dengan versi primer, query existing wajib tetap ambil baris bahasa primer.
        $project = $this->docProject();
        $doc = $project->documents()->first();
        (new TranslateDocumentJob($doc->id))->handle(app(SpecEngine::class));
        $user = User::firstOrFail(); // owner user dari docProject() — sudah terikat ke workspace via pivot

        // DocumentController::showVersion
        $res = $this->actingAs($user)->getJson(route('documents.versions.show', [$doc, 1]));
        $res->assertOk();
        $this->assertSame('# PRD', $res->json('content_md'));
        $this->assertStringNotContainsString('[[EN]]', $res->json('content_md'));

        // Riwayat versi di payload ProjectController::show — varian tidak muncul sebagai entri riwayat
        $this->actingAs($user)->get(route('projects.show', $project))->assertInertia(fn ($page) => $page
            ->component('project')
            ->where('documents', function ($docs) {
                $prd = collect($docs)->firstWhere('doc_key', 'PRD');

                return count($prd['versions']) === 1 && $prd['versions'][0]['version_no'] === 1;
            }));
    }

    public function test_project_show_includes_variant_language_and_version_payload(): void
    {
        $project = $this->docProject();
        $doc = $project->documents()->first();
        $user = User::firstOrFail(); // owner user dari docProject() — sudah terikat ke workspace via pivot

        // Sebelum translate: variant_version_no null (belum tersedia)
        $this->actingAs($user)->get(route('projects.show', $project))->assertInertia(fn ($page) => $page
            ->component('project')
            ->where('documents', function ($docs) {
                $prd = collect($docs)->firstWhere('doc_key', 'PRD');

                return $prd['variant_language'] === 'en' && $prd['variant_version_no'] === null;
            }));

        (new TranslateDocumentJob($doc->id))->handle(app(SpecEngine::class));

        $this->actingAs($user)->get(route('projects.show', $project))->assertInertia(fn ($page) => $page
            ->component('project')
            ->where('documents', function ($docs) {
                $prd = collect($docs)->firstWhere('doc_key', 'PRD');

                return $prd['variant_language'] === 'en' && $prd['variant_version_no'] === 1;
            }));
    }

    public function test_store_version_and_restore_tag_project_primary_language_explicitly(): void
    {
        // Gate review Task 7: versi manual baru (store/restore) wajib language => primaryLanguage() eksplisit,
        // karena default kolom 'id' salah untuk proyek berbahasa EN.
        $project = $this->docProjectEn();
        $doc = $project->documents()->first();
        $user = User::firstOrFail(); // owner user dari docProject() — sudah terikat ke workspace via pivot

        $this->actingAs($user)->post(route('documents.versions.store', $doc), ['content_md' => '# Updated'])
            ->assertRedirect();
        $this->assertSame('en', $doc->versions()->where('version_no', 2)->first()->language);

        $this->actingAs($user)->post(route('documents.versions.restore', [$doc, 1]))->assertRedirect();
        $this->assertSame('en', $doc->versions()->where('version_no', 3)->first()->language);
    }
}
