<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company profile captured when an organization registers itself as a buyer.
 *
 * The organization already carried what the CRM needed to describe an account (name, size, website).
 * Buying adds the details an invoice needs — country, tax registration and a billing address — so
 * checkout can prefill them instead of asking for the same facts on every purchase. All nullable:
 * organizations created by the CRM or by seeding have no billing profile and must stay valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_organizations', function (Blueprint $table): void {
            $table->string('country')->nullable();
            $table->string('industry')->nullable();
            $table->string('phone')->nullable();
            $table->string('tax_id')->nullable();
            $table->text('billing_address')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('crm_organizations', function (Blueprint $table): void {
            $table->dropColumn(['country', 'industry', 'phone', 'tax_id', 'billing_address']);
        });
    }
};
