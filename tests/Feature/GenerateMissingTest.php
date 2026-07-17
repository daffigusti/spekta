<?php

namespace Tests\Feature;

use App\Models\CreditLedger;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerateMissingTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_missing_docs_completes_full_pipeline(): void
    {
        config(['spekta.llm.driver' => 'stub']);
        $this->post('/register', [
            'name' => 'M', 'company' => 'AC', 'email' => 'o@a.co',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ]);
        $user = User::firstOrFail();
        $this->actingAs($user)->post('/projects');
        $project = Project::firstOrFail();

        // Wizard cepat: depth concise → set 1 (5 dokumen) — depth auto kini ikut template workspace
        $this->post("/projects/{$project->id}/wizard/input", [
            'name' => 'Kasir', 'kind' => 'idea', 'depth' => 'concise',
            'raw_text' => str_repeat('Aplikasi kasir toko retail dengan QRIS dan laporan penjualan. ', 3),
        ]);
        $u = $project->understanding()->first();
        $this->post("/projects/{$project->id}/wizard/understanding", [
            'roles' => $u->roles, 'features' => $u->features, 'complexity' => 2, 'assumptions' => [],
        ]);
        $this->post("/projects/{$project->id}/wizard/interview/finish", ['skip_all' => true]);
        $this->post("/projects/{$project->id}/wizard/structure/confirm", ['scope_mode' => 'full']);
        $this->post("/projects/{$project->id}/wizard/stack/confirm");
        $this->post("/projects/{$project->id}/generate")->assertSessionHasNoErrors();
        $this->assertSame(count(config('spekta.doc_sets.1')), $project->documents()->count());

        // Generate lanjutan subset terpilih (modal checklist)
        $before = $project->workspace->creditBalance();
        $this->post("/projects/{$project->id}/generate-missing", ['doc_keys' => ['ARCHITECTURE']])->assertSessionHasNoErrors();
        $this->assertSame(count(config('spekta.doc_sets.1')) + 1, $project->documents()->count());
        $this->assertNotNull($project->documents()->where('doc_key', 'ARCHITECTURE')->first());

        // Tanpa doc_keys → semua sisa (top-up dulu, free plan cuma 2 kredit)
        CreditLedger::create([
            'workspace_id' => $project->workspace_id, 'delta' => 5, 'kind' => 'plan_grant',
            'expires_at' => now()->endOfMonth(), 'idempotency_key' => 'test-grant',
        ]);
        $this->post("/projects/{$project->id}/generate-missing")->assertSessionHasNoErrors();
        $this->assertSame(count(config('spekta.doc_pipeline')), $project->documents()->count());
        $this->assertSame($before - 2 + 5, $project->workspace->fresh()->creditBalance());

        // Tidak ada sisa → ditolak tanpa potong kredit
        $this->post("/projects/{$project->id}/generate-missing")->assertSessionHasErrors('credits');
        $this->assertSame($before - 2 + 5, $project->workspace->fresh()->creditBalance());
    }
}
