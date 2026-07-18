<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\SpecEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FR-02: kontradiksi di INPUT user terdeteksi saat understanding — dibunuh di hulu
 * (banner step understanding + wajib jadi pertanyaan interview) sebelum menyebar
 * ke seluruh dokumen. Pelengkap cek kontradiksi antar-dokumen (FR-11f) di hilir.
 */
class InputContradictionTest extends TestCase
{
    use RefreshDatabase;

    private function freshProject(): Project
    {
        config(['spekta.llm.driver' => 'stub']);
        $this->post('/register', [
            'name' => 'M', 'company' => 'AC', 'email' => 'o@a.co',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ]);
        $this->actingAs(User::firstOrFail())->post('/projects');

        return Project::firstOrFail();
    }

    public function test_contradictions_from_engine_persisted_on_understanding(): void
    {
        $project = $this->freshProject();

        $this->partialMock(SpecEngine::class, function ($mock) {
            $mock->shouldReceive('understand')->once()->andReturn([
                'project_name' => 'Kasir QRIS',
                'roles' => [], 'features' => [['title' => 'Kasir', 'quote' => 'kasir']],
                'domain' => 'Retail', 'complexity' => 2, 'assumptions' => [],
                'contradictions' => [
                    'Input menyebut "maks 3 cabang" tapi juga "sinkronisasi unlimited cabang".',
                    123, // non-string wajib tersaring
                ],
            ]);
        });

        $this->post("/projects/{$project->id}/wizard/input", [
            'kind' => 'idea', 'raw_text' => 'Aplikasi kasir maksimal 3 cabang, tapi harus sinkronisasi unlimited cabang realtime.',
        ]);

        $this->assertSame(
            ['Input menyebut "maks 3 cabang" tapi juga "sinkronisasi unlimited cabang".'],
            $project->refresh()->understanding->contradictions,
        );
    }

    public function test_stub_driver_yields_empty_contradictions_not_null(): void
    {
        $project = $this->freshProject();

        $this->post("/projects/{$project->id}/wizard/input", [
            'kind' => 'idea', 'raw_text' => 'Aplikasi kasir sederhana dengan laporan penjualan harian.',
        ]);

        // [] bukan null — frontend baca (u.contradictions ?? []) tanpa cabang khusus
        $this->assertSame([], $project->refresh()->understanding->contradictions);
    }
}
