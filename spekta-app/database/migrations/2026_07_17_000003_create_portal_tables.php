<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // FR-17: share-link token unik + OTP email, berlaku 30 hari, dapat dicabut (BR-28)
        Schema::create('share_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->string('approver_email'); // BR-27: satu approver utama
            $table->json('contact_emails')->nullable(); // BR-40: maks 5 kontak
            $table->json('doc_keys'); // hanya dokumen terpilih yang tampil
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        // FR-18: komentar per section, thread balasan, open/resolved
        Schema::create('comments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('document_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('share_link_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('parent_id')->nullable();
            $table->string('author_name');
            $table->string('author_email')->nullable();
            $table->string('author_type')->default('client'); // client|team
            $table->string('section_anchor')->nullable();
            $table->text('body');
            $table->string('status')->default('open'); // open|resolved
            $table->timestamps();
        });

        // FR-19: approval per dokumen oleh approver utama
        Schema::create('document_approvals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('share_link_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('document_id')->constrained()->cascadeOnDelete();
            $table->string('approved_by');
            $table->timestamp('approved_at');
            $table->unique(['share_link_id', 'document_id']);
        });

        // BR-24: baseline immutable — versi dokumen + RAB + timeline + asumsi + hash
        Schema::create('baselines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->json('snapshot');
            $table->string('hash', 64);
            $table->string('approver_email');
            $table->timestamp('approved_at');
            $table->timestamps();
            $table->unique(['project_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('baselines');
        Schema::dropIfExists('document_approvals');
        Schema::dropIfExists('comments');
        Schema::dropIfExists('share_links');
    }
};
