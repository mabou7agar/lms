<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A team may have a manager (a platform user). Nullable + nullOnDelete, mirroring departments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_teams', function (Blueprint $table): void {
            $table->foreignId('manager_id')->nullable()->after('name')
                ->constrained('users')->nullOnDelete();
            $table->index('manager_id');
        });
    }

    public function down(): void
    {
        Schema::table('crm_teams', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('manager_id');
        });
    }
};
