<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sso_domain_mappings', function (Blueprint $table): void {
            $table->id();
            $table->publicId(); // UUIDv7 external id (shared Blueprint macro)

            // The owning tenant. Opaque indexed id (NOT a cross-domain FK), matching how the users
            // table stores organization_id — Identity stays decoupled from the CRM organizations table.
            $table->unsignedBigInteger('organization_id');

            // A domain is GLOBALLY unique: exactly one organization may claim an email domain, so a
            // sign-in domain can never map to two orgs. Stored lowercased by the FormRequest.
            $table->string('domain')->unique();

            // auto_join = users signing in with this email domain join the org;
            // restrict     = only listed domains may SSO into the org.
            $table->string('mode', 16);

            // Verification is a super-admin-toggled stub flag (no DNS/email verification is built).
            $table->timestamp('verified_at')->nullable();

            // The user who created the mapping (opaque id; nullable for system/seed rows).
            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();

            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_domain_mappings');
    }
};
