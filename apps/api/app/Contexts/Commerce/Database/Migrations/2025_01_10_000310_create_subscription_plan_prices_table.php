<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plan_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('subscription_plans')->cascadeOnDelete();
            $table->string('currency', 3);
            $table->unsignedInteger('amount_minor');
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['plan_id', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plan_prices');
    }
};
