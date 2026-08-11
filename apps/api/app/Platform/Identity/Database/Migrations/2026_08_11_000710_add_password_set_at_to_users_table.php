<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Records WHEN a user last set a real, self-chosen password — the signal that distinguishes a
     * password-capable account from a social-only one.
     *
     * A social-created account is minted with a random, unknown-to-anyone password (so the column
     * `password` is never null and cannot be used as the signal). This nullable timestamp is stamped
     * only by the register/reset flows; social-only users leave it null. "Never orphan an account"
     * (unlinking the last sign-in method) reads it via User::hasUsablePassword().
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('password_set_at')->nullable()->after('password');
        });

        // Backfill existing accounts: they all registered/reset with a real password, so mark them as
        // password-capable. (No-op on a fresh test database, which has no pre-existing rows.)
        DB::table('users')->whereNull('password_set_at')->update(['password_set_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('password_set_at');
        });
    }
};
