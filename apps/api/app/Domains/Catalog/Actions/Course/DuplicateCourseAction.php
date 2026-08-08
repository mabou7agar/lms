<?php

namespace App\Domains\Catalog\Actions\Course;

use App\Domains\Catalog\Enums\CourseStatus;
use App\Domains\Catalog\Models\Course;
use App\Domains\Catalog\Services\SlugService;
use App\Platform\Shared\Actions\BaseAction;
use App\Platform\Shared\Curriculum\Contracts\CurriculumForkPort;
use Illuminate\Support\Facades\DB;

/**
 * Clone a course into a NEW, independent DRAFT. The copy gets a fresh public_id (HasPublicId) and a
 * fresh unique slug; its title is suffixed " (Copy)" in every locale; status resets to Draft;
 * is_featured is cleared; and all publish timestamps (published_at / last_published_at /
 * scheduled_publish_at) are nulled.
 *
 * organization_id is NEVER copied: it is stamped SERVER-SIDE from the resolved tenant by
 * BelongsToTenantNullable on create (it is not mass-assignable), so a copy always belongs to the
 * acting tenant — a forged/client organization_id can never reach it.
 *
 * Catalog-level associations are copied: categories, tags, trainers (with role/position/is_primary
 * preserved), and gallery items (new rows referencing the SAME shared media assets — assets are never
 * duplicated). The curriculum is materialised through the Shared CurriculumForkPort (Authoring's
 * snapshot fork), so Catalog never imports Authoring; with the null port the curriculum copy is
 * simply deferred.
 */
class DuplicateCourseAction extends BaseAction
{
    public function __construct(
        private readonly SlugService $slugs,
        private readonly CurriculumForkPort $curriculumFork,
    ) {}

    public function execute(Course $source): Course
    {
        return $this->transaction(function () use ($source): Course {
            $copyTitleI18n = $this->copyTitleMap($source);
            $defaultLocale = (string) config('shared.default_locale', 'en');
            $defaultTitle = (string) ($copyTitleI18n[$defaultLocale] ?? reset($copyTitleI18n));

            $copy = Course::create([
                'title' => $defaultTitle,
                'title_i18n' => $copyTitleI18n,
                'slug' => $this->slugs->forModel(Course::class, $defaultTitle),
                'subtitle' => $source->subtitle,
                'subtitle_i18n' => $source->subtitle_i18n,
                'description' => $source->description,
                'description_i18n' => $source->description_i18n,
                'learning_objectives_i18n' => $source->learning_objectives_i18n,
                'requirements_i18n' => $source->requirements_i18n,
                'target_audience_i18n' => $source->target_audience_i18n,
                'duration_minutes' => $source->duration_minutes,
                'level_id' => $source->level_id,
                'language_id' => $source->language_id,
                // A copy always starts as a fresh Draft, unfeatured, with no publish history.
                'status' => CourseStatus::Draft->value,
                'visibility' => $source->visibility->value,
                'is_featured' => false,
                'thumbnail_path' => $source->thumbnail_path,
                'trailer_path' => $source->trailer_path,
                'seo' => $source->seo,
            ]);

            $this->copyAssociations($source, $copy);

            // Curriculum copy via the Shared port (Authoring adapter) — no direct Catalog->Authoring
            // dependency. A no-op under the null default (curriculum copy deferred).
            $this->curriculumFork->fork((int) $source->getKey(), (int) $copy->getKey());

            return $copy;
        });
    }

    /**
     * The source title map with " (Copy)" appended to each locale value. Falls back to the legacy
     * scalar title when no i18n map exists.
     *
     * @return array<string, string>
     */
    private function copyTitleMap(Course $source): array
    {
        $map = $source->title_i18n;

        if (! is_array($map) || $map === []) {
            $map = ['en' => (string) $source->title];
        }

        $copy = [];
        foreach ($map as $locale => $value) {
            $copy[(string) $locale] = is_string($value) && $value !== '' ? $value.' (Copy)' : (string) $value;
        }

        return $copy;
    }

    /** Copy categories, tags, trainers (facets preserved) and gallery items onto the new course. */
    private function copyAssociations(Course $source, Course $copy): void
    {
        $copy->categories()->sync($source->categories()->pluck('categories.id')->all());
        $copy->tags()->sync($source->tags()->pluck('course_tags.id')->all());

        // Trainers: copy the pivot rows verbatim (role / position / is_primary preserved). A raw insert
        // bypasses the CourseTrainer "one primary per course" saved-hook demotion, which is exactly
        // what we want when cloning an already-consistent set of assignments.
        $trainerRows = DB::table('course_trainer')
            ->where('course_id', $source->getKey())
            ->get(['user_id', 'role', 'position', 'is_primary']);

        foreach ($trainerRows as $row) {
            DB::table('course_trainer')->insert([
                'course_id' => $copy->getKey(),
                'user_id' => (int) $row->user_id,
                'role' => $row->role,
                'position' => (int) $row->position,
                'is_primary' => (bool) $row->is_primary,
            ]);
        }

        // Gallery: new ordering rows that reference the SAME shared media assets (media_public_id is a
        // cross-context reference, never a duplicated asset).
        foreach ($source->galleryItems as $item) {
            $copy->galleryItems()->create([
                'media_public_id' => $item->media_public_id,
                'position' => $item->position,
            ]);
        }
    }
}
