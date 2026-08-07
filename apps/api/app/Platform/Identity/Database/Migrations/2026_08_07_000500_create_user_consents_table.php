<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_consents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('purpose', 64);
            $table->boolean('granted')->default(false);
            // The policy/terms version the decision was made against (audit trail for re-consent).
            $table->string('version', 32)->nullable();
            $table->string('source_ip', 45)->nullable();
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            // Current-state row per (user, purpose); history is captured by granted_at/revoked_at.
            $table->unique(['user_id', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_consents');
    }
};
