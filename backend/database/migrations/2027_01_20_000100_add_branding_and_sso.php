<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** White-label branding on organizations and one OIDC single sign-on connection per organization. Additive only. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('brand_name', 80)->nullable()->after('name');
            $table->char('brand_color', 7)->nullable()->after('brand_name');
            $table->string('logo_path')->nullable()->after('brand_color');
            $table->string('logo_mime', 40)->nullable()->after('logo_path');
            $table->timestamp('logo_updated_at')->nullable()->after('logo_mime');
        });

        Schema::create('sso_connections', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('issuer');
            $table->string('client_id');
            $table->text('client_secret');
            $table->json('allowed_domains');
            $table->boolean('enabled')->default(false);
            $table->boolean('enforced')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_connections');
        Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn(['brand_name', 'brand_color', 'logo_path', 'logo_mime', 'logo_updated_at']));
    }
};
