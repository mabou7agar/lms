<?php

namespace App\Domains\Authoring\Actions\Section;

use App\Domains\Authoring\Enums\PublishState;
use App\Domains\Authoring\Models\Section;
use App\Domains\Catalog\Models\Course;
use App\Platform\Shared\Actions\BaseAction;

class CreateSectionAction extends BaseAction
{
    /** @param array<string, mixed> $data */
    public function execute(Course $course, array $data): Section
    {
        return $this->transaction(function () use ($course, $data): Section {
            $position = (int) Section::where('course_id', $course->id)->max('position');

            // C1: pass through optional locale maps when present. HasTranslations keeps the legacy
            // `title`/`summary` scalars synced from the default-locale value on save.
            return Section::create(array_filter([
                'course_id' => $course->id,
                'title' => $data['title'],
                'title_i18n' => $data['title_i18n'] ?? null,
                'summary' => $data['summary'] ?? null,
                'summary_i18n' => $data['summary_i18n'] ?? null,
                'position' => $position + 1,
                'publish_state' => PublishState::Draft->value,
            ], fn ($v) => $v !== null));
        });
    }
}
