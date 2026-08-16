<?php

declare(strict_types=1);

namespace App\Domains\Qna\Enums;

/**
 * Who may read a question.
 *
 * `Public` is the default and the point of a course Q&A: the answer to one learner's question is
 * usually the answer to twenty more, and a thread nobody else can read helps nobody else.
 *
 * `Private` narrows it to the asker and the course team, for the questions people will not ask in
 * front of a class — a mistake they are embarrassed by, something about their own circumstances, or
 * anything they would otherwise not ask at all.
 */
enum QuestionVisibility: string
{
    case Public = 'public';
    case Private = 'private';

    public function isPrivate(): bool
    {
        return $this === self::Private;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $v): string => $v->value, self::cases());
    }
}
