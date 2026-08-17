<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many seats a line carries.
 *
 * `unit_amount_minor` keeps its meaning — the price of one unit — and quantity multiplies it for a
 * per-seat product. Storing the count rather than only the computed total means an invoice, a
 * credit note and a seat pool can all be reconstructed from the order without guessing.
 *
 * InvoiceService has read `order_items.quantity` since the invoicing wave, defaulting a missing
 * value to 1; this is the column it was written against. Defaulting to 1 keeps every existing row
 * and every single-seat purchase exactly as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cart_items', function (Blueprint $table): void {
            $table->unsignedInteger('quantity')->default(1)->after('unit_amount_minor');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->unsignedInteger('quantity')->default(1)->after('unit_amount_minor');
        });
    }

    public function down(): void
    {
        Schema::table('cart_items', function (Blueprint $table): void {
            $table->dropColumn('quantity');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn('quantity');
        });
    }
};
