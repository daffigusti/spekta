<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // FR-20: CR bernomor per proyek — deskripsi, dokumen terdampak, delta MD/biaya, status
        Schema::create('change_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number'); // CR-001, CR-002, …
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('source')->default('team'); // team|client
            $table->string('requested_by');
            $table->json('affected_doc_keys')->nullable();
            $table->decimal('delta_md', 8, 2)->nullable();   // diisi tim saat impact review
            $table->decimal('delta_cost', 16, 2)->nullable();
            $table->string('status')->default('proposed'); // proposed|approved|rejected
            $table->string('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignUuid('baseline_id')->nullable(); // baseline baru hasil approve (BR-26)
            $table->timestamps();
            $table->unique(['project_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('change_requests');
    }
};
