<?php

namespace App\Domains\Catalog\Filament\Resources\CategoryResource\Pages;

use App\Domains\Catalog\Actions\Category\ReorderCategoriesAction;
use App\Domains\Catalog\Filament\Resources\CategoryResource;
use App\Domains\Catalog\Models\Category;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCategories extends ListRecords
{
    protected static string $resource = CategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /**
     * Persist a drag-reorder through the domain ReorderCategoriesAction rather than Filament's default
     * inline column update. Filament hands us the table record keys (primary ids) in their new order;
     * we translate them to public_ids (the action's contract) preserving order, then delegate. The
     * action assigns 0-based `position` values in a single transaction.
     *
     * @param  array<int, int|string>  $order
     */
    public function reorderTable(array $order, int|string|null $draggedRecordKey = null): void
    {
        if (! $this->getTable()->isReorderable()) {
            return;
        }

        /** @var array<array-key, string> $publicIdsById */
        $publicIdsById = Category::query()
            ->whereIn('id', $order)
            ->pluck('public_id', 'id')
            ->all();

        $orderedPublicIds = [];

        foreach ($order as $id) {
            if (isset($publicIdsById[$id])) {
                $orderedPublicIds[] = (string) $publicIdsById[$id];
            }
        }

        app(ReorderCategoriesAction::class)->execute($orderedPublicIds);
    }
}
