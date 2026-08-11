<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-category suppression (unsubscribe) list, keyed by tenant + email + category. A marketing send
 * to a suppressed (email, category) is skipped. Keyed on EMAIL so it covers both leads (no user
 * account) and users uniformly, and so a public unsubscribe link never needs to resolve an account.
 *
 * Transactional/critical categories are NEVER consulted against this list — only marketing is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_suppressions', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->string('email');
            $table->string('category', 24)->default('marketing');
            $table->string('source', 24)->default('unsubscribe_link');
            $table->string('reason')->nullable();
            $table->timestamp('suppressed_at');
            $table->timestamps();

            $table->unique(['organization_id', 'email', 'category'], 'marketing_suppressions_unique');
            $table->index(['email', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_suppressions');
    }
};
