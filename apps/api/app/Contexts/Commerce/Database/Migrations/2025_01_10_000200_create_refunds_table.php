<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('status', 20);
            $table->string('reason', 40)->nullable();
            $table->string('provider_reference')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
