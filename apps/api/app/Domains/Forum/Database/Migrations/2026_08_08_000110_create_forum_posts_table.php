<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replies inside a forum thread. Nesting is capped at ONE level: `parent_post_id` may reference only
 * a top-level post (a reply to a reply is rejected by ReplyToThreadAction), so the tree is never
 * deeper than thread -> post -> reply. `is_instructor` is a derived badge stamped server-side at
 * create time (via CourseAccessPort) — never mass-assigned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forum_posts', function (Blueprint $table): void {
            $table->id();
            $table->publicId();

            $table->foreignId('thread_id')->constrained('forum_threads')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->unsignedBigInteger('parent_post_id')->nullable();
            $table->foreign('parent_post_id')->references('id')->on('forum_posts')->nullOnDelete();

            $table->text('body'); // sanitized rich-text (HtmlSanitizer on write)
            $table->boolean('is_instructor')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->index('thread_id');
            $table->index('parent_post_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forum_posts');
    }
};
