<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('e_invoice_documents', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            // Opaque reference to the Commerce invoice; no cross-domain FK by design.
            $table->unsignedBigInteger('invoice_id')->nullable()->index();
            $table->string('provider', 32);
            $table->string('status', 32);
            $table->string('provider_reference')->nullable();
            $table->string('hash', 128)->nullable();
            $table->jsonb('payload');
            $table->timestamp('cleared_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('e_invoice_documents');
    }
};
