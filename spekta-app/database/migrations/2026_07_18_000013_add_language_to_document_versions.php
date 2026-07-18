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
        // Backfill: proyek berbahasa EN → versi existing dianggap EN.
        // BUGFIX FR-12: projects.language TIDAK PERNAH ditulis aplikasi (selalu default 'id') —
        // bahasa nyata proyek ada di blueprint['language'] (JSON). Loop PHP dipakai (bukan JSON
        // path SQL semacam json_extract) supaya portable tanpa asumsi driver DB (sqlite/mysql).
        DB::table('projects')->whereNotNull('blueprint')->select('id', 'blueprint')->orderBy('id')
            ->get()
            ->each(function ($project) {
                $blueprint = json_decode((string) $project->blueprint, true);
                if (($blueprint['language'] ?? null) !== 'en') {
                    return;
                }
                $docIds = DB::table('documents')->where('project_id', $project->id)->pluck('id');
                if ($docIds->isNotEmpty()) {
                    DB::table('document_versions')->whereIn('document_id', $docIds)->update(['language' => 'en']);
                }
            });
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
