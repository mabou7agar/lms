<?php

namespace App\Domains\Catalog\Console\Commands;

use App\Domains\Catalog\Models\Course;
use App\Platform\Identity\Contracts\UserLookupPort;
use App\Platform\Shared\Filament\Forms\Components\MediaPicker;
use App\Platform\Shared\Media\Contracts\MediaPickerPort;
use Illuminate\Console\Command;
use SplFileInfo;
use Throwable;

/**
 * LOCAL REPAIR (never production) - import course thumbnails that were left stranded in Livewire's
 * temporary upload directory.
 *
 * Background: the admin MediaPicker uploads through `FileUpload::storeFiles(false)`, so the browser's
 * file lands in `storage/app/private/livewire-tmp` FIRST and only becomes a MediaAsset when the upload
 * modal's action completes. A batch of thumbnails was uploaded while that action was aborting before it
 * got there (the credential-free `fake` ingestion provider was being handed the bytes over HTTP, so every
 * upload died on `Could not resolve host: upload.fake.test`, and the picker swallowed the error), leaving
 * the bytes on disk under Livewire's mangled name while `courses.thumbnail_path` stayed empty.
 *
 * Livewire encodes the ORIGINAL client filename into the temp name as `...-meta<base64>-.<ext>`, which
 * is what makes this recovery deterministic rather than guesswork: a temp file is adopted ONLY when its
 * decoded original filename (sans extension) matches a course TITLE exactly, case/whitespace-insensitively.
 * Anything without an exact match is reported and left untouched - never guessed.
 *
 * Safety / idempotency:
 *   - refuses to run in production;
 *   - only fills courses whose thumbnail_path is currently EMPTY, so a second run is a no-op (--force
 *     opts into replacing an existing thumbnail);
 *   - --dry-run prints the mapping without writing anything;
 *   - the import goes through the SAME MediaPickerPort seam the admin picker uses, so every asset is
 *     minted by the media engine (ready + public + owned by the acting admin) - nothing is hand-rolled;
 *   - the temp file is left in place, so a partially failed run can simply be retried.
 */
class RecoverCourseThumbnailsCommand extends Command
{
    protected $signature = 'catalog:recover-course-thumbnails
        {--actor= : Acting admin, as a numeric user id or an email}
        {--actor-email=admin@helbaron.local : Email used to resolve the acting admin when --actor is omitted}
        {--dir= : Livewire temp upload directory (defaults to storage/app/private/livewire-tmp)}
        {--force : Also replace thumbnails on courses that already have one}
        {--dry-run : Print the mapping without importing anything}';

    protected $description = 'Import course thumbnails stranded in the Livewire temp upload directory (idempotent, exact title match only)';

