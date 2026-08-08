<?php

namespace App\Domains\Catalog\Exceptions;

use App\Platform\Shared\Exceptions\BaseDomainException;

/**
 * Refused to delete a category that is still referenced — it has courses attached, or it is a parent
 * with child categories. Deletion is only permitted once those references are removed (archive the
 * category instead to hide it reversibly). The caller must detach courses / reparent or delete
 * children first.
 */
class CategoryNotDeletableException extends BaseDomainException
{
    protected string $errorCode = 'CATEGORY_NOT_DELETABLE';

    protected int $status = 409;

    public static function hasCourses(int $courseCount): self
    {
        return new self(
            "This category still has {$courseCount} course(s) attached and cannot be deleted. Detach the courses first, or archive the category to hide it.",
            ['reason' => 'has_courses', 'courses_count' => $courseCount],
        );
    }

    public static function hasChildren(int $childCount): self
    {
        return new self(
            "This category has {$childCount} child categor(y/ies) and cannot be deleted. Reparent or delete the children first.",
            ['reason' => 'has_children', 'children_count' => $childCount],
        );
    }
}
