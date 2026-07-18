<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rework Template Perusahaan: dari 3 kind tetap (proposal/document/portal) ke
 * template preset bernama — set dokumen, bahasa, tone, opsi proposal. Multi per
 * workspace, tepat satu default. Isi lama hanya default auto-generate → aman didrop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('doc_templates');

        Schema::create('doc_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->json('doc_kinds')->nullable(); // [doc_key] — subset config('spekta.doc_pipeline')
            $table->string('language', 5)->default('id'); // id|en
            $table->string('tone')->default('formal'); // formal|formal_rfc|casual
            $table->json('config')->nullable(); // opsi proposal, mis. {white_label: bool}
            $table->string('file_url')->nullable();
            $table->timestamps();
            $table->index('workspace_id');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->foreignUuid('doc_template_id')->nullable()->after('workspace_id')
                ->constrained('doc_templates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('doc_template_id');
        });

        Schema::dropIfExists('doc_templates');

        // Kembalikan bentuk kind-tetap (state setelah migration 000009)
        Schema::create('doc_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('kind'); // proposal|document|portal
            $table->string('file_url')->nullable();
            $table->json('config')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'kind']);
        });
    }
};
