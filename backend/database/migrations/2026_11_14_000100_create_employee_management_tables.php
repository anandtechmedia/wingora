<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Additive: employee management (designations, personal details, history, contacts, HR records, policies). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('designations', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('designation_id')->nullable()->after('department_id')->constrained()->nullOnDelete();
            $table->string('preferred_name', 100)->nullable()->after('last_name');
            $table->char('nationality', 2)->nullable()->after('preferred_name');
            $table->text('personal_email')->nullable();
            $table->text('mobile_phone')->nullable();
            $table->text('home_address')->nullable();

            $table->index(['tenant_id', 'designation_id']);
            $table->index(['tenant_id', 'manager_id']);
            $table->index(['tenant_id', 'employment_type']);
            $table->index(['tenant_id', 'last_name', 'first_name']);
        });

        Schema::create('employment_history', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('effective_date');
            $table->string('change_type', 30);
            $table->json('changes');
            $table->string('note', 500)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'effective_date']);
        });

        Schema::create('emergency_contacts', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->text('name');
            $table->string('relationship', 40);
            $table->text('phone');
            $table->text('email')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id']);
        });

        Schema::create('employee_records', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('severity', 30)->nullable();
            $table->string('title', 160);
            $table->text('body');
            $table->date('occurred_on');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'employee_id', 'type']);
        });

        Schema::create('policies', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('title', 160);
            $table->string('version', 20);
            $table->text('summary')->nullable();
            $table->foreignId('file_id')->nullable()->constrained('stored_files')->nullOnDelete();
            $table->date('effective_date');
            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'title', 'version']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('policy_acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('policy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->timestamp('acknowledged_at');
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->unique(['policy_id', 'employee_id']);
            $table->index(['tenant_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_acknowledgements');
        Schema::dropIfExists('policies');
        Schema::dropIfExists('employee_records');
        Schema::dropIfExists('emergency_contacts');
        Schema::dropIfExists('employment_history');
        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['designation_id']);
            $table->dropIndex(['tenant_id', 'designation_id']);
            $table->dropIndex(['tenant_id', 'manager_id']);
            $table->dropIndex(['tenant_id', 'employment_type']);
            $table->dropIndex(['tenant_id', 'last_name', 'first_name']);
            $table->dropColumn(['designation_id', 'preferred_name', 'nationality', 'personal_email', 'mobile_phone', 'home_address']);
        });
        Schema::dropIfExists('designations');
    }
};
