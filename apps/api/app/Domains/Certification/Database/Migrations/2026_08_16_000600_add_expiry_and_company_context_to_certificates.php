<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a credential stops being valid, and whose marks it carries.
 *
 * Both are commercial facts the certificate had no way to record. A product can be sold with a
 * credential that lapses after two years; a company bundle can be sold with the company's logo on
 * the certificate. Certification does not know what a product or a seat pool is, so these arrive
 * already resolved through the Shared certificate-policy port and are SNAPSHOTTED here — the
 * certificate must still read correctly years later, after the company has rebranded or the product
 * has been retired.
 *
 * Every column is nullable and every existing certificate keeps its meaning: no expiry, no company,
 * platform branding — exactly what it had.
 *
 * `organization_id` carries no foreign key, matching how orders, media assets and brand settings all
 * reference an organization from outside CRM.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificates', function (Blueprint $table): void {
            $table->timestamp('expires_at')->nullable()->after('issued_at');

            $table->unsignedBigInteger('organization_id')->nullable()->after('enrollment_id');
            $table->string('company_name')->nullable()->after('organization_id');
            $table->string('company_logo_url')->nullable()->after('company_name');
            $table->string('branding_mode')->nullable()->after('company_logo_url');

            $table->index('organization_id');
            // The reminder sweep reads "valid certificates expiring in the next N days".
            $table->index(['status', 'expires_at'], 'certificates_status_expires_index');
        });
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table): void {
            $table->dropIndex('certificates_status_expires_index');
            $table->dropIndex(['organization_id']);
            $table->dropColumn([
                'expires_at', 'organization_id', 'company_name', 'company_logo_url', 'branding_mode',
            ]);
        });
    }
};
