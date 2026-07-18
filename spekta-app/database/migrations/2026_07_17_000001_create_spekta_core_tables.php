<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('logo_url')->nullable();
            $table->json('brand_colors')->nullable();
            $table->string('locale', 5)->default('id');
            $table->string('currency', 3)->default('IDR');
            $table->decimal('margin_default', 5, 2)->default(30);
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('workspace_members', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('member'); // owner|admin|member
            $table->boolean('hide_prices')->default(false);
            $table->timestamps();
            $table->unique(['workspace_id', 'user_id']);
        });

        Schema::create('rate_cards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('currency', 3)->default('IDR');
            $table->boolean('is_default')->default(false);
            $table->json('roles'); // [{role, daily_rate}]
            $table->decimal('margin_pct', 5, 2)->default(30);
            $table->timestamps();
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('client_name')->nullable();
            $table->string('status')->default('draft'); // draft|generating|ready|shared|approved|archived
            $table->unsignedTinyInteger('complexity')->nullable(); // 1-5
            $table->string('language', 5)->default('id');
            $table->string('scope_mode')->default('full'); // mvp|full
            $table->unsignedTinyInteger('health_score')->nullable();
            $table->string('wizard_step')->default('input'); // input|understanding|interview|structure|stack|generate|done
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('project_inputs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->string('kind'); // idea|transcript|rfp
            $table->string('file_path')->nullable();
            $table->text('raw_text')->nullable();
            $table->json('extracted')->nullable();
            $table->string('status')->default('ready'); // pending|ready|error
            $table->timestamps();
        });

        Schema::create('understandings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->json('roles')->nullable();      // [{name, note}]
            $table->json('features')->nullable();   // [{title, quote}]
            $table->string('domain')->nullable();
            $table->unsignedTinyInteger('complexity')->default(3);
            $table->json('assumptions')->nullable(); // [string]
            $table->boolean('confirmed')->default(false);
            $table->timestamps();
        });

        Schema::create('interview_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('seq');
            $table->text('question');
            $table->text('reason')->nullable();
            $table->json('options')->nullable();
            $table->text('answer_text')->nullable();
            $table->boolean('skipped')->default(false);
            $table->text('assumption_text')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'seq']);
        });

        Schema::create('structure_nodes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->uuid('parent_id')->nullable()->index();
            $table->string('kind'); // root|phase|feature|subfeature
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('phase_no')->nullable();
            $table->string('scope')->default('mvp'); // mvp|full|parked
            $table->decimal('est_md', 8, 2)->default(0);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('stack_choices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->string('layer'); // frontend|backend|database|auth|payment|deploy
            $table->string('choice');
            $table->text('justification')->nullable();
            $table->json('alternatives')->nullable(); // [{choice, reason_rejected}]
            $table->string('source')->default('ai'); // ai|preset|user
            $table->timestamps();
            $table->unique(['project_id', 'layer']);
        });

        Schema::create('generation_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->string('trigger')->default('full'); // full|selective
            $table->string('status')->default('queued'); // queued|running|paused|error|done
            $table->decimal('credit_cost', 6, 2)->default(1);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('generation_nodes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('run_id')->constrained('generation_runs')->cascadeOnDelete();
            $table->string('doc_key');
            $table->json('depends_on')->nullable(); // [doc_key]
            $table->string('status')->default('queued'); // queued|running|done|error
            $table->unsignedTinyInteger('attempt')->default(0);
            $table->text('error_text')->nullable();
            $table->timestamps();
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->string('doc_key'); // PRD|REQUIREMENTS|...
            $table->string('title');
            $table->uuid('current_version_id')->nullable();
            $table->string('status')->default('draft'); // draft|internal_review|shared|approved
            $table->boolean('share_visible')->default(true);
            $table->timestamps();
            $table->unique(['project_id', 'doc_key']);
        });

        Schema::create('document_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('version_no');
            $table->longText('content_md');
            $table->string('source')->default('ai'); // ai|user
            $table->json('generated_meta')->nullable(); // model, tokens, duration (BR-12)
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['document_id', 'version_no']);
        });

        Schema::create('health_findings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->string('rule_key');
            $table->string('severity'); // info|warning|critical
            $table->string('location')->nullable();
            $table->text('message');
            $table->text('suggestion')->nullable();
            $table->boolean('resolved')->default(false);
            $table->timestamps();
        });

        Schema::create('estimates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->string('scope'); // mvp|full
            $table->json('rate_card_snapshot')->nullable();
            $table->decimal('total_md', 10, 2)->default(0);
            $table->unsignedTinyInteger('range_pct')->default(15);
            $table->decimal('total_cost', 16, 2)->default(0);
            $table->string('currency', 3)->default('IDR');
            $table->json('team_composition')->nullable();
            $table->decimal('duration_weeks', 5, 1)->nullable();
            $table->string('status')->default('draft'); // draft|snapshotted
            $table->timestamps();
            $table->unique(['project_id', 'scope']);
        });

        Schema::create('estimate_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('estimate_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('structure_node_id')->constrained()->cascadeOnDelete();
            $table->json('role_breakdown')->nullable(); // [{role, md}]
            $table->decimal('md', 8, 2)->default(0);
            $table->decimal('cost', 16, 2)->default(0);
            $table->boolean('overridden')->default(false);
            $table->text('override_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('plan')->default('free'); // free|starter|pro|team
            $table->unsignedSmallInteger('seats')->default(1);
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('credit_ledger', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->decimal('delta', 8, 2);
            $table->string('kind'); // plan_grant|topup|consume|refund|expire
            $table->string('ref_type')->nullable();
            $table->string('ref_id')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->timestamps();
            $table->index(['workspace_id', 'created_at']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->string('entity_type')->nullable();
            $table->string('entity_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach ([
            'audit_logs', 'credit_ledger', 'subscriptions', 'estimate_lines', 'estimates',
            'health_findings', 'document_versions', 'documents', 'generation_nodes',
            'generation_runs', 'stack_choices', 'structure_nodes', 'interview_items',
            'understandings', 'project_inputs', 'projects', 'rate_cards',
            'workspace_members', 'workspaces',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
