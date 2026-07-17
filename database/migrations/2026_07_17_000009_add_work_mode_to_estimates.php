<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Mode pengerjaan (konvensional / AI-assisted / vibe) + baseline MD konvensional untuk perbandingan
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimates', function (Blueprint $table) {
            $table->string('work_mode')->default('conservative');
            $table->decimal('baseline_md', 10, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('estimates', function (Blueprint $table) {
            $table->dropColumn(['work_mode', 'baseline_md']);
        });
    }
};
