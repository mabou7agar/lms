<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shared moderation substrate: one row per report raised against any user-generated content.
 *
 * The reportable target is a SCALAR polymorphic reference (reportable_type/reportable_id) with NO
 * foreign key — the substrate must not depend on any reporting domain's table. reporter_user_id and
 * resolved_by are genuine FKs into the Identity users table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_reports', function (Blueprint $table) {
            $table->id();
            $table->publicId();

            // Polymorphic target — no FK (points at reviews, answers, forum posts, ...).
            $table->string('reportable_type');
            $table->unsignedBigInteger('reportable_id');

            $table->foreignId('reporter_user_id')->constrained('users')->cascadeOnDelete();

            $table->string('reason', 32);            // ReportReason
            $table->text('note')->nullable();
            $table->string('status', 16)->default('pending'); // ReportStatus

            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->index(['reportable_type', 'reportable_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_reports');
    }
};
