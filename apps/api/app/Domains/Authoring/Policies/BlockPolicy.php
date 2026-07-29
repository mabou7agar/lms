<?php

namespace App\Domains\Authoring\Policies;

use App\Domains\Authoring\Models\Block;
use App\Domains\Authoring\Models\Lesson;
use App\Domains\Authoring\Models\Section;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Shared\Policies\BasePolicy;
use Illuminate\Support\Facades\Gate;

class BlockPolicy extends BasePolicy
{
    public function before(mixed $user, string $ability): ?bool
    {
        if ($user instanceof Actor && $user->hasRole('super_admin')) {
            return true;
        }

        return null;
    }

    public function view(Actor $user, Block $block): bool
    {
        return $this->managesCourse($user, $block);
    }

    public function update(Actor $user, Block $block): bool
    {
        return $this->managesCourse($user, $block);
    }

    public function delete(Actor $user, Block $block): bool
    {
        return $this->managesCourse($user, $block);
    }

    /**
     * Authorize through the block's ancestry (block → lesson → section → course) against the single
     * `authoring.manage-curriculum` gate. Resolving the whole chain means a block whose lesson
     * belongs to another course is checked against that course, so cross-course tampering (a foreign
     * block id) is denied automatically.
     */
    private function managesCourse(Actor $user, Block $block): bool
    {
        $lesson = $block->lesson;
        if (! $lesson instanceof Lesson) {
            return false;
        }

        $section = $lesson->section;
        if (! $section instanceof Section) {
            return false;
        }

        $course = $section->course;

        return $course !== null && Gate::forUser($user)->allows('authoring.manage-curriculum', $course);
    }
}
