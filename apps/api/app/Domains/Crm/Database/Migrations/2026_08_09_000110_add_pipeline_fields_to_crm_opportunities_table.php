<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promotes crm_opportunities to a first-class pipeline object: pipeline/stage placement, an owner,
 * a win probability, an optional owning organization and product/plan reference, plus lifecycle
 * timestamps (won/closed) and a lost reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_opportunities', function (Blueprint $table): void {
            $table->foreignId('pipeline_id')->nullable()->after('lead_id')->constrained('crm_pipelines')->nullOnDelete();
            $table->foreignId('stage_id')->nullable()->after('pipeline_id')->constrained('crm_stages')->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->after('stage_id')->constrained('users')->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->after('company_id')->constrained('crm_organizations')->nullOnDelete();
            $table->unsignedTinyInteger('probability')->default(0);
            $table->string('product_ref')->nullable();
            $table->string('lost_reason')->nullable();
            $table->timestamp('won_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->index(['pipeline_id', 'stage_id']);
        });
    }

    public function down(): void
    {
        Schema::table('crm_opportunities', function (Blueprint $table): void {
            $table->dropIndex(['pipeline_id', 'stage_id']);
            $table->dropConstrainedForeignId('pipeline_id');
            $table->dropConstrainedForeignId('stage_id');
            $table->dropConstrainedForeignId('owner_id');
            $table->dropConstrainedForeignId('organization_id');
            $table->dropColumn(['probability', 'product_ref', 'lost_reason', 'won_at', 'closed_at']);
        });
    }
};
