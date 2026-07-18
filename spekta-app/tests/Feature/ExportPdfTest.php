<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_pdf_export_downloads_combined_blueprint(): void
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
        $this->post("/projects/{$project->id}/generate")->assertSessionHasNoErrors();
        $project->refresh();

        $this->assertGreaterThan(0, $project->documents()->count());

        // FR-21: PDF gabungan seluruh dokumen
        $res = $this->get("/projects/{$project->id}/export/pdf");
        $res->assertOk()->assertDownload('kasir-pintar-blueprint.pdf');
        $content = file_get_contents($res->getFile()->getPathname());
        $this->assertStringStartsWith('%PDF', $content);

        // Whitelist: kind tak dikenal tetap 404
        $this->get("/projects/{$project->id}/export/bogus")->assertNotFound();
    }
}
