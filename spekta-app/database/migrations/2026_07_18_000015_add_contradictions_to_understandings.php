<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Kontradiksi di INPUT user terdeteksi saat understanding (FR-02) — dibunuh di hulu
// sebelum menyebar ke seluruh dokumen; pelengkap cek kontradiksi antar-dokumen (FR-11f).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('understandings', function (Blueprint $table) {
            $table->json('contradictions')->nullable(); // [string]
        });
    }

    public function down(): void
    {
        Schema::table('understandings', function (Blueprint $table) {
            $table->dropColumn('contradictions');
        });
    }
};
