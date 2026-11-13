<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Global, platform-managed reference data. Not tenant-owned; changed only through reviewed seeders/migrations. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->char('code', 3)->unique();
            $table->string('name', 80);
            $table->string('symbol', 10);
            $table->unsignedTinyInteger('minor_units')->default(2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('languages', function (Blueprint $table) {
            $table->id();
            $table->string('code', 3)->unique();
            $table->string('name', 80);
            $table->string('native_name', 80);
            $table->string('direction', 3)->default('ltr');
            $table->string('status', 20)->default('planned');
            $table->timestamps();
        });

        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->char('code', 2)->unique();
            $table->char('iso3', 3)->unique();
            $table->string('name', 120);
            $table->char('default_currency_code', 3);
            $table->string('default_language_code', 3);
            $table->string('default_timezone', 64);
            $table->string('subdivision_label', 40);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('default_currency_code')->references('code')->on('currencies');
            $table->foreign('default_language_code')->references('code')->on('languages');
        });

        Schema::create('country_languages', function (Blueprint $table) {
            $table->id();
            $table->char('country_code', 2);
            $table->string('language_code', 3);
            $table->boolean('is_official')->default(false);

            $table->unique(['country_code', 'language_code']);
            $table->foreign('country_code')->references('code')->on('countries')->cascadeOnDelete();
            $table->foreign('language_code')->references('code')->on('languages');
        });

        Schema::create('country_timezones', function (Blueprint $table) {
            $table->id();
            $table->char('country_code', 2);
            $table->string('timezone', 64);

            $table->unique(['country_code', 'timezone']);
            $table->foreign('country_code')->references('code')->on('countries')->cascadeOnDelete();
        });

        Schema::create('subdivisions', function (Blueprint $table) {
            $table->id();
            $table->char('country_code', 2);
            $table->string('code', 6)->unique();
            $table->string('name', 120);
            $table->string('type', 30);

            $table->index(['country_code', 'name']);
            $table->foreign('country_code')->references('code')->on('countries')->cascadeOnDelete();
        });

        Schema::create('locales', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('language_code', 3);
            $table->char('country_code', 2);
            $table->string('date_format', 30);
            $table->string('time_format', 30);
            $table->string('decimal_separator', 4);
            $table->string('group_separator', 4);
            $table->unsignedTinyInteger('first_day_of_week');
            $table->timestamps();

            $table->foreign('language_code')->references('code')->on('languages');
            $table->foreign('country_code')->references('code')->on('countries')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['locales', 'subdivisions', 'country_timezones', 'country_languages', 'countries', 'languages', 'currencies'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
