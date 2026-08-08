<?php

namespace App\Domains\Catalog\Actions\Category;

use App\Domains\Catalog\Models\Category;
use App\Platform\Shared\Actions\BaseAction;

/**
 * Reversible archive/activate toggle for a category. Archiving flips `is_active` to false so the
 * category drops out of the active listing (Category::scopeActive) WITHOUT deleting anything —
 * courses stay attached and the row is untouched otherwise. Activating flips it back to true. This
 * is the safe, non-destructive alternative to DeleteCategoryAction.
 */
class ArchiveCategoryAction extends BaseAction
{
    /** Hide the category from the active listing (reversible). */
    public function archive(Category $category): Category
    {
        return $this->setActive($category, false);
    }

    /** Restore the category to the active listing. */
    public function activate(Category $category): Category
    {
        return $this->setActive($category, true);
    }

    private function setActive(Category $category, bool $active): Category
    {
        return $this->transaction(function () use ($category, $active): Category {
            $category->is_active = $active;
            $category->save();

            return $category;
        });
    }
}
