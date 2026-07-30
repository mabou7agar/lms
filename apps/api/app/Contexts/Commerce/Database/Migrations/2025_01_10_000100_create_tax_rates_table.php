<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->string('type', 16)->default('vat');
            $table->string('country_code', 2);
            $table->string('currency', 3)->nullable();
            $table->unsignedInteger('rate_bps');
            $table->boolean('inclusive')->default(false);
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['type', 'country_code', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rates');
    }
};
