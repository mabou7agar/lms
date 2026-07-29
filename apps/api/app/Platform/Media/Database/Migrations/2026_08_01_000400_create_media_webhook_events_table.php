<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P2/W04 - Idempotency ledger for provider webhooks. A verified event is recorded by its provider
 * event id (unique) BEFORE its side effects run; a replay of the same id finds processed_at set and
 * is a safe no-op. media_asset_id is nullable because an event may arrive for a provider_ref we no
 * longer hold (already deleted / never created) — still recorded so it is not reprocessed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 16);
            $table->string('provider_event_id')->unique();
            $table->foreignId('media_asset_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->string('type');
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();

            $table->index('media_asset_id');
            $table->index('provider');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_webhook_events');
    }
};
