<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned legal entities, holiday calendars and work locations.
 * Composite foreign keys that include tenant_id make cross-tenant links impossible at the database level.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_entities', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('legal_name', 200);
            $table->char('country_code', 2);
            $table->string('subdivision_code', 6)->nullable();
            $table->char('currency_code', 3);
            $table->string('default_language_code', 3);
            $table->text('registration_number')->nullable();
            $table->text('tax_identifier')->nullable();
            $table->string('address_line1', 200)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['id', 'tenant_id']);
            $table->unique(['tenant_id', 'legal_name', 'country_code']);
            $table->index(['tenant_id', 'country_code']);
            $table->foreign('country_code')->references('code')->on('countries');
            $table->foreign('subdivision_code')->references('code')->on('subdivisions');
            $table->foreign('currency_code')->references('code')->on('currencies');
            $table->foreign('default_language_code')->references('code')->on('languages');
        });

        Schema::create('holiday_calendars', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->char('country_code', 2);
            $table->string('subdivision_code', 6)->nullable();
            $table->boolean('include_statutory')->default(true);
            $table->timestamps();

            $table->unique(['id', 'tenant_id']);
            $table->unique(['tenant_id', 'name']);
            $table->foreign('country_code')->references('code')->on('countries');
            $table->foreign('subdivision_code')->references('code')->on('subdivisions');
        });

        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('holiday_calendar_id');
            $table->date('date');
            $table->string('name', 160);
            $table->string('type', 20)->default('company');
            $table->boolean('is_paid')->default(true);
            $table->timestamps();

            $table->unique(['holiday_calendar_id', 'date', 'name']);
            $table->foreign(['holiday_calendar_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('holiday_calendars')->cascadeOnDelete();
        });

        Schema::create('work_locations', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('legal_entity_id');
            $table->unsignedBigInteger('holiday_calendar_id')->nullable();
            $table->string('name', 160);
            $table->string('code', 40)->nullable();
            $table->char('country_code', 2);
            $table->string('subdivision_code', 6);
            $table->string('city', 120)->nullable();
            $table->string('address_line1', 200)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('timezone', 64);
            $table->boolean('is_remote')->default(false);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['id', 'tenant_id']);
            $table->unique(['id', 'legal_entity_id']);
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'legal_entity_id']);
            $table->foreign(['legal_entity_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('legal_entities');
            $table->foreign(['holiday_calendar_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('holiday_calendars');
            $table->foreign('country_code')->references('code')->on('countries');
            $table->foreign('subdivision_code')->references('code')->on('subdivisions');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->unsignedBigInteger('legal_entity_id')->nullable()->after('manager_id');
            $table->unsignedBigInteger('work_location_id')->nullable()->after('legal_entity_id');
            $table->string('employment_type', 40)->nullable()->after('work_location_id');

            $table->index(['tenant_id', 'legal_entity_id']);
            $table->index(['tenant_id', 'work_location_id']);
            $table->foreign(['legal_entity_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('legal_entities');
            $table->foreign(['work_location_id', 'legal_entity_id'])->references(['id', 'legal_entity_id'])->on('work_locations');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 10)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('locale'));
        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['work_location_id', 'legal_entity_id']);
            $table->dropForeign(['legal_entity_id', 'tenant_id']);
            $table->dropIndex(['tenant_id', 'legal_entity_id']);
            $table->dropIndex(['tenant_id', 'work_location_id']);
            $table->dropColumn(['legal_entity_id', 'work_location_id', 'employment_type']);
        });
        Schema::dropIfExists('work_locations');
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('holiday_calendars');
        Schema::dropIfExists('legal_entities');
    }
};
