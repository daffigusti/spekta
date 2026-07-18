<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StructureRebuildTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Regresi: buildStructure gagal setelah node root tersimpan → struktur kosong permanen
     * (guard lama cek exists() dan melihat root). finish interview harus membangun ulang.
     */
    public function test_finish_interview_membangun_ulang_struktur_bila_hanya_root_tersisa(): void
    {
        config(['spekta.llm.driver' => 'stub']);

        $this->post('/register', [
            'name' => 'M', 'company' => 'AC', 'email' => 'r@a.co',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ]);
        $this->actingAs(User::firstOrFail())->post('/projects');
        $project = Project::firstOrFail();

        $this->post("/projects/{$project->id}/wizard/input", [
            'name' => 'Toko Online', 'kind' => 'idea',
            'raw_text' => 'Aplikasi toko online dengan katalog produk. Keranjang belanja. Pembayaran transfer.',
        ]);
        $project->refresh();
        $this->post("/projects/{$project->id}/wizard/understanding", [
            'roles' => [], 'features' => $project->understanding->features, 'complexity' => 2, 'assumptions' => [],
        ]);

        // Simulasi kegagalan lama: root sudah tertulis, fase/fitur tidak
        $project->structureNodes()->create(['kind' => 'root', 'title' => $project->name, 'sort' => 0]);

        $this->post("/projects/{$project->id}/wizard/interview/finish", ['skip_all' => true]);

        $this->assertTrue(
            $project->structureNodes()->where('kind', 'feature')->exists(),
            'Struktur harus dibangun ulang meski node root sisa kegagalan lama masih ada'
        );
        // Root tidak dobel
        $this->assertSame(1, $project->structureNodes()->where('kind', 'root')->count());
    }
}
