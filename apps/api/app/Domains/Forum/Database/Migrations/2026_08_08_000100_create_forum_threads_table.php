<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Course-level discussion threads. `course_id` is the authorization + tenancy anchor: the thread
 * inherits its owning course's T1 "global-OR-own-org" tenancy transitively (via the Forum
 * CourseTenantScope join on `courses`), so `organization_id` is a stamped denormalisation only —
 * NEVER mass-assigned, always written server-side from the course by CreateThreadAction.
 *
 * `solved_post_id` points at the accepted answer post; it carries NO foreign key to avoid a circular
 * constraint with forum_posts.thread_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forum_threads', function (Blueprint $table): void {
            $table->id();
            $table->publicId();

            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Transitive tenancy stamp (denormalised from the course). Nullable = global content.
            $table->unsignedBigInteger('organization_id')->nullable();

            $table->string('title');
            $table->text('body'); // sanitized rich-text (HtmlSanitizer on write)

            $table->timestamp('pinned_at')->nullable();
            $table->timestamp('locked_at')->nullable();

            // Accepted answer. No FK (circular with forum_posts.thread_id).
            $table->unsignedBigInteger('solved_post_id')->nullable();

            $table->unsignedInteger('posts_count')->default(0);
            $table->timestamp('last_post_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['course_id', 'pinned_at']);
            $table->index(['course_id', 'last_post_at']);
            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forum_threads');
    }
};
