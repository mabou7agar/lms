<?php

namespace App\Platform\Blog\Filament\Resources;

use App\Platform\Blog\Actions\UpdateBlogPostAction;
use App\Platform\Blog\Enums\PostStatus;
use App\Platform\Blog\Filament\Resources\BlogPostResource\Pages;
use App\Platform\Blog\Filament\Resources\BlogPostResource\RelationManagers\VersionsRelationManager;
use App\Platform\Blog\Models\BlogPost;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Shared\Filament\Forms\Components\MediaPicker;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * The Blog CMS editor — bilingual blog articles with version history. Manages bilingual
 * title/excerpt/body (sanitized RichEditor), a cover image (MediaPicker), a category, featured flag
 * and reading estimate, the editorial PostStatus with an optional schedule window, and a full SEO
 * tab. A Publish record action, a frontend preview link, and a version-history relation manager
 * (list + per-version Rollback) round it out. Admin/super-admin gated.
 */
class BlogPostResource extends Resource
{
    protected static ?string $model = BlogPost::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-newspaper';

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Blog Posts';

    protected static ?string $recordRouteKeyName = 'public_id';

    protected static ?string $recordTitleAttribute = 'slug';

    /** Gate the whole resource to admins (the panel already requires it; defence in depth). */
    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof Actor && $user->hasRole(['admin', 'super_admin']);
    }

    /**
     * The signed-in admin's IANA timezone (falling back to the platform default) so the schedule
     * pickers store the operator's local wall-clock correctly. Guards a missing/non-IANA value.
     */
    private static function adminTimezone(): string
    {
        // Read the timezone as a plain attribute (no narrowing to the Identity User model, which the
        // Blog context must not reference). Fall back to the platform default when absent/non-IANA.
        $timezone = Auth::user()->getAttribute('timezone');

        if (is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true)) {
            return $timezone;
        }

        $default = config('shared.default_timezone', 'UTC');

        return is_string($default) ? $default : 'UTC';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Post')->columnSpanFull()->tabs([
                self::contentTab(),
                self::seoTab(),
                self::publishingTab(),
            ]),
        ]);
    }

    private static function contentTab(): Tab
    {
        return Tab::make('Content')->icon('heroicon-o-document-text')->schema([
            Section::make('Identity')->columns(2)->schema([
                TextInput::make('slug')->required()->maxLength(160)
                    ->helperText('URL slug, e.g. "getting-started-with-analytics". Must be unique.')
                    ->rule('regex:/^[a-z0-9]+(?:[-\/][a-z0-9]+)*$/'),
                Select::make('blog_category_id')->label('Category')
                    // Query by the plain `slug` column (searchable); display the localized name.
                    ->relationship('category', 'slug')
                    ->getOptionLabelFromRecordUsing(fn ($record): string => $record->name['en'] ?? $record->slug)
                    ->searchable()->preload()
                    ->helperText('Optional — file this post under a category.'),
                TextInput::make('title.en')->label('Title (EN)')->required()->maxLength(200),
                TextInput::make('title.ar')->label('Title (AR)')->maxLength(200)
                    ->extraInputAttributes(['dir' => 'rtl']),
            ]),
            Section::make('Cover')->columns(2)->schema([
                MediaPicker::make('cover_image')
                    ->label('Cover image')
                    ->purpose('lesson_image')
                    ->acceptedTypes(['image'])
                    ->allowLegacyUrl()
                    ->reusable()
                    ->searchable()
                    // Blog cards render a 16:9 cover — crop to that frame on upload.
                    ->imageAspectRatios(['16:9'])
                    ->helperText('Pick from the media library or upload a new image. Crop to the 16:9 card frame. Existing URLs are kept until replaced.'),
                TextInput::make('reading_minutes')->label('Reading minutes')->numeric()->minValue(1)
                    ->helperText('Estimated read time in minutes (optional).'),
            ]),
            Section::make('Excerpt')->columns(2)->schema([
                Textarea::make('excerpt.en')->label('Excerpt (EN)')->rows(2)->maxLength(500),
                Textarea::make('excerpt.ar')->label('Excerpt (AR)')->rows(2)->maxLength(500)
                    ->extraInputAttributes(['dir' => 'rtl']),
            ]),
            Section::make('Body')->schema([
                RichEditor::make('body.en')->label('Body (EN)')
                    ->helperText('HTML is sanitized on save (scripts/iframes/styles removed).'),
                RichEditor::make('body.ar')->label('Body (AR)')
                    ->helperText('يُنقّى الـ HTML عند الحفظ.'),
            ]),
        ]);
    }

    private static function seoTab(): Tab
    {
        return Tab::make('SEO')->icon('heroicon-o-magnifying-glass')->schema([
            Section::make('Meta')->columns(2)->schema([
                TextInput::make('seo.meta_title.en')->label('Meta title (EN)')->maxLength(255),
                TextInput::make('seo.meta_title.ar')->label('Meta title (AR)')->maxLength(255),
                Textarea::make('seo.meta_description.en')->label('Meta description (EN)')->rows(2)->maxLength(320),
                Textarea::make('seo.meta_description.ar')->label('Meta description (AR)')->rows(2)->maxLength(320),
                TextInput::make('seo.keywords')->label('Keywords')->maxLength(255)
                    ->helperText('Comma-separated.'),
                TextInput::make('seo.canonical')->label('Canonical URL/path')->maxLength(2048),
            ]),
            Section::make('Robots')->columns(2)->schema([
                Toggle::make('seo.robots_index')->label('Allow indexing')->default(true)->inline(false),
                Toggle::make('seo.robots_follow')->label('Allow following links')->default(true)->inline(false),
            ]),
            Section::make('Social (Open Graph / Twitter)')->columns(2)->schema([
                TextInput::make('seo.og_title')->label('OG title')->maxLength(255),
                Textarea::make('seo.og_description')->label('OG description')->rows(2)->maxLength(320),
                TextInput::make('seo.og_image')->label('OG image URL')->url()->maxLength(2048),
                Select::make('seo.twitter_card')->label('Twitter card')
                    ->options(['summary' => 'Summary', 'summary_large_image' => 'Summary (large image)'])
                    ->default('summary_large_image'),
            ]),
            Section::make('Structured data')->schema([
                Textarea::make('seo.json_ld')->label('JSON-LD')->rows(4)
                    ->helperText('Optional raw JSON-LD injected as a <script type="application/ld+json"> tag on the page.'),
            ]),
        ]);
    }

    private static function publishingTab(): Tab
    {
        return Tab::make('Publishing')->icon('heroicon-o-rocket-launch')->schema([
            Section::make('Status & schedule')->columns(2)->schema([
                Select::make('status')->options(PostStatus::options())
                    ->default(PostStatus::Draft->value)->required()
                    ->helperText('Only "Published" posts inside their schedule window are served publicly.'),
                DateTimePicker::make('published_at')->label('Publish at')->timezone(self::adminTimezone())
                    ->helperText('Leave blank to publish immediately when status is Published.'),
                DateTimePicker::make('unpublished_at')->label('Unpublish at')->timezone(self::adminTimezone())
                    ->helperText('Optional — automatically retires the post after this time.'),
            ]),
            Section::make('Placement')->columns(2)->schema([
                Toggle::make('is_featured')->label('Featured')->inline(false)
                    ->helperText('Featured posts are highlighted on the blog and exposed via ?featured=true.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('published_at', 'desc')
            ->columns([
                TextColumn::make('slug')->badge()->searchable()->sortable(),
                TextColumn::make('title.en')->label('Title')->searchable()->wrap(),
                TextColumn::make('category.name.en')->label('Category')->badge()->placeholder('—')->toggleable(),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof PostStatus ? $state->label() : $state)
                    ->color(fn ($state) => $state === PostStatus::Published ? 'success' : 'gray'),
                IconColumn::make('is_featured')->boolean()->label('Featured')->toggleable(),
                TextColumn::make('reading_minutes')->label('Read (min)')->placeholder('—')->toggleable(),
                TextColumn::make('published_at')->dateTime()->placeholder('—')->label('Published')->toggleable(),
                TextColumn::make('updated_at')->dateTime()->since()->label('Updated')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(PostStatus::options()),
                SelectFilter::make('blog_category_id')->label('Category')->relationship('category', 'slug'),
            ])
            ->recordActions([
                Action::make('publish')
                    ->label('Publish')
                    ->icon('heroicon-o-rocket-launch')
                    ->color('success')
                    ->visible(fn (BlogPost $record): bool => $record->status !== PostStatus::Published)
                    ->requiresConfirmation()
                    ->modalDescription('Publish this post now? It becomes live on its public URL immediately.')
                    ->action(function (BlogPost $record): void {
                        app(UpdateBlogPostAction::class)->publish($record);
                        Notification::make()->title('Post published')->success()->send();
                    }),
                Action::make('preview')
                    ->label('Preview')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn (BlogPost $record): string => rtrim((string) config('shared.frontend_url'), '/').'/blog/'.$record->slug, shouldOpenInNewTab: true),
            ]);
    }

    public static function getRelations(): array
    {
        return [VersionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBlogPosts::route('/'),
            'create' => Pages\CreateBlogPost::route('/create'),
            'edit' => Pages\EditBlogPost::route('/{record}/edit'),
        ];
    }
}
