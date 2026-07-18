<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doc_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('kind'); // proposal|document|portal
            $table->string('file_url')->nullable();
            $table->json('config')->nullable();
            $table->timestamps();
            // ponytail: satu template per kind, multi-template (Team tier) belum
            $table->unique(['workspace_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doc_templates');
    }
};
