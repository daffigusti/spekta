<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Open questions: pertanyaan belum terjawab (interview skip / asumsi / kontradiksi input),
// bisa dijawab klien via portal — jawaban jadi bahan update dokumen.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('open_questions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->string('source', 20); // interview|assumption|contradiction
            $table->text('question');
            $table->string('question_hash', 64); // sha1(source|question) — dedup sync derived
            $table->string('status', 20)->default('open'); // open|answered
            $table->text('answer_text')->nullable();
            $table->string('answered_by')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'question_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('open_questions');
    }
};
