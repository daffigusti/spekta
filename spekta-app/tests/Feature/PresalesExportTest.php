<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\User;
use App\Services\ProposalGenerator;
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

    private function docXml(string $path): string
    {
        $zip = new \ZipArchive;
        $zip->open($path);
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        unlink($path);

        return $xml;
    }

    /** FR-16: payment scheme/warranty dari config, override per-template menang, white_label atur footer. */
    public function test_proposal_mengikuti_konfigurasi_payment_warranty_dan_branding(): void
    {
        [, $project] = $this->buildProject();
        config(['spekta.proposal.warranty_days' => 45]);
        $project->workspace->update(['brand_colors' => ['primary' => '#FF5733']]);

        $this->get("/projects/{$project->id}/estimate")->assertOk();
        $estimate = $project->estimates()->where('scope', 'full')->firstOrFail();

        $xml = $this->docXml(app(ProposalGenerator::class)->generate($project->fresh(), $estimate));
        $this->assertStringContainsString('Down payment (mulai kerja)', $xml); // default config
        $this->assertStringContainsString('45 hari', $xml); // warranty_days configurable
        $this->assertStringContainsString('FF5733', $xml); // warna aksen workspace
        // Template default white_label=true → tanpa footer Spekta
        $this->assertStringNotContainsString('Dokumen dibuat dengan Spekta', $xml);

        // Override per-template menang atas config; non white-label memunculkan footer
        $project->docTemplate->update(['config' => [
            'white_label' => false,
            'payment_scheme' => [['label' => 'Sekali bayar', 'pct' => 100]],
            'warranty_days' => 90,
        ]]);
        $xml = $this->docXml(app(ProposalGenerator::class)->generate($project->fresh(), $estimate));
        $this->assertStringContainsString('Sekali bayar', $xml);
        $this->assertStringContainsString('90 hari', $xml);
        $this->assertStringNotContainsString('Down payment', $xml);
        $this->assertStringContainsString('Dokumen dibuat dengan Spekta', $xml);
    }

    /** Currency rate card (USD) mengalir ke proposal — tidak lagi hardcode Rp. */
    public function test_currency_usd_mengalir_ke_proposal(): void
    {
        [, $project] = $this->buildProject();
        $project->workspace->rateCards()->where('is_default', true)->firstOrFail()->update(['currency' => 'USD']);

        $this->get("/projects/{$project->id}/estimate")->assertOk();
        $estimate = $project->estimates()->where('scope', 'full')->firstOrFail();
        $this->assertSame('USD', $estimate->currency);

        $xml = $this->docXml(app(ProposalGenerator::class)->generate($project->fresh(), $estimate));
        $this->assertStringContainsString('$', $xml);
        $this->assertStringNotContainsString('Rp ', $xml);
    }
}
