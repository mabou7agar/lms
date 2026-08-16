<?php

declare(strict_types=1);

namespace App\Domains\Authoring\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A learner took a course file. Emitted after the download URL has been issued — the fact recorded is
 * "access was granted", which is the only thing the server can honestly attest to. Whether the bytes
 * finished arriving happens at the client and nobody here can know it.
 *
 * Published as a fact with internal ids only. Nothing in the download path waits on a listener: if
 * analytics is unavailable the learner still gets their file, because a reporting concern must never
 * stand between somebody and something they paid for.
 */
class CourseResourceDownloaded
{
    use Dispatchable;

    public function __construct(
        public readonly int $resourceId,
        public readonly int $courseId,
        public readonly ?int $lessonId,
        public readonly int $userId,
    ) {}
}
