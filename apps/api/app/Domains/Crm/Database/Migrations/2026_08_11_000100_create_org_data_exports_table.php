<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Org BI/data export jobs. Mirrors the Analytics export_jobs artifact pattern (queued -> stored
 | artifact -> signed download) but is CRM-owned and confined to ONE organization: every row an org
 | exports belongs to that org. The stored file_path is never serialized (downloads go through a
 | signed, org-bound route), and the manifest describes the produced bundle (files/columns/rows).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_data_exports', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            // The owning organization. Isolation anchor: every query confines on this column.
            $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
            // Who requested it (audit only; authority is re-derived from the tenant on each request).
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('dataset', 32)->default('bi_bundle');
            $table->string('status', 16)->default('queued'); // queued | processing | completed | failed
            $table->string('storage_prefix')->nullable();     // artifact directory — never exposed
            $table->json('manifest')->nullable();              // generated_at / files / columns / rows
            $table->unsignedInteger('row_count')->nullable();  // total rows across the bundle
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('org_data_exports');
    }
};
