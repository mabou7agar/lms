<?php

use App\Contexts\Commerce\Enums\AccessDurationType;
use App\Contexts\Commerce\Enums\CertificateExpiryType;
use App\Contexts\Commerce\Enums\CertificateRefundPolicy;
use App\Contexts\Commerce\Enums\CompanyCertificateBranding;
use App\Contexts\Commerce\Enums\ProductAudience;
use App\Contexts\Commerce\Enums\RefundAccessPolicy;
use App\Contexts\Commerce\Enums\SeatMode;
use App\Contexts\Commerce\Enums\SeatReassignmentPolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commercial policy for a purchasable product (a single course or a bundle of them).
 *
 * The policy lives on `products` rather than in a side table because every purchasable thing needs
 * exactly one policy and it is always read together with the product — a join would buy nothing. The
 * defaults chosen here reproduce today's behaviour (lifetime access, certificate on, revoke on
 * refund), so existing rows keep working without a data backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            // Who may buy this. Individuals enrol directly; companies receive seats to distribute.
            $table->string('audience')->default(ProductAudience::Individual->value);

            // How long a purchase grants access.
            $table->string('access_duration_type')->default(AccessDurationType::Lifetime->value);
            $table->unsignedInteger('access_duration_value')->nullable();
            $table->timestamp('access_ends_at')->nullable();

            // Certificate issuing and validity.
            $table->boolean('certificate_enabled')->default(true);
            $table->string('certificate_expiry_type')->default(CertificateExpiryType::None->value);
            $table->unsignedInteger('certificate_expiry_value')->nullable();
            $table->timestamp('certificate_expires_at')->nullable();

            // Expiry reminders. Offsets are whole days BEFORE the expiry moment; channels is a list
            // of ReminderChannel values. Both are stored as JSON because they are genuinely
            // multi-valued and are read as a unit, never queried across rows.
            $table->jsonb('reminder_offsets_days')->nullable();
            $table->jsonb('reminder_channels')->nullable();

            // What a refund does to access and to an already-earned certificate.
            $table->string('refund_access_policy')->default(RefundAccessPolicy::RevokeImmediately->value);
            $table->string('certificate_refund_policy')->default(CertificateRefundPolicy::Revoke->value);

            // Company purchases: seats, reassignment, and whose branding the certificate carries.
            $table->string('seat_mode')->default(SeatMode::NotApplicable->value);
            $table->unsignedInteger('default_seat_count')->nullable();
            $table->string('seat_reassignment_policy')->default(SeatReassignmentPolicy::BeforeStart->value);
            $table->unsignedTinyInteger('reassignment_progress_threshold')->nullable();
            $table->string('company_certificate_branding')->default(CompanyCertificateBranding::HelbaronOnly->value);

            // Employee access normally dies with the company's purchase; kept as an explicit,
            // admin-visible flag rather than an implicit rule.
            $table->boolean('employee_access_expires_with_purchase')->default(true);

            // Audience is the field public listings filter on, so it is worth an index.
            $table->index(['audience', 'status'], 'products_audience_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('products_audience_status_index');
            $table->dropColumn([
                'audience',
                'access_duration_type',
                'access_duration_value',
                'access_ends_at',
                'certificate_enabled',
                'certificate_expiry_type',
                'certificate_expiry_value',
                'certificate_expires_at',
                'reminder_offsets_days',
                'reminder_channels',
                'refund_access_policy',
                'certificate_refund_policy',
                'seat_mode',
                'default_seat_count',
                'seat_reassignment_policy',
                'reassignment_progress_threshold',
                'company_certificate_branding',
                'employee_access_expires_with_purchase',
            ]);
        });
    }
};
