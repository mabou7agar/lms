<?php

namespace App\Domains\Authoring\Actions\Lesson;

use App\Domains\Authoring\Enums\LessonType;
use App\Domains\Authoring\Enums\PublishState;
use App\Domains\Authoring\Models\Lesson;
use App\Domains\Authoring\Models\Section;
use App\Platform\Shared\Actions\BaseAction;
use App\Platform\Shared\Html\HtmlSanitizer;

class CreateLessonAction extends BaseAction
{
    public function __construct(private readonly HtmlSanitizer $sanitizer) {}

    /** @param array<string, mixed> $data */
    public function execute(Section $section, array $data): Lesson
    {
        $content = $data['content'] ?? [];
        if (is_array($content)) {
            // Defense in depth: HTML-bearing content fields are sanitized before persistence.
            $content = $this->sanitizer->sanitizeArray($content);
        }

        return $this->transaction(function () use ($section, $data, $content): Lesson {
            $position = (int) Lesson::where('section_id', $section->id)->max('position');

            // C1: pass through the optional title locale map; HasTranslations keeps `title` synced.
            return Lesson::create(array_filter([
                'section_id' => $section->id,
                'title' => $data['title'],
                'title_i18n' => $data['title_i18n'] ?? null,
                'type' => ($data['type'] instanceof LessonType ? $data['type']->value : $data['type']),
                'content' => $content,
                'position' => $position + 1,
                'is_preview' => $data['is_preview'] ?? config('authoring.preview.default', false),
                'publish_state' => PublishState::Draft->value,
            ], fn ($v) => $v !== null));
        });
    }
}
