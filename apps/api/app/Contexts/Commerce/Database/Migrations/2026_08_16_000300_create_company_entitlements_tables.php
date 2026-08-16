<?php

use App\Contexts\Commerce\Enums\CompanyEntitlementStatus;
use App\Contexts\Commerce\Enums\SeatMode;
use App\Contexts\Commerce\Enums\SeatReassignmentPolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a company actually receives when it buys training: a pool of seats against one purchased
 * product, which its manager hands out to employees.
 *
 * This is deliberately NOT the existing `seat_pools` table. That one models platform seats driven by
 * an organization SUBSCRIPTION — one capacity counter per organization, with no product, no order,
 * no access window and no reassignment policy. A one-off purchase is a different thing: each order
 * line buys its own bundle of courses, on its own clock, under the policy that was in force the day
 * it was bought. Overloading the subscription pool would have meant bolting eight commerce columns
 * onto a CRM table and putting the verified subscription seat flow at risk.
 *
 * The policy columns are SNAPSHOTS taken at fulfilment. An admin editing a product later changes
 * what future buyers get, never what an existing customer already paid for.
 *
 * `organization_id` carries no foreign key, matching the buyer-ownership migration: it points at a
 * CRM table from Commerce and the contexts stay decoupled. `(order_id, product_id)` is unique, which
 * is what makes fulfilment idempotent under webhook retries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_entitlements', function (Blueprint $table): void {
            $table->id();
            $table->publicId();

            $table->unsignedBigInteger('organization_id')->index();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();

            // Null seats = unlimited (a whole-organization licence). Used seats mirrors the active
            // assignment rows and is maintained under a row lock so the pool cannot be over-drawn.
            $table->unsignedInteger('seats_purchased')->nullable();
            $table->unsignedInteger('seats_used')->default(0);

            $table->timestamp('access_starts_at')->nullable();
            $table->timestamp('access_ends_at')->nullable();
            $table->string('status')->default(CompanyEntitlementStatus::Active->value);

            // Policy as it stood at purchase. Seat mode and the reassignment rule are always
            // snapshotted, so they are NOT NULL: an entitlement without a policy would be a purchase
            // whose terms nobody can state.
            $table->string('seat_mode')->default(SeatMode::Fixed->value);
            $table->string('seat_reassignment_policy')->default(SeatReassignmentPolicy::Always->value);
            $table->unsignedInteger('reassignment_progress_threshold')->nullable();
            $table->string('company_certificate_branding')->nullable();
            $table->boolean('employee_access_expires_with_purchase')->default(true);

            $table->timestamps();

            $table->unique(['order_id', 'product_id'], 'company_entitlements_order_product_unique');
            $table->index(['organization_id', 'status'], 'company_entitlements_org_status_index');
        });

        Schema::create('company_entitlement_assignments', function (Blueprint $table): void {
            $table->id();
            $table->publicId();

            $table->foreignId('company_entitlement_id')
                ->constrained('company_entitlements')
                ->cascadeOnDelete();

            // The employee, held twice on purpose: the membership row is who the manager sees and
            // manages, the platform user is who receives the enrollments.
            $table->unsignedBigInteger('organization_member_id')->index();
            $table->unsignedBigInteger('user_id')->index();

            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(
                ['company_entitlement_id', 'organization_member_id'],
                'company_entitlement_assignments_pool_member_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_entitlement_assignments');
        Schema::dropIfExists('company_entitlements');
    }
};
