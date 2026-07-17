<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generation_runs', function (Blueprint $table) {
            $table->json('meta')->nullable(); // trigger=regen: ['instruction' => …]
        });
    }

    public function down(): void
    {
        Schema::table('generation_runs', function (Blueprint $table) {
            $table->dropColumn('meta');
        });
    }
};
