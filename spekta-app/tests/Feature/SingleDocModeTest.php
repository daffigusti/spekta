<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SingleDocModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_depth_generates_only_one_prd_document(): void
    {
        config(['spekta.llm.driver' => 'stub']);
        $this->post('/register', [
            'name' => 'M', 'company' => 'AC', 'email' => 'o@a.co',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ]);
        $user = User::firstOrFail();
        $this->actingAs($user)->post('/projects');
        $project = Project::firstOrFail();

        $this->post("/projects/{$project->id}/wizard/input", [
            'name' => 'Kasir', 'kind' => 'idea', 'depth' => 'single',
            'raw_text' => str_repeat('Aplikasi kasir toko retail dengan QRIS dan laporan penjualan. ', 3),
        ])->assertSessionHasNoErrors();
        $u = $project->understanding()->first();
        $this->post("/projects/{$project->id}/wizard/understanding", [
            'roles' => $u->roles, 'features' => $u->features, 'complexity' => 2, 'assumptions' => [],
        ]);
        $this->post("/projects/{$project->id}/wizard/interview/finish", ['skip_all' => true]);
        $this->post("/projects/{$project->id}/wizard/structure/confirm", ['scope_mode' => 'full']);
        $this->post("/projects/{$project->id}/wizard/stack/confirm");
        $this->post("/projects/{$project->id}/generate")->assertSessionHasNoErrors();

        // Satu run, satu node PRD tanpa dependency, satu dokumen
        $run = $project->generationRuns()->firstOrFail();
        $this->assertSame(['PRD'], $run->nodes()->pluck('doc_key')->all());
        $this->assertSame([], $run->nodes()->first()->depends_on);
        $this->assertSame(1, $project->documents()->count());
        $this->assertSame('PRD', $project->documents()->first()->doc_key);

        // Health tetap terhitung tanpa crash (rule PRD: FR-xx + Assumptions terpenuhi oleh stub)
        $this->assertNotNull($project->fresh()->health_score);

        // Escape hatch: mode single tetap bisa nambah dokumen lain lewat generate lanjutan
        $this->post("/projects/{$project->id}/generate-missing", ['doc_keys' => ['ARCHITECTURE', 'DATABASE']])
            ->assertSessionHasNoErrors();
        $this->assertSame(3, $project->documents()->count());
        $this->assertNotNull($project->documents()->where('doc_key', 'DATABASE')->first());
    }
}
