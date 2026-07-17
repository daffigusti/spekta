<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PresalesExportTest extends TestCase
{
    use RefreshDatabase;

    private function buildProject(): array
    {
        config(['spekta.llm.driver' => 'stub']);

        $this->post('/register', [
            'name' => 'Muammar K',
            'company' => 'AmanahCorp',
            'email' => 'owner@amanah.co.id',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);
        $user = User::firstOrFail();

        $this->actingAs($user)->post('/projects');
        $project = Project::firstOrFail();

        $this->post("/projects/{$project->id}/wizard/input", [
            'name' => 'Kasir Pintar',
            'client_name' => 'PT Maju Jaya',
            'kind' => 'idea',
            'raw_text' => 'Aplikasi kasir multi-cabang dengan pembayaran QRIS. Laporan penjualan harian. Manajemen stok dengan notifikasi. Role admin dan kasir.',
        ]);
        $project->refresh();
        $this->post("/projects/{$project->id}/wizard/understanding", [
            'roles' => [], 'features' => $project->understanding->features, 'complexity' => 2, 'assumptions' => [],
        ]);
        $this->post("/projects/{$project->id}/wizard/interview/finish", ['skip_all' => true]);
        $this->post("/projects/{$project->id}/wizard/structure/confirm", ['scope_mode' => 'full']);

        return [$user, $project->refresh()];
    }

    public function test_timeline_computed_and_presales_exports_download(): void
    {
        [, $project] = $this->buildProject();

        // FR-15: timeline tergenerate dari fase + buffer UAT di akhir
        $this->get("/projects/{$project->id}/estimate")->assertOk();
        $full = $project->estimates()->where('scope', 'full')->firstOrFail();
        $timeline = $full->timeline;
        $this->assertNotEmpty($timeline);
        $last = end($timeline);
        $this->assertSame('buffer', $last['kind']);
        $this->assertGreaterThan(0, $last['md']);
        $phaseEntries = array_filter($timeline, fn ($t) => $t['kind'] === 'phase');
        $this->assertNotEmpty($phaseEntries);
        // Fase berurutan: start_week fase ke-2 = akhir fase ke-1
        $ordered = array_values($phaseEntries);
        for ($i = 1; $i < count($ordered); $i++) {
            $this->assertEqualsWithDelta($ordered[$i - 1]['start_week'] + $ordered[$i - 1]['weeks'], $ordered[$i]['start_week'], 0.01);
        }

        // FR-16: proposal DOCX
        $res = $this->get("/projects/{$project->id}/export/proposal?scope=full");
        $res->assertOk()->assertDownload('kasir-pintar-proposal-full.docx');

        // BR-23: snapshot + audit trail
        $this->assertSame('snapshotted', $full->fresh()->status);
        $this->assertTrue(AuditLog::where('action', 'proposal.generated')->exists());

        // FR-16: RAB Excel dengan formula hidup
        $res = $this->get("/projects/{$project->id}/export/rab?scope=full");
        $res->assertOk()->assertDownload('kasir-pintar-rab-full.xlsx');

        // Scope tidak valid ditolak
        $this->get("/projects/{$project->id}/export/proposal?scope=bogus")->assertNotFound();
    }
}
