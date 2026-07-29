<?php

namespace App\Domains\Assessment\Enums;

/**
 * What a learner is expected to hand in for an assignment. `text_and_file` requires both a written
 * response AND at least one file; the others require exactly the artefact named.
 */
enum SubmissionType: string
{
    case Text = 'text';
    case File = 'file';
    case TextAndFile = 'text_and_file';
    case ExternalUrl = 'external_url';

    public function requiresText(): bool
    {
        return $this === self::Text || $this === self::TextAndFile;
    }

    public function requiresFile(): bool
    {
        return $this === self::File || $this === self::TextAndFile;
    }

    public function requiresUrl(): bool
    {
        return $this === self::ExternalUrl;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $t) => $t->value, self::cases());
    }
}
