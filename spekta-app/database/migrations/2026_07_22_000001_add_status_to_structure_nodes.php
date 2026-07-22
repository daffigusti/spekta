<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Status kanban task (todo|doing|done) — hanya bermakna untuk kind='task'
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('structure_nodes', function (Blueprint $table) {
            $table->string('status', 10)->default('todo')->after('scope');
        });
    }

    public function down(): void
    {
        Schema::table('structure_nodes', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
