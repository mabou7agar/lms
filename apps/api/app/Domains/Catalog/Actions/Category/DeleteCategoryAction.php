<?php

namespace App\Domains\Catalog\Actions\Category;

use App\Domains\Catalog\Exceptions\CategoryNotDeletableException;
use App\Domains\Catalog\Models\Category;
use App\Platform\Shared\Actions\BaseAction;

/**
 * Guarded (soft) delete for a category. The guard is the whole point: a category that still has
 * courses attached, or that is a parent with child categories, is REFUSED — deletion only proceeds
 * once there are zero courses and zero children.
 *
 * Parent-with-children rule: REFUSE (never silently reparent). Reparenting would move a whole
 * sub-tree implicitly; refusing keeps the taxonomy change an explicit, auditable admin decision. The
 * reversible alternative for "get it out of the way" is ArchiveCategoryAction (is_active=false).
 *
 * The guard runs inside the same transaction as the delete, so the courses/children checks and the
 * delete are consistent under concurrency.
 */
class DeleteCategoryAction extends BaseAction
{
    public function execute(Category $category): void
    {
        $this->transaction(function () use ($category): void {
            $this->guard($category);
            $category->delete();
        });
    }

    /**
     * Assert the category may be deleted. Public so callers (e.g. Filament action visibility) can
     * pre-check without triggering the delete.
     *
     * @throws CategoryNotDeletableException when courses are attached or child categories exist
     */
    public function guard(Category $category): void
    {
        $courseCount = $category->courses()->count();

        if ($courseCount > 0) {
            throw CategoryNotDeletableException::hasCourses($courseCount);
        }

        $childCount = $category->children()->count();

        if ($childCount > 0) {
            throw CategoryNotDeletableException::hasChildren($childCount);
        }
    }

    /** Whether this category is currently safe to delete (no courses, no children). */
    public function isDeletable(Category $category): bool
    {
        return $category->courses()->doesntExist() && $category->children()->doesntExist();
    }
}
