<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The portable vector store. One row per indexed chunk of published/authorised content.
     *
     * The embedding is stored as a JSONB array of floats — NOT a pgvector column — so this runs on
     * the platform's stock Postgres (which has no pgvector). The PortableVectorStore pre-filters
     * candidates here by tenant (organization_id), visibility and locale, then scores cosine in PHP.
     * When the pgvector driver is provisioned (LOCAL/INFRA), an additional `vector` column + ANN
     * index is added by a separate infra migration; this table's shape is forward-compatible.
     *
     * organization_id is nullable: NULL = GLOBAL/platform content; a value = that tenant's private
     * content. Search always filters "(organization_id IS NULL OR organization_id = :tenant)".
     */
    public function up(): void
    {
        Schema::create('content_embeddings', function (Blueprint $table): void {
            $table->id();

            // Source identity (the record this chunk was derived from).
            $table->string('embeddable_type', 40);         // course | lesson | qna_answer ...
            $table->unsignedBigInteger('embeddable_id');
            $table->uuid('embeddable_public_id')->nullable(); // stable external id for result payloads

            // Tenancy + audience + language pre-filters.
            $table->unsignedBigInteger('organization_id')->nullable(); // NULL = global content
            $table->string('locale', 12)->default('*');      // '*' = language-agnostic (folded)
            $table->string('source_type', 16);               // course | lesson | qna
            $table->string('visibility', 16);                // public | authenticated | private

            // Chunk payload.
            $table->unsignedInteger('chunk_index')->default(0);
            $table->string('title')->nullable();
            $table->text('chunk_text');
            $table->jsonb('embedding');                       // list<float>, L2-normalised
            $table->unsignedSmallInteger('dims');
            $table->string('model', 120);
            $table->unsignedBigInteger('version')->default(1);

            $table->timestamps();

            // Retrieval + maintenance paths.
            $table->index(['embeddable_type', 'embeddable_id']);
            $table->index('organization_id');
            $table->index('source_type');
            $table->index(['source_type', 'visibility', 'organization_id']); // the pre-filter path
            // One row per (source, chunk) so a re-index upsert is idempotent.
            $table->unique(['source_type', 'embeddable_id', 'chunk_index'], 'content_embeddings_source_chunk_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_embeddings');
    }
};
