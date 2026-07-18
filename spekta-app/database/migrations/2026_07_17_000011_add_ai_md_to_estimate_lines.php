<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimate_lines', function (Blueprint $table) {
            // Estimasi AI asli per baris — pembanding warning override <50% (FR-14).
            // Nullable: baris lama tetap valid, warning di-skip sampai recompute berikutnya.
            $table->decimal('ai_md', 8, 2)->nullable()->after('md');
        });
    }

    public function down(): void
    {
        Schema::table('estimate_lines', function (Blueprint $table) {
            $table->dropColumn('ai_md');
        });
    }
};
