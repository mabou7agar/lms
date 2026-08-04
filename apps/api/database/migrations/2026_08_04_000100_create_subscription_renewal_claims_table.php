<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Renewal-claim ledger: one row per (subscription, billing-period start) that a renewal worker has
 * taken ownership of. The UNIQUE (subscription_id, period_start) index is the DB-enforced mutex that
 * lets exactly one concurrent scheduler pass charge a given period — closing the cross-gateway
 * double-charge window for adapters that ignore the charge idempotency key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_renewal_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->timestamp('period_start');
            $table->timestamp('created_at')->nullable()->useCurrent();

            $table->unique(['subscription_id', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_renewal_claims');
    }
};
