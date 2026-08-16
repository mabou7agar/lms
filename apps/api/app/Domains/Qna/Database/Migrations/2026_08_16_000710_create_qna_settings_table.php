<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The admin-controlled Q&A response promise. A single row, like certificate_settings: there is one
 * platform-wide service level, and per-course overrides are a product decision nobody has made.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qna_settings', function (Blueprint $table): void {
            $table->id();
            // Calendar hours. 48 is a working promise rather than an aspirational one — see QnaSetting.
            $table->unsignedInteger('response_sla_hours')->default(48);
            $table->boolean('notify_instructor_on_overdue')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qna_settings');
    }
};
