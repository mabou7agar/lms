<?php

namespace App\Domains\Catalog\Filament\Resources\CourseResource\RelationManagers;

use App\Platform\Shared\Filament\Forms\Components\MediaPicker;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * U8 - Ordered course gallery. Each row is a media reference chosen through the shared MediaPicker
 * (pick an existing image OR upload a new one). Order is the drag-reorderable `position` column;
 * deleting a row removes only the ordering item and never the shared MediaAsset.
 */
class GalleryRelationManager extends RelationManager
{
    protected static string $relationship = 'galleryItems';

    protected static ?string $title = 'Gallery';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            MediaPicker::make('media_public_id')
                ->label('Image')
                ->required()
                ->purpose('lesson_image')
                ->acceptedTypes(['image'])
                ->reusable()
                ->searchable(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->reorderable('position')
            ->columns([
                TextColumn::make('position')->label('#')->sortable(),
                TextColumn::make('media_public_id')->label('Media reference')->limit(40),
            ])
            ->headerActions([
                CreateAction::make()
                    ->modalHeading('Add gallery image')
                    // Append to the end; explicit reordering is the drag handle on the table.
                    ->mutateDataUsing(fn (array $data, RelationManager $livewire): array => [
                        ...$data,
                        'position' => (int) $livewire->getOwnerRecord()->galleryItems()->max('position') + 1,
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([]);
    }
}
