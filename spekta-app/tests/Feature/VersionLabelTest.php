<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Label semantik versi: opsional saat edit manual, otomatis saat restore. */
class VersionLabelTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_label_and_restore_autolabel(): void
    {
        config(['spekta.llm.driver' => 'stub']);
        $this->post('/register', [
            'name' => 'M', 'company' => 'AC', 'email' => 'o@a.co',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ]);
        $this->actingAs(User::firstOrFail())->post('/projects');
        $project = Project::firstOrFail();

        $doc = $project->documents()->create(['doc_key' => 'PRD', 'title' => 'PRD.md']);
        $v1 = $doc->versions()->create(['version_no' => 1, 'content_md' => '# v1', 'source' => 'ai', 'label' => 'Draf awal AI']);
        $doc->update(['current_version_id' => $v1->id]);

        // edit manual dengan label opsional
        $this->post(route('documents.versions.store', $doc->id), ['content_md' => '# v2 revisi', 'label' => 'Internal review'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Internal review', $doc->versions()->where('version_no', 2)->firstOrFail()->label);

        // label terlalu panjang ditolak
        $this->post(route('documents.versions.store', $doc->id), ['content_md' => '# x', 'label' => str_repeat('a', 61)])
            ->assertSessionHasErrors('label');

        // restore → label otomatis
        $this->post(route('documents.versions.restore', [$doc->id, 1]))->assertSessionHasNoErrors();
        $this->assertSame('Restore dari v1', $doc->versions()->orderByDesc('version_no')->firstOrFail()->label);
    }
}
