<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\Exporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MvpFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_mvp_flow_from_register_to_export(): void
    {
        config(['spekta.llm.driver' => 'stub']);

        // 1. Register → workspace + free plan + 2 kredit (BR-01)
        $this->post('/register', [
            'name' => 'Muammar K',
            'company' => 'AmanahCorp',
            'email' => 'owner@amanah.co.id',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect(route('dashboard', absolute: false));

        $user = User::firstOrFail();
        $workspace = $user->currentWorkspace();
        $this->assertNotNull($workspace);
        $this->assertSame(2.0, $workspace->creditBalance());
        $this->assertSame('free', $workspace->subscription->plan);
        $this->assertTrue($workspace->rateCards()->where('is_default', true)->exists());

        // 2. Buat proyek
        $this->actingAs($user)->post('/projects')->assertRedirect();
        $project = Project::firstOrFail();

        // 3. Input ide → understanding (FR-01/FR-02)
        $this->post("/projects/{$project->id}/wizard/input", [
            'name' => 'Kasir Pintar',
            'client_name' => 'PT Maju Jaya',
            'kind' => 'idea',
            'raw_text' => 'Aplikasi kasir multi-cabang dengan pembayaran QRIS terintegrasi. Ada laporan penjualan harian per cabang. Manajemen stok dengan notifikasi stok menipis. Role admin pusat dan kasir cabang.',
        ])->assertSessionHasNoErrors();

        $project->refresh();
        $this->assertSame('understanding', $project->wizard_step);
        $this->assertNotNull($project->understanding);
        $this->assertNotEmpty($project->understanding->features);

        // 4. Konfirmasi understanding → interview (FR-03)
        $u = $project->understanding;
        $this->post("/projects/{$project->id}/wizard/understanding", [
            'roles' => $u->roles,
            'features' => $u->features,
            'domain' => $u->domain,
            'complexity' => 3,
            'assumptions' => $u->assumptions,
        ])->assertSessionHasNoErrors();

        $project->refresh();
        $this->assertSame('interview', $project->wizard_step);
        $this->assertGreaterThan(0, $project->interviewItems()->count());
        $this->assertLessThanOrEqual(10, $project->interviewItems()->count());

        // 5. Jawab 1, skip sisanya → asumsi tercatat (BR-13)
        $this->post("/projects/{$project->id}/wizard/interview/answer", [
            'seq' => 1, 'answer' => '1.000–10.000',
        ])->assertSessionHasNoErrors();
        $this->post("/projects/{$project->id}/wizard/interview/finish", ['skip_all' => true]);

        $project->refresh();
        $this->assertSame('structure', $project->wizard_step);
        $this->assertGreaterThan(0, $project->structureNodes()->where('kind', 'feature')->count());
        $this->assertNotEmpty($project->assumptions());

        // 6. Scope toggle + konfirmasi struktur → stack (FR-04/FR-05/FR-06)
        $feature = $project->structureNodes()->where('kind', 'feature')->first();
        $this->patch("/projects/{$project->id}/wizard/nodes/{$feature->id}", ['scope' => 'full']);
        $this->post("/projects/{$project->id}/wizard/structure/confirm", ['scope_mode' => 'full']);

        $project->refresh();
        $this->assertSame('stack', $project->wizard_step);
        $this->assertGreaterThanOrEqual(6, $project->stackChoices()->count());

        // Override stack layer → source=user (FR-06)
        $this->patch("/projects/{$project->id}/wizard/stack/frontend", ['choice' => 'Vue 3']);
        $this->assertSame('user', $project->stackChoices()->where('layer', 'frontend')->first()->source);

        // 7. Generate (FR-07, BR-02: konsumsi 1 kredit) — queue sync di test
        $this->post("/projects/{$project->id}/wizard/stack/confirm");
        $this->post("/projects/{$project->id}/generate")->assertSessionHasNoErrors();

        $project->refresh();
        $this->assertSame(1.0, $workspace->creditBalance());
        $this->assertSame('ready', $project->status);
        $this->assertSame('done', $project->generationRuns()->first()->status);

        // 11 dokumen untuk kompleksitas 3 (FR-07 scale-adaptive) — SECURITY/FEATURES/DESIGN mulai complexity 4
        $this->assertSame(11, $project->documents()->count());
        $prd = $project->documents()->where('doc_key', 'PRD')->first();
        $this->assertStringContainsString('Assumptions', $prd->currentVersion->content_md); // BR-13
        $this->assertSame('ai', $prd->currentVersion->generated_meta['generated_by']); // BR-53

        // 8. Spec Health terhitung (FR-11)
        $this->assertNotNull($project->health_score);

        // 9. Edit manual → versi baru source=user (FR-08)
        $v1Content = $prd->currentVersion->content_md;
        $this->post("/documents/{$prd->id}/versions", ['content_md' => $v1Content."\n\n## Catatan\n\nEdit manual."]);
        $prd->refresh();
        $this->assertSame(2, $prd->currentVersion->version_no);
        $this->assertSame('user', $prd->currentVersion->source);

        // 9b. Pulihkan v1 → v3 non-destruktif dengan isi v1
        $this->post("/documents/{$prd->id}/versions/1/restore");
        $prd->refresh();
        $this->assertSame(3, $prd->currentVersion->version_no);
        $this->assertSame($v1Content, $prd->currentVersion->content_md);

        // 10. Estimasi MVP vs Full (FR-14, BR-20/21)
        $this->get("/projects/{$project->id}/estimate")->assertOk();
        $this->assertSame(2, $project->estimates()->count());
        $full = $project->estimates()->where('scope', 'full')->first();
        $this->assertGreaterThan(0, $full->total_md);
        $this->assertGreaterThan(0, $full->total_cost);

        // Override line estimasi
        $line = $full->lines()->first();
        $this->patch("/projects/{$project->id}/estimates/{$full->id}/lines/{$line->id}", [
            'md' => $line->md + 4, 'reason' => 'Integrasi lebih kompleks dari perkiraan',
        ]);
        $this->assertTrue($line->fresh()->overridden);

        // 11. Export ZIP + agent pack (FR-21)
        $this->get("/projects/{$project->id}/export/zip")->assertOk()->assertDownload();
        $this->get("/projects/{$project->id}/export/agent_pack")->assertOk()->assertDownload();

        // tasks.md ber-nomor FR + pointer AC & skenario uji — WBS siap dieksekusi AI agent
        $path = app(Exporter::class)->zip($project, 'agent_pack');
        $zip = new \ZipArchive;
        $zip->open($path);
        $tasksMd = $zip->getFromName('tasks.md');
        $zip->close();
        unlink($path);
        $this->assertStringContainsString('FR-01 —', $tasksMd);
        $this->assertStringContainsString('AC: REQUIREMENTS.md §FR-01', $tasksMd);
        $this->assertStringContainsString('Uji: TESTING.md §TS-FR-01', $tasksMd); // kompleksitas 3 → TESTING ada

        // 12. Kredit habis → generate ditolak (BR-02 enforcement)
        $this->post('/projects');
        $p2 = Project::latest()->where('id', '!=', $project->id)->first();
        $this->post("/projects/{$p2->id}/wizard/input", [
            'name' => 'Proyek 2', 'kind' => 'idea',
            'raw_text' => str_repeat('Fitur aplikasi manajemen inventori gudang dengan barcode. ', 5),
        ]);
        $p2->refresh();
        $this->post("/projects/{$p2->id}/wizard/understanding", [
            'roles' => [], 'features' => $p2->understanding->features, 'complexity' => 1, 'assumptions' => [],
        ]);
        $this->post("/projects/{$p2->id}/wizard/interview/finish", ['skip_all' => true]);
        $this->post("/projects/{$p2->id}/wizard/structure/confirm", ['scope_mode' => 'mvp']);
        $this->post("/projects/{$p2->id}/wizard/stack/confirm");
        $this->post("/projects/{$p2->id}/generate"); // pakai kredit terakhir → saldo 0

        $this->assertSame(0.0, $workspace->creditBalance());
        $this->post('/projects');
        $p3 = Project::latest('created_at')->whereNotIn('id', [$project->id, $p2->id])->first();
        $this->post("/projects/{$p3->id}/wizard/input", [
            'name' => 'Proyek 3', 'kind' => 'idea',
            'raw_text' => str_repeat('Aplikasi booking lapangan futsal online dengan pembayaran digital. ', 5),
        ]);
        $p3->refresh();
        $this->post("/projects/{$p3->id}/wizard/understanding", [
            'roles' => [], 'features' => $p3->understanding->features, 'complexity' => 1, 'assumptions' => [],
        ]);
        $this->post("/projects/{$p3->id}/wizard/interview/finish", ['skip_all' => true]);
        $this->post("/projects/{$p3->id}/wizard/structure/confirm", ['scope_mode' => 'mvp']);
        $this->post("/projects/{$p3->id}/wizard/stack/confirm");
        $this->post("/projects/{$p3->id}/generate")->assertSessionHasErrors('credits');
    }
}
