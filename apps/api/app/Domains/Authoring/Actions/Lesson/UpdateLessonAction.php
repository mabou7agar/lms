<?php

namespace App\Domains\Authoring\Actions\Lesson;

use App\Domains\Authoring\Actions\Concerns\GuardsLockVersion;
use App\Domains\Authoring\Models\Lesson;
use App\Platform\Shared\Actions\BaseAction;
use App\Platform\Shared\Html\HtmlSanitizer;

class UpdateLessonAction extends BaseAction
{
    use GuardsLockVersion;

    public function __construct(private readonly HtmlSanitizer $sanitizer) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  int|null  $expectedVersion  optimistic-lock guard (C3); null skips the check for
     *                                     backward-compatible callers.
     */
    public function execute(Lesson $lesson, array $data, ?int $expectedVersion = null): Lesson
    {
        $content = $data['content'] ?? null;
        if (is_array($content)) {
            // Defense in depth: HTML-bearing content fields are sanitized before persistence.
            $content = $this->sanitizer->sanitizeArray($content);
        }

        return $this->transaction(function () use ($lesson, $data, $content, $expectedVersion): Lesson {
            // Short transaction: lock only the target row for the compare-and-write window.
            $locked = Lesson::query()->whereKey($lesson->getKey())->lockForUpdate()->firstOrFail();

            $this->assertLockVersion($locked, $expectedVersion);

            $locked->fill(array_filter([
                'title' => $data['title'] ?? null,
                'title_i18n' => $data['title_i18n'] ?? null,
                'type' => $data['type'] ?? null,
                'content' => $content,
            ], fn ($v) => $v !== null));

            $this->advanceLockVersion($locked);
            $locked->save();

            return $locked;
        });
    }
}
