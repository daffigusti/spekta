<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
use ZipArchive;

class FileInputTest extends TestCase
{
    use RefreshDatabase;

    private function makeProject(): Project
    {
        config(['spekta.llm.driver' => 'stub']);
        $this->post('/register', [
            'name' => 'Muammar K', 'company' => 'AmanahCorp',
            'email' => 'owner@amanah.co.id',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ]);
        $this->actingAs(User::firstOrFail())->post('/projects');

        return Project::firstOrFail();
    }

    private const TRANSCRIPT = 'Klien butuh aplikasi kasir multi-cabang dengan pembayaran QRIS. Laporan penjualan harian per cabang. Manajemen stok dengan notifikasi stok menipis.';

    public function test_txt_upload_extracted_and_understood(): void
    {
        $project = $this->makeProject();
        $file = UploadedFile::fake()->createWithContent('meeting.txt', self::TRANSCRIPT);

        $this->post("/projects/{$project->id}/wizard/input", [
            'name' => 'Kasir Pintar', 'kind' => 'transcript', 'file' => $file,
        ])->assertSessionHasNoErrors();

        $project->refresh();
        $this->assertSame('understanding', $project->wizard_step);
        $input = $project->inputs()->first();
        $this->assertSame('meeting.txt', $input->file_path);
        $this->assertStringContainsString('QRIS', $input->raw_text);
    }

    public function test_docx_upload_extracted(): void
    {
        $project = $this->makeProject();

        // docx minimal: zip berisi word/document.xml
        $tmp = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive;
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml',
            '<?xml version="1.0"?><w:document xmlns:w="x"><w:body><w:p><w:r><w:t>'.self::TRANSCRIPT.'</w:t></w:r></w:p></w:body></w:document>');
        $zip->close();
        $file = new UploadedFile($tmp, 'notulen.docx', null, null, true);

        $this->post("/projects/{$project->id}/wizard/input", [
            'name' => 'Kasir Pintar', 'kind' => 'transcript', 'file' => $file,
        ])->assertSessionHasNoErrors();

        $this->assertStringContainsString('QRIS', $project->inputs()->first()->raw_text);
    }

    public function test_file_and_text_combined(): void
    {
        $project = $this->makeProject();
        $file = UploadedFile::fake()->createWithContent('meeting.txt', self::TRANSCRIPT);

        $this->post("/projects/{$project->id}/wizard/input", [
            'name' => 'Kasir Pintar', 'kind' => 'transcript',
            'raw_text' => 'Catatan tambahan: role admin pusat dan kasir cabang wajib ada.',
            'file' => $file,
        ])->assertSessionHasNoErrors();

        $raw = $project->inputs()->first()->raw_text;
        $this->assertStringContainsString('Catatan tambahan', $raw);
        $this->assertStringContainsString('QRIS', $raw);
    }

    public function test_invalid_extension_and_short_content_rejected(): void
    {
        $project = $this->makeProject();

        $this->post("/projects/{$project->id}/wizard/input", [
            'name' => 'X', 'kind' => 'idea',
            'file' => UploadedFile::fake()->create('virus.exe', 10),
        ])->assertSessionHasErrors('file');

        $this->post("/projects/{$project->id}/wizard/input", [
            'name' => 'X', 'kind' => 'idea',
            'file' => UploadedFile::fake()->createWithContent('pendek.txt', 'terlalu pendek'),
        ])->assertSessionHasErrors('raw_text');

        $this->post("/projects/{$project->id}/wizard/input", [
            'name' => 'X', 'kind' => 'idea',
        ])->assertSessionHasErrors();
    }
}
