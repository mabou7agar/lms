<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When an enrollment stops granting access.
 *
 * Only company-seat enrollments carry a date: a company buys training for a window, and an employee's
 * access to it ends with the purchase. NULL — the default, and what every existing row keeps — means
 * access never lapses, which is exactly what an individual purchase, a free enrollment and a manual
 * grant should all continue to do. That is why this is a nullable column rather than a computed rule:
 * the individual learner's access must be untouchable by a company's clock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $table): void {
            $table->timestamp('expires_at')->nullable()->after('enrolled_at');

            // Expiry sweeps and the learner's own access check both read (user, expiry).
            $table->index(['user_id', 'expires_at'], 'enrollments_user_expires_index');
        });
    }

    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table): void {
            $table->dropIndex('enrollments_user_expires_index');
            $table->dropColumn('expires_at');
        });
    }
};
