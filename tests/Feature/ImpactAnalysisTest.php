<?php

namespace Tests\Feature;

use App\Models\CreditLedger;
use App\Models\Project;
use App\Models\User;
use App\Services\SpecEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImpactAnalysisTest extends TestCase
{
    use RefreshDatabase;

    // Project::factory() belum ada (lihat GenerateMissingTest/AssistantChatTest) — tiru pola
    // register + POST /projects supaya workspace & doc_template terpasang dengan benar.
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

    public function test_impact_endpoint_returns_json(): void
    {
        $project = $this->projectWithDocs();
        $user = User::firstOrFail();

        $res = $this->actingAs($user)->postJson(route('projects.impact', $project), [
            'change_text' => 'Tambah fitur notifikasi email',
        ]);

        $res->assertOk()->assertJsonStructure(['summary', 'delta_md', 'affected' => [['doc_key', 'reason', 'manual_edit']]]);
    }

    public function test_analyze_blocked_when_credits_exhausted(): void
    {
        // BR-02: analisa (panggilan LLM sinkron) butuh kredit tersedia meski tidak dikonsumsi.
        $project = $this->projectWithDocs();
        $user = User::firstOrFail();
        $workspace = $project->workspace;

        // Habiskan saldo: entri konsumsi menutup grant free awal (lihat Workspace::creditBalance()).
        CreditLedger::create([
            'workspace_id' => $workspace->id,
            'delta' => -$workspace->creditBalance(),
            'kind' => 'consume',
            'ref_type' => 'project',
            'ref_id' => $project->id,
            'idempotency_key' => 'test-zero-'.$project->id,
        ]);
        $this->assertSame(0.0, $workspace->fresh()->creditBalance());

        $this->actingAs($user)->postJson(route('projects.impact', $project), [
            'change_text' => 'Tambah fitur notifikasi email',
        ])->assertStatus(402);
    }
}
