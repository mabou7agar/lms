<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a task type (call/email/meeting/follow_up/other), a completion timestamp and an optional
 * priority to crm_tasks so the sales surface can categorise and close activities.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_tasks', function (Blueprint $table): void {
            $table->string('type', 16)->default('other')->after('title');
            $table->string('priority', 16)->nullable()->after('status');
            $table->timestamp('completed_at')->nullable()->after('due_at');
        });
    }

    public function down(): void
    {
        Schema::table('crm_tasks', function (Blueprint $table): void {
            $table->dropColumn(['type', 'priority', 'completed_at']);
        });
    }
};
