<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('provider_reference')->nullable();
            $table->string('status', 20);
            $table->unsignedInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('error_code')->nullable();
            $table->unsignedSmallInteger('attempt_no');
            $table->timestamps();

            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');
    }
};
