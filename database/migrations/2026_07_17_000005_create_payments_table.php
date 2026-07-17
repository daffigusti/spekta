<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // FR-23: transaksi Midtrans (Snap) — langganan & top-up kredit
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('order_id')->unique(); // dikirim ke Midtrans
            $table->string('kind'); // subscription|topup
            $table->string('plan')->nullable(); // starter|pro|team (kind=subscription)
            $table->unsignedSmallInteger('seats')->default(1);
            $table->decimal('credits', 8, 2)->nullable(); // kind=topup
            $table->decimal('amount', 16, 2);
            $table->string('status')->default('pending'); // pending|paid|failed|expired
            $table->string('snap_token')->nullable();
            $table->string('redirect_url')->nullable();
            $table->json('raw_notification')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
