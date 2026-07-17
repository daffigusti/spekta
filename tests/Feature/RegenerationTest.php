<?php

namespace Tests\Feature;

use App\Models\CreditLedger;
use App\Models\Project;
use App\Models\User;
use App\Services\GenerationPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegenerationTest extends TestCase
{
    use RefreshDatabase;

    // Project::factory()/User::factory(['workspace_id']) belum ada — tiru pola projectWithDocs()
    // di ImpactAnalysisTest (register + POST /projects) supaya workspace terpasang benar.
    private function projectWithDocs(): Project
    {
        config(['spekta.llm.driver' => 'stub']);
        $this->post('/register', [
            'name' => 'M', 'company' => 'AC', 'email' => 'o@a.co',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ]);
        $user = User::firstOrFail();
        $this->actingAs($user)->post('/projects');
        $project = Project::firstOrFail();

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

        // queue sync di testing (phpunit.xml): job sudah jalan saat dispatchChain. Verifikasi hasil.
        $doc = $project->documents()->where('doc_key', 'REQUIREMENTS')->first();
        $this->assertSame(2, $doc->fresh()->currentVersion->version_no);
        $this->assertSame('ai', $doc->fresh()->currentVersion->source);
        $this->assertStringContainsString('regen', $doc->fresh()->currentVersion->content_md);
        $this->assertSame('done', $run->fresh()->status);
    }

    public function test_regenerate_endpoint_starts_run(): void
    {
        // Endpoint POST /projects/{project}/regenerate memulai regen run.
        $project = $this->projectWithDocs();
        $user = User::firstOrFail(); // User dari projectWithDocs()
        $workspace = $project->workspace;
        $balanceBefore = $workspace->creditBalance();

        $this->actingAs($user)->post(route('projects.regenerate', $project), [
            'change_text' => 'Tambah FR notifikasi',
            'doc_keys' => ['REQUIREMENTS'],
        ])->assertRedirect();

        $this->assertSame('regen', $project->generationRuns()->latest()->first()->trigger);

        // BR-02: regenerate sukses mengkonsumsi 1 kredit (setelah pipeline start, bukan sebelum).
        $ledger = $workspace->creditLedger()
            ->where('kind', 'consume')
            ->where('idempotency_key', 'like', 'consume-regen-%')
            ->first();
        $this->assertNotNull($ledger);
        $this->assertSame(-1.0, $ledger->delta);
        $this->assertSame($balanceBefore - 1.0, $workspace->fresh()->creditBalance());
    }

    public function test_regenerate_blocked_when_credits_exhausted(): void
    {
        // BR-02: kredit habis → redirect dengan error 'credits', run TIDAK terbentuk, tidak ada konsumsi tambahan.
        $project = $this->projectWithDocs();
        $user = User::firstOrFail();
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

        $runsBefore = $project->generationRuns()->count();

        $this->actingAs($user)->post(route('projects.regenerate', $project), [
            'change_text' => 'Tambah FR notifikasi',
            'doc_keys' => ['REQUIREMENTS'],
        ])->assertSessionHasErrors('credits');

        $this->assertSame($runsBefore, $project->generationRuns()->count());
        $this->assertSame(0.0, $workspace->fresh()->creditBalance());
    }

    public function test_regenerate_blocked_on_baselined_doc_without_cr(): void
    {
        // BR-25: dokumen ter-baseline hanya boleh berubah lewat CR. Endpoint deny jika status=approved.
        $project = $this->projectWithDocs();
        $project->update(['status' => 'approved']); // BR-25 aktif
        $user = User::firstOrFail();

        $this->actingAs($user)->post(route('projects.regenerate', $project), [
            'change_text' => 'x',
            'doc_keys' => ['REQUIREMENTS'],
        ])->assertForbidden();
    }
}
