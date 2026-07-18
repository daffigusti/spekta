<?php

namespace Tests\Feature;

use App\Jobs\TranslateDocumentJob;
use App\Models\Project;
use App\Models\User;
use App\Services\SpecEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TranslateDocumentTest extends TestCase
{
    use RefreshDatabase;

    // Project::factory() belum ada (lihat ImpactAnalysisTest) — tiru pola register + POST /projects,
    // lalu set language='id' manual karena tidak terisi lewat form store().
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
        $project->update(['language' => 'id']);

        $doc = $project->documents()->create(['doc_key' => 'PRD', 'title' => 'PRD.md']);
        $v = $doc->versions()->create(['version_no' => 1, 'content_md' => '# PRD', 'source' => 'ai']);
        $doc->update(['current_version_id' => $v->id]);

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
}
