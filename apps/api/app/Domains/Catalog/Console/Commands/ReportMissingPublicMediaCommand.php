<?php

namespace App\Domains\Catalog\Console\Commands;

use App\Domains\Catalog\Models\Course;
use App\Platform\Identity\Contracts\Data\UserRef;
use App\Platform\Identity\Contracts\UserLookupPort;
use App\Platform\Shared\Filament\Forms\Components\MediaPicker;
use Illuminate\Console\Command;

/**
 * Operator worklist: which PUBLIC-facing images are still missing, and exactly where to upload them.
 *
 * The catalog and trainer surfaces are designed to degrade gracefully — a course without a thumbnail
 * falls back to the generated CourseCover, a trainer without a photo falls back to initials — so a
 * missing upload is INVISIBLE as a defect. It just looks like a design choice. That is why the media
 * gap went unnoticed for so long, and why "which images are actually missing?" needs to be a question
 * the system answers rather than something an operator eyeballs across twelve cards.
 *
 * This command reads the same values the public API resolves, so its answer always matches what a
 * visitor sees:
 *   - courses: `thumbnail_path` empty (MediaPicker::classifyValue() === 'empty');
 *   - trainers: the effective avatar UserRef exposes (profile_photo, falling back to avatar_path).
 *
 * It is READ-ONLY. It imports nothing and guesses nothing: attaching an image to the wrong course or
 * the wrong person is worse than showing a fallback, so filling these gaps is deliberately a human
 * action through the admin panel, and this command just says precisely where to go.
 *
 * `--fail-on-missing` makes it exit non-zero when anything is outstanding, so it can gate a release
 * checklist.
 */
class ReportMissingPublicMediaCommand extends Command
{
    protected $signature = 'catalog:report-missing-public-media
        {--fail-on-missing : Exit with a failure code when any public image is still missing}';

    protected $description = 'List courses without a thumbnail and trainers without a profile photo, with the admin path to fix each';

    private const COURSE_PATH = 'Admin → Catalog → Courses → Edit course → Thumbnail → Upload new → Save';

    private const TRAINER_PATH = 'Admin → Identity → Instructor Profiles → Edit trainer → Profile photo → Upload new → Save';

    public function handle(UserLookupPort $users): int
    {
        $courses = Course::query()
            ->orderBy('id')
            ->get()
            ->filter(fn (Course $course): bool => MediaPicker::classifyValue($course->thumbnail_path) === 'empty')
            ->values();

        // UserRef::avatarPath already prefers profile_photo and falls back to avatar_path, which is
        // exactly what the public trainer cards render — so an empty ref here means a visible fallback.
        $trainers = array_values(array_filter(
            $users->instructors(),
            fn (UserRef $trainer): bool => MediaPicker::classifyValue($trainer->avatarPath) === 'empty',
        ));

        $this->reportCourses($courses->all());
        $this->reportTrainers($trainers);

        $missing = $courses->count() + count($trainers);

        $this->newLine();

        if ($missing === 0) {
            $this->info('All public course thumbnails and trainer photos are in place.');

            return self::SUCCESS;
        }

        $this->warn("{$missing} public image(s) still missing. Uploaded media always wins over the fallback, so each upload above replaces a placeholder immediately.");

        return $this->option('fail-on-missing') ? self::FAILURE : self::SUCCESS;
    }

    /** @param  list<Course>  $courses */
    private function reportCourses(array $courses): void
    {
        $this->newLine();
        $this->line('<options=bold>Course thumbnails</>');

        if ($courses === []) {
            $this->info('  Every course has a thumbnail.');

            return;
        }

        $this->line('  Upload each via: '.self::COURSE_PATH);
        $this->newLine();

        // Plain lines rather than a table: Symfony wraps table cells to the terminal width, which splits a
        // slug or uuid across lines and makes the output impossible to copy into a ticket or grep.
        foreach ($courses as $course) {
            $this->line(sprintf('  - %s  [slug: %s]  [id: %s]', $course->title, $course->slug, $course->public_id));
        }
    }

    /** @param  list<UserRef>  $trainers */
    private function reportTrainers(array $trainers): void
    {
        $this->newLine();
        $this->line('<options=bold>Trainer profile photos</>');

        if ($trainers === []) {
            $this->info('  Every active trainer has a profile photo.');

            return;
        }

        $this->line('  Upload each via: '.self::TRAINER_PATH);
        $this->newLine();

        foreach ($trainers as $trainer) {
            $this->line(sprintf('  - %s  [id: %s]', $trainer->name, $trainer->publicId));
        }
    }
}
