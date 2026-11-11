<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widens the encrypted ticket and comment text. A 10,000-character description of 4-byte characters
 * exceeds MySQL TEXT once encrypted and base64-encoded. Widening only; no data changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('helpdesk_tickets', fn (Blueprint $table) => $table->mediumText('description')->change());
        Schema::table('helpdesk_comments', fn (Blueprint $table) => $table->mediumText('body')->change());
    }

    /** Intentionally irreversible: narrowing back to TEXT would fail on, or truncate, longer stored ciphertext. */
    public function down(): void {}
};
