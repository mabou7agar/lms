<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds a nullable `course_id` to content_embeddings so a retrieval can be confined to a single
     * course. Additive and nullable: existing rows/back-compat are unaffected; the value is populated
     * at (re)ingestion time from the chunk's owning course (the course itself for a course chunk, the
     * parent course for a lesson/Q&A chunk). This is what lets the RAG tutor + instructor copilot
     * ground answers in ONE course's content and never another's.
     */
    public function up(): void
    {
        Schema::table('content_embeddings', function (Blueprint $table): void {
            $table->unsignedBigInteger('course_id')->nullable()->after('organization_id');
            // The tutor/copilot read path always filters by (course_id, visibility) within a tenant.
            $table->index(['course_id', 'visibility', 'organization_id'], 'content_embeddings_course_path');
        });
    }

    public function down(): void
    {
        Schema::table('content_embeddings', function (Blueprint $table): void {
            $table->dropIndex('content_embeddings_course_path');
            $table->dropColumn('course_id');
        });
    }
};
