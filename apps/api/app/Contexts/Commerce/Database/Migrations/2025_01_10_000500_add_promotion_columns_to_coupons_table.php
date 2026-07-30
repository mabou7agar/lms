<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            if (! Schema::hasColumn('coupons', 'per_user_limit')) {
                $table->unsignedInteger('per_user_limit')->nullable()->after('max_redemptions');
            }

            if (! Schema::hasColumn('coupons', 'first_order_only')) {
                $table->boolean('first_order_only')->default(false)->after('per_user_limit');
            }

            if (! Schema::hasColumn('coupons', 'min_subtotal_minor')) {
                $table->unsignedInteger('min_subtotal_minor')->nullable()->after('first_order_only');
            }

            if (! Schema::hasColumn('coupons', 'stackable')) {
                $table->boolean('stackable')->default(false)->after('min_subtotal_minor');
            }
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            foreach (['per_user_limit', 'first_order_only', 'min_subtotal_minor', 'stackable'] as $column) {
                if (Schema::hasColumn('coupons', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
