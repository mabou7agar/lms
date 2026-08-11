<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Versioned prompt library. Every version of a prompt is an immutable row; (key, version) is
     * unique. The active row for a key+locale is what PromptLibrary resolves, and the version it
     * resolves is stamped onto the ai_usages row for the run — so prompt provenance is auditable.
     */
    public function up(): void
    {
        Schema::create('ai_prompts', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->string('key');
            $table->string('purpose')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->text('system_prompt')->nullable();
            $table->text('user_template');
            $table->jsonb('variables')->nullable();       // declared variable names/metadata
            $table->string('model_preference')->nullable(); // "provider:model" | "model" | null
            $table->string('locale', 8)->default('en');
            $table->boolean('active')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['key', 'version']);
            $table->index(['key', 'locale', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_prompts');
    }
};
