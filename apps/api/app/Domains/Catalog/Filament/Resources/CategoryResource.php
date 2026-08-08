<?php

namespace App\Domains\Catalog\Filament\Resources;

use App\Domains\Catalog\Actions\Category\ArchiveCategoryAction;
use App\Domains\Catalog\Actions\Category\DeleteCategoryAction;
use App\Domains\Catalog\Exceptions\CategoryNotDeletableException;
use App\Domains\Catalog\Filament\Resources\CategoryResource\Pages;
use App\Domains\Catalog\Models\Category;
use App\Platform\Shared\Filament\Forms\Components\MediaPicker;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Throwable;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static string|\UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?string $recordRouteKeyName = 'public_id';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Content')->columns(2)->schema([
                TextInput::make('name_i18n.en')->label('Name (EN)')->required()->maxLength(255)
                    ->helperText('English is the default and fallback locale.')
                    ->live(onBlur: true)
                    // Auto-suggest a slug from the English name while the slug is still blank; an
                    // operator-typed slug is never overwritten.
                    ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                        if (blank($get('slug')) && filled($state)) {
                            $set('slug', Str::slug((string) $state));
                        }
                    }),
                TextInput::make('name_i18n.ar')->label('Name (AR)')->maxLength(255)
                    ->extraInputAttributes(['dir' => 'rtl']),
                Textarea::make('description_i18n.en')->label('Description (EN)')->rows(3)->columnSpanFull()
                    ->helperText('English is the default and fallback locale.'),
                Textarea::make('description_i18n.ar')->label('Description (AR)')->rows(3)->columnSpanFull()
                    ->extraInputAttributes(['dir' => 'rtl']),
                TextInput::make('slug')->label('Slug')->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->helperText('URL identifier. Auto-suggested from the English name; editable and must be unique.'),
            ]),
            Section::make('Placement')->columns(2)->schema([
                Select::make('parent_id')->relationship('parent', 'name')->label('Parent')->searchable(),
                TextInput::make('position')->numeric()->default(0)
                    ->helperText('Lower numbers render first. You can also drag rows to reorder on the list.'),
                Toggle::make('is_active')->default(true)
                    ->helperText('Inactive categories are archived — hidden from the active catalog listing.'),
            ]),
            Section::make('Media')->columns(2)->schema([
                MediaPicker::make('image_path')
                    ->label('Image')
                    ->purpose('lesson_image')
                    ->acceptedTypes(['image'])
                    ->allowLegacyUrl()
                    ->searchable()
                    ->helperText('Pick from the media library or upload a new image. Existing URLs are kept until replaced.'),
                TextInput::make('icon')->label('Icon')->maxLength(255)
                    ->helperText('An icon identifier/name (e.g. a Lucide or heroicon key) for compact nav/menu rendering.'),
            ]),
            Section::make('SEO')->columns(2)->schema([
                TextInput::make('seo.meta_title')->label('Meta title')->maxLength(255),
                TextInput::make('seo.canonical')->label('Canonical URL')->maxLength(2048)->url(),
                Textarea::make('seo.meta_description')->label('Meta description')->rows(2)->columnSpanFull(),
                TextInput::make('seo.og_image')->label('OG image URL')->maxLength(2048)->url()->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // Bounded courses count in one aggregate subquery — the courses_count column and the
            // delete-action visibility read this loaded attribute, never a per-row query.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('courses'))
            ->reorderable('position')
            ->defaultSort('position')
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('parent.name')->label('Parent')->searchable()->placeholder('—')->toggleable(),
                TextColumn::make('courses_count')->label('Courses')->badge()->sortable(),
                TextColumn::make('position')->sortable(),
                IconColumn::make('is_active')->boolean()->label('Active'),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Status')
                    ->placeholder('All')
                    ->trueLabel('Active only')
                    ->falseLabel('Archived only'),
                SelectFilter::make('parent_id')
                    ->label('Parent')
                    ->relationship('parent', 'name')
                    ->searchable(),
            ])
            ->recordActions([
                EditAction::make(),
                self::archiveAction(),
                self::activateAction(),
                self::deleteAction(),
            ]);
    }

    /**
     * Reversible ARCHIVE — flips is_active to false so the category leaves the active listing without
     * deleting anything. Only shown for a currently-active category.
     */
    public static function archiveAction(): Action
    {
        return Action::make('archive')
            ->label('Archive')
            ->icon('heroicon-o-archive-box-arrow-down')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Archive category')
            ->modalDescription('Hide this category from the active catalog listing. Courses stay attached and this is fully reversible — you can activate it again at any time.')
            ->visible(fn (Category $record): bool => (bool) $record->is_active)
            ->action(function (Category $record): void {
                app(ArchiveCategoryAction::class)->archive($record);
                Notification::make()->title('Category archived')->success()->send();
            });
    }

    /** Restore an archived category back into the active listing. */
    public static function activateAction(): Action
    {
        return Action::make('activate')
            ->label('Activate')
            ->icon('heroicon-o-archive-box')
            ->color('success')
            ->visible(fn (Category $record): bool => ! $record->is_active)
            ->action(function (Category $record): void {
                app(ArchiveCategoryAction::class)->activate($record);
                Notification::make()->title('Category activated')->success()->send();
            });
    }

    /**
     * Guarded DELETE — delegates to DeleteCategoryAction, whose guard refuses a category that still
     * has courses attached or child categories (parent-with-children rule: REFUSE). A refusal surfaces
     * only as a danger notification; the resource never deletes the row itself.
     */
    public static function deleteAction(): Action
    {
        return Action::make('delete')
            ->label('Delete')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Delete category')
            ->modalDescription('Permanently remove this category. A category that still has courses attached, or that has child categories, is refused — detach courses / reparent children first, or archive it instead.')
            ->action(function (Category $record): void {
                try {
                    app(DeleteCategoryAction::class)->execute($record);
                    Notification::make()->title('Category deleted')->success()->send();
                } catch (CategoryNotDeletableException $e) {
                    Notification::make()->title('Cannot delete category')->body($e->getMessage())->danger()->send();
                } catch (Throwable $e) {
                    Notification::make()->title('Delete failed')->body($e->getMessage())->danger()->send();
                }
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCategories::route('/'),
            'create' => Pages\CreateCategory::route('/create'),
            'edit' => Pages\EditCategory::route('/{record}/edit'),
        ];
    }
}
