<?php

use App\Contexts\Commerce\Enums\BuyerType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buyer ownership on the cart and the order.
 *
 * A purchase belongs either to the person who made it or to an organization they buy for, and that
 * decision changes what may be bought, who is invoiced, and (from the seat wave) who receives the
 * licences. Storing it on the CART as well as the order is what lets the audience rules be enforced
 * while items are being added rather than only at checkout.
 *
 * Billing details are copied onto the order rather than read back through the organization, so a
 * historical invoice keeps the name, tax id and address that were true on the day it was issued.
 * `organization_id` deliberately has no foreign key: it points at a CRM table from Commerce, and the
 * contexts stay decoupled — orders must also survive an organization being removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table): void {
            $table->string('buyer_type')->default(BuyerType::Individual->value);
            $table->unsignedBigInteger('organization_id')->nullable()->index();
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->string('buyer_type')->default(BuyerType::Individual->value);
            $table->unsignedBigInteger('organization_id')->nullable()->index();

            // Snapshot of who the invoice is made out to, at the moment of purchase.
            $table->string('company_name')->nullable();
            $table->string('billing_name')->nullable();
            $table->string('billing_email')->nullable();
            $table->string('billing_phone')->nullable();
            $table->string('billing_country')->nullable();
            $table->string('billing_tax_id')->nullable();
            $table->text('billing_address')->nullable();

            // Company revenue reporting reads by owner + status.
            $table->index(['buyer_type', 'status'], 'orders_buyer_type_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table): void {
            $table->dropColumn(['buyer_type', 'organization_id']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_buyer_type_status_index');
            $table->dropColumn([
                'buyer_type', 'organization_id', 'company_name',
                'billing_name', 'billing_email', 'billing_phone',
                'billing_country', 'billing_tax_id', 'billing_address',
            ]);
        });
    }
};
