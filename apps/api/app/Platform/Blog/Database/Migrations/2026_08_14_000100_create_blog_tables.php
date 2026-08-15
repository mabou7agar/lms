<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Blog taxonomy categories (see App\Platform\Blog). One row per category, addressed by a
        // unique `slug`, ordered by `position`. `name`/`description` are bilingual JSON bags.
        Schema::create('blog_categories', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->string('slug')->unique();
            $table->json('name');
            $table->json('description')->nullable();
            $table->integer('position')->default(0);
            $table->timestamps();

            $table->index('position');
        });

        // Blog articles. One row per post, addressed by a unique `slug`. `title`/`excerpt`/`body`/
        // `seo` are bilingual/structured JSON bags. `cover_image` holds a MediaAsset public_id ref.
        // Only a `published` row inside its published_at/unpublished_at window is served publicly.
        Schema::create('blog_posts', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->string('slug')->unique();
            $table->json('title');
            $table->json('excerpt')->nullable();
            $table->json('body');
            $table->string('cover_image', 2048)->nullable();
            $table->foreignId('blog_category_id')->nullable()->constrained('blog_categories')->nullOnDelete();
            $table->string('status', 16)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('unpublished_at')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->integer('reading_minutes')->nullable();
            $table->json('seo')->nullable();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'published_at']);
            $table->index('is_featured');
            $table->index('blog_category_id');
        });

        // Append-only version history: one snapshot of the post fields per recorded version, used
        // for the admin version list and rollback. Snapshots are taken on every post update.
        Schema::create('blog_post_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_post_id')->constrained('blog_posts')->cascadeOnDelete();
            $table->integer('version');
            $table->json('snapshot');
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['blog_post_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_post_versions');
        Schema::dropIfExists('blog_posts');
        Schema::dropIfExists('blog_categories');
    }
};
