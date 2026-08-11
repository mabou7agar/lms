<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One attempt-tracked OUTBOUND delivery of a single event to a single endpoint. The signed body is
 * derived from `payload` (jsonb); `signature` records the exact HMAC sent for auditing/replay.
 *
 * Idempotency: UNIQUE (webhook_endpoint_id, event_id) — the emission layer derives event_id
 * deterministically from the source domain event, so re-dispatching the same event never creates a
 * second delivery to the same endpoint.
 *
 * Tenancy: organization_id mirrors the owning endpoint's tenant (nullable, indexed, no FK) so a
 * delivery is directly tenant-scoped (defense in depth) as well as reachable through its endpoint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->publicId();

            $table->unsignedBigInteger('webhook_endpoint_id');
            // Mirrors the endpoint's tenant for direct scoping (nullable, no FK).
            $table->unsignedBigInteger('organization_id')->nullable();

            $table->string('event_type');
            // Deterministic per-endpoint idempotency key derived from the source event.
            $table->string('event_id');

            $table->jsonb('payload');

            $table->string('status', 16)->default('pending'); // pending | success | failed
            $table->unsignedInteger('attempts')->default(0);

            $table->unsignedSmallInteger('response_status')->nullable();
            $table->unsignedInteger('response_ms')->nullable();
            $table->text('error')->nullable();

            $table->string('signature')->nullable();

            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();

            $table->timestamps();

            $table->foreign('webhook_endpoint_id')
                ->references('id')->on('webhook_endpoints')
                ->cascadeOnDelete();

            $table->unique(['webhook_endpoint_id', 'event_id']);
            $table->index(['webhook_endpoint_id', 'status']);
            $table->index('event_id');
            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
