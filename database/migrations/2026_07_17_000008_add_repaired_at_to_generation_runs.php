<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Auto-repair satu pass (FR-11): penanda run sudah pernah diperbaiki — cegah loop
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generation_runs', function (Blueprint $table) {
            $table->timestamp('repaired_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('generation_runs', function (Blueprint $table) {
            $table->dropColumn('repaired_at');
        });
    }
};
