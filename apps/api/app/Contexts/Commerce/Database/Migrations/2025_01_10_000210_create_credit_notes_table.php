<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('refund_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number')->unique();
            $table->string('status', 20)->default('issued');
            $table->string('currency', 3);
            $table->unsignedInteger('total_minor');
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();

            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_notes');
    }
};
