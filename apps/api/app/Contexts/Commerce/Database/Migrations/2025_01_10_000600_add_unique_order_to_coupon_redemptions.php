<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backstop the coupon per-user / first-order rules at the database level: exactly one redemption row
 * per order. The application re-checks the per-user cap under a row lock during checkout, and this
 * unique index guarantees a retried or duplicated checkout can never write a second redemption for
 * the same order even if that logic is ever bypassed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupon_redemptions', function (Blueprint $table): void {
            $table->unique('order_id', 'coupon_redemptions_order_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('coupon_redemptions', function (Blueprint $table): void {
            $table->dropUnique('coupon_redemptions_order_id_unique');
        });
    }
};
