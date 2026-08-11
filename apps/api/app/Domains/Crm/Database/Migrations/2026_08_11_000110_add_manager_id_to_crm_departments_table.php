<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A department may have a manager (a platform user). Nullable + nullOnDelete: the department survives
 * the manager's account removal, it just loses its manager. Opaque users FK, matching the tenancy
 * decoupling used elsewhere in CRM.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_departments', function (Blueprint $table): void {
            $table->foreignId('manager_id')->nullable()->after('name')
                ->constrained('users')->nullOnDelete();
            $table->index('manager_id');
        });
    }

    public function down(): void
    {
        Schema::table('crm_departments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('manager_id');
        });
    }
};
