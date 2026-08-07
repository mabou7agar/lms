<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table): void {
            $table->id();
            $table->publicId(); // UUIDv7 external id (shared Blueprint macro)
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider', 32);
            // The provider's stable, opaque subject id (OIDC `sub`) — the match key for returning logins.
            $table->string('provider_subject');
            // Email at link time, for display/audit only; never the identity match key.
            $table->string('email')->nullable();
            $table->timestamps();

            // One local account per external identity; prevents two users claiming the same IdP subject.
            $table->unique(['provider', 'provider_subject']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
