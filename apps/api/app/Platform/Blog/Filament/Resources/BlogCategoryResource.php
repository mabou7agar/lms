<?php

namespace App\Platform\Blog\Filament\Resources;

use App\Platform\Blog\Filament\Resources\BlogCategoryResource\Pages;
use App\Platform\Blog\Models\BlogCategory;
use App\Platform\Identity\Contracts\Actor;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * The Blog categories editor — a simple taxonomy (slug, bilingual name/description, position) that
 * blog posts are filed under. Admin/super-admin gated.
 */
class BlogCategoryResource extends Resource
{
    protected static ?string $model = BlogCategory::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Blog Categories';

    protected static ?string $recordRouteKeyName = 'public_id';

    protected static ?string $recordTitleAttribute = 'slug';

    /** Gate the whole resource to admins (the panel already requires it; defence in depth). */
    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof Actor && $user->hasRole(['admin', 'super_admin']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identity')->columns(2)->schema([
                TextInput::make('slug')->required()->maxLength(160)
                    ->helperText('URL slug, e.g. "insights". Must be unique.')
                    ->rule('regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'),
                TextInput::make('position')->numeric()->default(0)
                    ->helperText('Lower numbers first.'),
                TextInput::make('name.en')->label('Name (EN)')->required()->maxLength(160),
                TextInput::make('name.ar')->label('Name (AR)')->maxLength(160)
                    ->extraInputAttributes(['dir' => 'rtl']),
            ]),
            Section::make('Description')->columns(2)->schema([
                Textarea::make('description.en')->label('Description (EN)')->rows(2)->maxLength(500),
                Textarea::make('description.ar')->label('Description (AR)')->rows(2)->maxLength(500)
                    ->extraInputAttributes(['dir' => 'rtl']),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->columns([
                TextColumn::make('slug')->badge()->searchable()->sortable(),
                TextColumn::make('name.en')->label('Name')->searchable()->wrap(),
                TextColumn::make('posts_count')->counts('posts')->label('Posts')->toggleable(),
                TextColumn::make('position')->sortable()->toggleable(),
                TextColumn::make('updated_at')->dateTime()->since()->label('Updated')->toggleable(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBlogCategories::route('/'),
            'create' => Pages\CreateBlogCategory::route('/create'),
            'edit' => Pages\EditBlogCategory::route('/{record}/edit'),
        ];
    }
}
