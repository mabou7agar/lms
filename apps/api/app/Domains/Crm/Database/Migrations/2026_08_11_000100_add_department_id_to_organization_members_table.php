<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manager hierarchy (additive, nullable): a member may belong to a department. FK is nullOnDelete so
 * deleting a department detaches its members instead of cascading them away.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_members', function (Blueprint $table): void {
            $table->foreignId('department_id')->nullable()->after('organization_id')
                ->constrained('crm_departments')->nullOnDelete();
            $table->index('department_id');
        });
    }

    public function down(): void
    {
        Schema::table('organization_members', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('department_id');
        });
    }
};
