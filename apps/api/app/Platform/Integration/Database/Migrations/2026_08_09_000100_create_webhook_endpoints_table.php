<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer-registered OUTBOUND webhook endpoints. One row per destination a tenant subscribes to a
 * set of platform events. Distinct from the INBOUND provider receivers (payment_webhook_events /
 * media_webhook_events).
 *
 * Boundary rules (mirroring the rest of the platform):
 *  - organization_id is the NULLABLE tenant column (opaque unsigned bigint, indexed, NO foreign key)
 *    resolved server-side from TenantContext. NULL = a platform-level (global) endpoint.
 *  - created_by is a cross-context scalar Identity user id — indexed, never foreign-keyed.
 *  - `secret` backs outbound HMAC signing and is never serialized (hidden on the model).
 *  - event_types (jsonb) is the set of subscribed webhook event names (e.g. "course.completed").
 *  - consecutive_failures + disabled_at drive auto-disable of a persistently failing endpoint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table): void {
            $table->id();
            $table->publicId();

            // Nullable tenant owner (opaque id, indexed, no FK) — stamped from the resolved tenant.
            $table->unsignedBigInteger('organization_id')->nullable();

            $table->string('name');
            $table->text('description')->nullable();

            $table->string('url', 2048);
            // HMAC signing secret — server-side only, never serialized to a client after creation/rotation.
            $table->string('secret', 128);

            // Subscribed webhook event names.
            $table->jsonb('event_types');

            $table->boolean('active')->default(true);
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('disabled_at')->nullable();

            // Cross-context scalar Identity user id — indexed, never foreign-keyed (DDD boundary).
            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();

            $table->index('organization_id');
            $table->index('active');
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_endpoints');
    }
};
