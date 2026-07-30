<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->foreignId('from_plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            $table->foreignId('to_plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            $table->unsignedInteger('amount_minor')->default(0);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index('subscription_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_changes');
    }
};
