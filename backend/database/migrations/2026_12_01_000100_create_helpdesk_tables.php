<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR helpdesk and knowledge base. Additive only. Ticket descriptions and comment bodies are encrypted at rest;
 * subjects stay searchable, so the form asks people not to put personal details in the subject.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tenantFk = function (Blueprint $table, string $column, string $on, bool $cascade = false) {
            $name = $table->getTable().'_'.$column.'_tenant_fk';
            $name = strlen($name) > 64 ? substr($name, 0, 55).'_'.substr(md5($name), 0, 8) : $name;
            $fk = $table->foreign([$column, 'tenant_id'], $name)->references(['id', 'tenant_id'])->on($on);
            $cascade ? $fk->cascadeOnDelete() : $fk->restrictOnDelete();
        };
        $base = function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
        };

        Schema::create('helpdesk_settings', function (Blueprint $table) use ($base) {
            $base($table);
            $table->json('due_hours');
            $table->unsignedInteger('next_number')->default(1);
            $table->timestamps();
            $table->unique('tenant_id');
        });

        Schema::create('helpdesk_categories', function (Blueprint $table) use ($base) {
            $base($table);
            $table->string('name', 80);
            $table->string('description', 500)->nullable();
            $table->boolean('restricted')->default(false);
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
            $table->unique(['id', 'tenant_id']);
        });

        Schema::create('helpdesk_category_agents', function (Blueprint $table) use ($tenantFk) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('helpdesk_category_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unique(['helpdesk_category_id', 'user_id']);
            $tenantFk($table, 'helpdesk_category_id', 'helpdesk_categories', true);
        });

        Schema::create('helpdesk_tickets', function (Blueprint $table) use ($base, $tenantFk) {
            $base($table);
            $table->unsignedInteger('number');
            $table->unsignedBigInteger('helpdesk_category_id');
            $table->foreignId('requester_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject', 200);
            $table->text('description');
            $table->string('priority', 10)->default('normal');
            $table->string('status', 20)->default('open');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
            $table->unique(['id', 'tenant_id']);
            $table->index(['tenant_id', 'status', 'due_at']);
            $table->index(['tenant_id', 'assignee_id']);
            $table->index(['tenant_id', 'requester_id']);
            $tenantFk($table, 'helpdesk_category_id', 'helpdesk_categories');
            $tenantFk($table, 'employee_id', 'employees');
        });

        Schema::create('helpdesk_comments', function (Blueprint $table) use ($base, $tenantFk) {
            $base($table);
            $table->unsignedBigInteger('helpdesk_ticket_id');
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->text('body');
            $table->boolean('internal')->default(false);
            $table->timestamps();
            $table->unique(['id', 'tenant_id']);
            $tenantFk($table, 'helpdesk_ticket_id', 'helpdesk_tickets', true);
        });

        Schema::create('helpdesk_attachments', function (Blueprint $table) use ($base, $tenantFk) {
            $base($table);
            $table->unsignedBigInteger('helpdesk_ticket_id');
            $table->unsignedBigInteger('helpdesk_comment_id')->nullable();
            // stored_files has no (id, tenant_id) key; tenant is checked in the application.
            $table->foreignId('stored_file_id')->constrained('stored_files')->restrictOnDelete();
            $table->boolean('internal')->default(false);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $tenantFk($table, 'helpdesk_ticket_id', 'helpdesk_tickets', true);
            $tenantFk($table, 'helpdesk_comment_id', 'helpdesk_comments', true);
        });

        Schema::create('kb_articles', function (Blueprint $table) use ($base, $tenantFk) {
            $base($table);
            $table->string('kind', 10)->default('article'); // faq | article
            $table->unsignedBigInteger('helpdesk_category_id')->nullable();
            $table->string('title', 200);
            $table->string('slug', 120);
            $table->text('summary')->nullable();
            $table->longText('body');
            $table->string('status', 20)->default('draft');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'status', 'kind']);
            $tenantFk($table, 'helpdesk_category_id', 'helpdesk_categories');
        });
    }

    public function down(): void
    {
        foreach (['kb_articles', 'helpdesk_attachments', 'helpdesk_comments', 'helpdesk_tickets', 'helpdesk_category_agents', 'helpdesk_categories', 'helpdesk_settings'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
