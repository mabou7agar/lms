<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The certificate half of the policy snapshot, which the seat wave left out.
 *
 * A company entitlement already freezes seats, access window and reassignment rules as they were
 * sold, for the obvious reason: an admin editing the product afterwards must not rewrite terms
 * somebody already paid for. Certificates were the one part still read live, so switching a product
 * to "no certificate" would have retroactively taken the credential away from every employee working
 * through a bundle that was sold with one. These columns close that hole.
 *
 * Existing rows are backfilled from their product, which is exactly what was in force when they were
 * created — the seat wave shipped one commit ago, so no entitlement predates its own product policy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_entitlements', function (Blueprint $table): void {
            $table->boolean('certificate_enabled')->default(true);
            $table->string('certificate_expiry_type')->nullable();
            $table->unsignedInteger('certificate_expiry_value')->nullable();
            $table->timestamp('certificate_expires_at')->nullable();
        });

        // Backfill from the product each entitlement was created for.
        $entitlements = DB::table('company_entitlements')
            ->join('products', 'products.id', '=', 'company_entitlements.product_id')
            ->select([
                'company_entitlements.id',
                'products.certificate_enabled',
                'products.certificate_expiry_type',
                'products.certificate_expiry_value',
                'products.certificate_expires_at',
            ])
            ->get();

        foreach ($entitlements as $row) {
            DB::table('company_entitlements')->where('id', $row->id)->update([
                'certificate_enabled' => $row->certificate_enabled,
                'certificate_expiry_type' => $row->certificate_expiry_type,
                'certificate_expiry_value' => $row->certificate_expiry_value,
                'certificate_expires_at' => $row->certificate_expires_at,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('company_entitlements', function (Blueprint $table): void {
            $table->dropColumn([
                'certificate_enabled',
                'certificate_expiry_type',
                'certificate_expiry_value',
                'certificate_expires_at',
            ]);
        });
    }
};