    /** Image extensions we are willing to adopt as a course thumbnail. */
    private const EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'gif'];

    public function handle(MediaPickerPort $media, UserLookupPort $users): int
    {
        if (app()->environment('production')) {
            $this->error('catalog:recover-course-thumbnails is a local repair tool and is disabled in production.');

            return self::FAILURE;
        }

        $actorId = $this->resolveActorId($users);

        if ($actorId === null) {
            return self::FAILURE;
        }

        $directory = (string) ($this->option('dir') ?: storage_path('app/private/livewire-tmp'));

        if (! is_dir($directory)) {
            $this->error("Temp upload directory not found: {$directory}");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $candidates = $this->candidatesByTitle($directory);

        if ($candidates === []) {
            $this->warn("No recoverable image uploads found in {$directory}.");

            return self::SUCCESS;
        }

        $imported = 0;
        $skipped = 0;

        foreach (Course::query()->orderBy('id')->get() as $course) {
            $title = (string) $course->title;
            $file = $candidates[$this->normalize($title)] ?? null;

            if ($file === null) {
                $this->line("  - no exact-title image for [{$title}] - left untouched.");
                $skipped++;

                continue;
            }

            if (! $force && MediaPicker::classifyValue($course->thumbnail_path) !== 'empty') {
                $this->line("  = [{$title}] already has a thumbnail - skipped (use --force to replace).");
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $this->info("  > [{$title}] <- {$file->getFilename()}");
                $imported++;

                continue;
            }

            try {
                $publicId = $media->upload(
                    actorId: $actorId,
                    purpose: 'lesson_image',
                    filename: $title.'.'.$file->getExtension(),
                    mimeType: $this->mimeFor($file),
                    sizeBytes: (int) $file->getSize(),
                    contents: (string) file_get_contents($file->getPathname()),
                );
            } catch (Throwable $e) {
                $this->error("  ! [{$title}]: {$e->getMessage()}");
                $skipped++;

                continue;
            }

            $course->forceFill(['thumbnail_path' => $publicId])->save();

            $this->info("  ok [{$title}] -> {$publicId}");
            $imported++;
        }

        $this->newLine();
        $this->info($dryRun
            ? "{$imported} course(s) would be updated, {$skipped} left untouched (dry run - nothing written)."
            : "{$imported} course thumbnail(s) imported, {$skipped} left untouched.");

        return self::SUCCESS;
    }

    /**
     * Livewire stores a temp upload as `<random>-meta<base64 of the original filename>-.<ext>`.
     * Returns the decoded original filename, or null when the name does not carry one.
     */
    public static function decodeOriginalName(string $tempFilename): ?string
    {
        if (preg_match('/-meta(.*?)-\./', $tempFilename, $matches) !== 1) {
            return null;
        }

        // Livewire strips base64 padding from the segment; restore it before decoding.
        $encoded = $matches[1];
        $encoded .= str_repeat('=', (4 - strlen($encoded) % 4) % 4);

        $decoded = base64_decode($encoded, true);

        return is_string($decoded) && $decoded !== '' ? $decoded : null;
    }

    /** Resolve the acting admin from --actor (id or email) or the --actor-email fallback. */
    private function resolveActorId(UserLookupPort $users): ?int
    {
        $actor = trim((string) $this->option('actor'));

        if ($actor !== '') {
            $id = ctype_digit($actor) ? (int) $actor : $users->idByEmail($actor);

            if ($id === null || $users->refById($id) === null) {
                $this->error("No user matches --actor={$actor}.");

                return null;
            }

            return $id;
        }

        $email = (string) $this->option('actor-email');
        $id = $users->idByEmail($email);

        if ($id === null) {
            $this->error("No user found for {$email}. Pass --actor=<id|email> explicitly.");

            return null;
        }

        return $id;
    }

    /**
     * Map normalized ORIGINAL filename (sans extension) to the newest temp file carrying it.
     *
     * @return array<string, SplFileInfo>
     */
    private function candidatesByTitle(string $directory): array
    {
        $byTitle = [];

        foreach ((array) scandir($directory) as $entry) {
            if (! is_string($entry) || $entry === '.' || $entry === '..') {
                continue;
            }

            $file = new SplFileInfo($directory.DIRECTORY_SEPARATOR.$entry);

            if (! $file->isFile() || ! in_array(strtolower($file->getExtension()), self::EXTENSIONS, true)) {
                continue;
            }

            $original = self::decodeOriginalName($entry);

            if ($original === null) {
                continue;
            }

            $key = $this->normalize(pathinfo($original, PATHINFO_FILENAME));

            if ($key === '') {
                continue;
            }

            // Keep the most recent upload for a title - that is the operator's latest attempt.
            if (! isset($byTitle[$key]) || $file->getMTime() > $byTitle[$key]->getMTime()) {
                $byTitle[$key] = $file;
            }
        }

        return $byTitle;
    }

    /** Case/whitespace-insensitive comparison key. Exact match only - no fuzzy matching. */
    private function normalize(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
    }

    private function mimeFor(SplFileInfo $file): string
    {
        return match (strtolower($file->getExtension())) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'application/octet-stream',
        };
    }
}
