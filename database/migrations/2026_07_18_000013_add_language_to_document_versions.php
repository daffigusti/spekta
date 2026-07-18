<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_versions', function (Blueprint $table) {
            $table->string('language', 5)->default('id');
        });
        // Backfill: proyek berbahasa EN → versi existing dianggap EN
        DB::statement("update document_versions set language = 'en' where document_id in (
            select d.id from documents d join projects p on p.id = d.project_id where p.language = 'en')");
        Schema::table('document_versions', function (Blueprint $table) {
            $table->dropUnique(['document_id', 'version_no']);
            $table->unique(['document_id', 'version_no', 'language']);
        });
    }

    public function down(): void
    {
        Schema::table('document_versions', function (Blueprint $table) {
            $table->dropUnique(['document_id', 'version_no', 'language']);
            $table->unique(['document_id', 'version_no']);
            $table->dropColumn('language');
        });
    }
};
