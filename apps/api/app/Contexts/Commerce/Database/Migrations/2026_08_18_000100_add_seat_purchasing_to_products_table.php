<?php

use App\Contexts\Commerce\Enums\PricingBasis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a company may choose at checkout, and what that choice costs.
 *
 * `seat_mode = buyer_selects` has existed since the commercial-policy wave but had nothing behind
 * it: no bounds on the number, and no statement of whether the listed price was for the package or
 * for one seat. These columns supply both, so the mode becomes sellable rather than refused.
 *
 * Defaults reproduce today's behaviour exactly — one price for the whole package, one seat minimum,
 * no maximum, whole seats — so every existing product keeps its current meaning with no backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            // Whether the listed price buys the package or one seat of it.
            $table->string('pricing_basis')->default(PricingBasis::FixedBundlePrice->value);

            // The bounds a buyer-selected seat count must satisfy. Null max means no ceiling.
            $table->unsignedInteger('min_seats')->nullable();
            $table->unsignedInteger('max_seats')->nullable();
            $table->unsignedInteger('seat_increment')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['pricing_basis', 'min_seats', 'max_seats', 'seat_increment']);
        });
    }
};
