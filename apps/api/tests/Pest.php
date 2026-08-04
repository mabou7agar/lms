<?php

use App\Domains\Authoring\Models\Lesson;
use App\Domains\Authoring\Models\Section;
use App\Domains\Authoring\Services\ContentVersioningService;
use App\Domains\Catalog\Models\Course;
use Tests\TestCase;

// Bind the Laravel TestCase so config()/response() helpers work in both suites.
uses(TestCase::class)->in('Feature', 'Unit');

// Shared Authoring content-version test helpers. Defined here — in Pest's bootstrap, which every
// process (including each ParaTest worker) loads — rather than only inside ContentVersionSnapshotTest,
// so they resolve under `php artisan test --parallel`, where ParaTest distributes test files across
// workers and a worker may run a using-file (ContentVersionApi/Integrity/Operations) without the
// defining file. The in-file definitions keep their own function_exists guards, so nothing redeclares.
if (! function_exists('courseWithLessons')) {
    function courseWithLessons(int $lessons = 2): Course
    {
        $course = Course::factory()->create();
        $section = Section::factory()->create(['course_id' => $course->id]);
        for ($i = 0; $i < $lessons; $i++) {
            Lesson::factory()->create(['section_id' => $section->id, 'position' => $i, 'title' => "Lesson {$i}"]);
        }

        return $course;
    }
}

if (! function_exists('versioning')) {
    function versioning(): ContentVersioningService
    {
        return app(ContentVersioningService::class);
    }
}
