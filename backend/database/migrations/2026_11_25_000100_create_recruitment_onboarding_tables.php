<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recruitment and onboarding. Additive only. Candidate personal data is limited to what an application needs;
 * no demographic or other sensitive attributes are collected. Hiring decisions are recorded only from human actions.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tenantFk = function (Blueprint $table, string $column, string $on, bool $cascade = false) {
            $name = $table->getTable().'_'.$column.'_tenant_fk';
            // MySQL caps identifiers at 64 characters.
            $name = strlen($name) > 64 ? substr($name, 0, 55).'_'.substr(md5($name), 0, 8) : $name;
            $fk = $table->foreign([$column, 'tenant_id'], $name)->references(['id', 'tenant_id'])->on($on);
            $cascade ? $fk->cascadeOnDelete() : $fk->restrictOnDelete();
        };
        $base = function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
        };

        Schema::create('recruitment_settings', function (Blueprint $table) use ($base) {
            $base($table);
            $table->boolean('careers_enabled')->default(false);
            $table->string('careers_slug', 63)->nullable()->unique();
            $table->string('careers_headline', 160)->nullable();
            $table->text('careers_intro')->nullable();
            $table->unsignedSmallInteger('retention_days')->default(365);
            $table->string('consent_version', 40)->default('v1');
            $table->text('consent_text');
            $table->boolean('ai_assist_enabled')->default(false);
            $table->timestamps();
            $table->unique('tenant_id');
        });

        Schema::create('pipeline_stages', function (Blueprint $table) use ($base) {
            $base($table);
            $table->string('key', 40);
            $table->string('name', 80);
            $table->string('kind', 20)->default('active'); // active | hired | rejected
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'key']);
            $table->unique(['id', 'tenant_id']);
        });

        Schema::create('job_openings', function (Blueprint $table) use ($base, $tenantFk) {
            $base($table);
            $table->string('title', 160);
            $table->string('slug', 120);
            $table->unsignedBigInteger('legal_entity_id');
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('work_location_id')->nullable();
            $table->unsignedBigInteger('hiring_manager_id')->nullable();
            $table->string('employment_type', 40)->nullable();
            $table->string('workplace', 20)->default('onsite');
            $table->unsignedSmallInteger('openings')->default(1);
            $table->text('summary')->nullable();
            $table->longText('description');
            $table->text('requirements')->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->date('closes_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'slug']);
            $table->unique(['id', 'tenant_id']);
            $table->index(['tenant_id', 'status']);
            $tenantFk($table, 'legal_entity_id', 'legal_entities');
            $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
            $tenantFk($table, 'work_location_id', 'work_locations');
            $tenantFk($table, 'hiring_manager_id', 'employees');
        });

        Schema::create('candidates', function (Blueprint $table) use ($base) {
            $base($table);
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->text('email');
            $table->string('email_hash', 64);
            $table->text('phone')->nullable();
            $table->string('location', 120)->nullable();
            $table->string('linkedin_url', 255)->nullable();
            $table->string('source', 30)->default('careers');
            $table->boolean('on_hold')->default(false);
            $table->timestamp('anonymized_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'email_hash']);
            $table->unique(['id', 'tenant_id']);
        });

        Schema::create('job_applications', function (Blueprint $table) use ($base, $tenantFk) {
            $base($table);
            $table->unsignedBigInteger('job_opening_id');
            $table->unsignedBigInteger('candidate_id');
            $table->unsignedBigInteger('pipeline_stage_id');
            $table->string('status', 20)->default('active'); // active | hired | rejected | withdrawn
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('position')->default(0);
            $table->text('cover_letter')->nullable();
            $table->string('consent_version', 40)->nullable();
            $table->timestamp('consented_at')->nullable();
            $table->string('consent_ip_hash', 64)->nullable();
            $table->text('rejection_reason')->nullable();
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->timestamp('retain_until')->nullable();
            $table->timestamps();
            $table->unique(['job_opening_id', 'candidate_id']);
            $table->unique(['id', 'tenant_id']);
            $table->index(['tenant_id', 'pipeline_stage_id', 'position']);
            $tenantFk($table, 'job_opening_id', 'job_openings', true);
            $tenantFk($table, 'candidate_id', 'candidates', true);
            $tenantFk($table, 'pipeline_stage_id', 'pipeline_stages');
            $tenantFk($table, 'employee_id', 'employees');
        });

        Schema::create('candidate_documents', function (Blueprint $table) use ($base, $tenantFk) {
            $base($table);
            $table->unsignedBigInteger('candidate_id');
            $table->unsignedBigInteger('job_application_id')->nullable();
            $table->unsignedBigInteger('stored_file_id');
            $table->string('kind', 30)->default('resume'); // resume | cover_letter | offer_letter | other
            $table->string('suggested_kind', 30)->nullable();
            $table->timestamps();
            $table->unique(['id', 'tenant_id']);
            $tenantFk($table, 'candidate_id', 'candidates', true);
            $tenantFk($table, 'job_application_id', 'job_applications', true);
            $table->foreign('stored_file_id')->references('id')->on('stored_files')->restrictOnDelete();
        });

        Schema::create('interviews', function (Blueprint $table) use ($base, $tenantFk) {
            $base($table);
            $table->unsignedBigInteger('job_application_id');
            $table->string('title', 120);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('timezone', 64);
            $table->string('mode', 20)->default('video');
            $table->string('location', 255)->nullable();
            $table->string('status', 20)->default('scheduled'); // scheduled | completed | cancelled
            $table->foreignId('scheduled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['id', 'tenant_id']);
            $tenantFk($table, 'job_application_id', 'job_applications', true);
        });

        Schema::create('interview_panelists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('interview_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unique(['interview_id', 'user_id']);
        });

        Schema::create('interview_feedback', function (Blueprint $table) use ($base, $tenantFk) {
            $base($table);
            $table->unsignedBigInteger('interview_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->string('recommendation', 20); // strong_yes | yes | no | strong_no
            $table->text('strengths')->nullable();
            $table->text('concerns')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['interview_id', 'user_id']);
            $tenantFk($table, 'interview_id', 'interviews', true);
        });

        Schema::create('job_offers', function (Blueprint $table) use ($base, $tenantFk) {
            $base($table);
            $table->unsignedBigInteger('job_application_id');
            $table->string('status', 20)->default('draft'); // draft | approved | sent | accepted | declined | withdrawn
            $table->string('job_title', 160);
            $table->string('currency', 3);
            $table->unsignedBigInteger('base_salary_minor');
            $table->string('pay_frequency', 20)->default('annual');
            $table->date('start_date')->nullable();
            $table->date('expires_on')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('letter_file_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->unique(['id', 'tenant_id']);
            $tenantFk($table, 'job_application_id', 'job_applications', true);
            $table->foreign('letter_file_id')->references('id')->on('stored_files')->nullOnDelete();
        });

        Schema::create('ai_drafts', function (Blueprint $table) use ($base) {
            $base($table);
            $table->string('feature', 40);
            $table->string('subject_type', 60);
            $table->unsignedBigInteger('subject_id');
            $table->string('model', 80);
            $table->longText('content');
            $table->string('status', 20)->default('pending_review'); // pending_review | accepted | discarded
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'subject_type', 'subject_id']);
        });

        Schema::create('onboarding_templates', function (Blueprint $table) use ($base, $tenantFk) {
            $base($table);
            $table->string('name', 120);
            $table->unsignedBigInteger('legal_entity_id')->nullable();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['id', 'tenant_id']);
            $tenantFk($table, 'legal_entity_id', 'legal_entities');
        });

        Schema::create('onboarding_template_tasks', function (Blueprint $table) use ($base, $tenantFk) {
            $base($table);
            $table->unsignedBigInteger('onboarding_template_id');
            $table->string('stage', 30); // pre_boarding | first_day | first_week | first_month
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->string('assignee', 20); // hr | manager | employee | user
            $table->foreignId('assignee_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->smallInteger('due_offset_days')->default(0);
            $table->string('document_key', 60)->nullable();
            $table->string('document_name', 160)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['id', 'tenant_id']);
            $tenantFk($table, 'onboarding_template_id', 'onboarding_templates', true);
        });

        Schema::create('onboarding_plans', function (Blueprint $table) use ($base, $tenantFk) {
            $base($table);
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('onboarding_template_id')->nullable();
            $table->unsignedBigInteger('job_application_id')->nullable();
            $table->date('start_date');
            $table->string('status', 20)->default('in_progress'); // in_progress | completed | cancelled
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['id', 'tenant_id']);
            $table->index(['tenant_id', 'status']);
            $tenantFk($table, 'employee_id', 'employees', true);
            $tenantFk($table, 'onboarding_template_id', 'onboarding_templates');
            $tenantFk($table, 'job_application_id', 'job_applications');
        });

        Schema::create('onboarding_tasks', function (Blueprint $table) use ($base, $tenantFk) {
            $base($table);
            $table->unsignedBigInteger('onboarding_plan_id');
            $table->string('stage', 30);
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->string('assignee', 20);
            $table->foreignId('assignee_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_on');
            $table->string('document_key', 60)->nullable();
            $table->string('document_name', 160)->nullable();
            $table->unsignedBigInteger('document_file_id')->nullable();
            $table->string('status', 20)->default('pending'); // pending | submitted | completed | returned | skipped
            $table->text('review_note')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->date('last_reminded_on')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['id', 'tenant_id']);
            $table->index(['tenant_id', 'status', 'due_on']);
            $tenantFk($table, 'onboarding_plan_id', 'onboarding_plans', true);
            $table->foreign('document_file_id')->references('id')->on('stored_files')->nullOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['onboarding_tasks', 'onboarding_plans', 'onboarding_template_tasks', 'onboarding_templates', 'ai_drafts', 'job_offers', 'interview_feedback',
            'interview_panelists', 'interviews', 'candidate_documents', 'job_applications', 'candidates', 'job_openings', 'pipeline_stages', 'recruitment_settings'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
