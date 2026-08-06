<?php

namespace App\Platform\Pages\Filament\Resources;

use App\Platform\Identity\Contracts\Actor;
use App\Platform\Pages\Actions\UpdateStaticPageAction;
use App\Platform\Pages\Enums\PageStatus;
use App\Platform\Pages\Enums\TemplateType;
use App\Platform\Pages\Filament\Resources\StaticPageResource\Pages;
use App\Platform\Pages\Filament\Resources\StaticPageResource\RelationManagers\VersionsRelationManager;
use App\Platform\Pages\Models\StaticPage;
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
 * The Static Pages CMS editor — structured page records (NOT a drag-and-drop builder). Manages
 * bilingual title/body (sanitized RichEditor) / excerpt, hero image, one of the predefined
 * TemplateType layouts, the editorial PageStatus with an optional schedule window, position,
 * show-in-nav, and a full SEO tab. A Publish record action and a version-history relation manager
 * (list + per-version Rollback) round it out. Admin/super-admin gated.
 */
class StaticPageResource extends Resource
{
    protected static ?string $model = StaticPage::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Static Pages';

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
        $timezone = Auth::user()?->timezone ?? config('shared.default_timezone', 'UTC');

        return is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true)
            ? $timezone
            : 'UTC';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Page')->columnSpanFull()->tabs([
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
                    ->helperText('URL slug, e.g. "about" or "help/getting-started". Must be unique.')
                    ->rule('regex:/^[a-z0-9]+(?:[-\/][a-z0-9]+)*$/'),
                Select::make('template')->options(TemplateType::options())
                    ->default(TemplateType::Standard->value)->required()
                    ->helperText('Predefined layout — not freeform.'),
                TextInput::make('title.en')->label('Title (EN)')->required()->maxLength(200),
                TextInput::make('title.ar')->label('Title (AR)')->maxLength(200),
                TextInput::make('hero_image')->label('Hero image URL')->url()->maxLength(2048)
                    ->columnSpanFull(),
            ]),
            Section::make('Excerpt')->columns(2)->schema([
                Textarea::make('excerpt.en')->label('Excerpt (EN)')->rows(2)->maxLength(500),
                Textarea::make('excerpt.ar')->label('Excerpt (AR)')->rows(2)->maxLength(500),
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
                Select::make('status')->options(PageStatus::options())
                    ->default(PageStatus::Draft->value)->required()
                    ->helperText('Only "Published" pages inside their schedule window are served publicly.'),
                Select::make('reviewer_id')->label('Reviewer')
                    ->relationship('reviewer', 'name')->searchable()->preload()
                    ->helperText('Optional — the admin who reviewed this page.'),
                DateTimePicker::make('published_at')->label('Publish at')->timezone(self::adminTimezone())
                    ->helperText('Leave blank to publish immediately when status is Published.'),
                DateTimePicker::make('unpublished_at')->label('Unpublish at')->timezone(self::adminTimezone())
                    ->helperText('Optional — automatically retires the page after this time.'),
            ]),
            Section::make('Placement')->columns(2)->schema([
                TextInput::make('position')->numeric()->default(0)
                    ->helperText('Lower numbers first (used for nav / sitemap ordering).'),
                Toggle::make('show_in_nav')->label('Show in navigation')->inline(false),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->columns([
                TextColumn::make('slug')->badge()->searchable()->sortable(),
                TextColumn::make('title.en')->label('Title')->searchable()->wrap(),
                TextColumn::make('template')->badge()->toggleable()
                    ->formatStateUsing(fn ($state) => $state instanceof TemplateType ? $state->label() : $state),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof PageStatus ? $state->label() : $state)
                    ->color(fn ($state) => $state === PageStatus::Published ? 'success' : 'gray'),
                IconColumn::make('show_in_nav')->boolean()->label('In nav')->toggleable(),
                TextColumn::make('published_at')->dateTime()->placeholder('—')->label('Published')->toggleable(),
                TextColumn::make('position')->sortable()->toggleable(),
                TextColumn::make('updated_at')->dateTime()->since()->label('Updated')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(PageStatus::options()),
                SelectFilter::make('template')->options(TemplateType::options()),
            ])
            ->recordActions([
                Action::make('publish')
                    ->label('Publish')
                    ->icon('heroicon-o-rocket-launch')
                    ->color('success')
                    ->visible(fn (StaticPage $record): bool => $record->status !== PageStatus::Published)
                    ->requiresConfirmation()
                    ->modalDescription('Publish this page now? It becomes live on its public URL immediately.')
                    ->action(function (StaticPage $record): void {
                        app(UpdateStaticPageAction::class)->publish($record);
                        Notification::make()->title('Page published')->success()->send();
                    }),
                Action::make('preview')
                    ->label('Preview')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn (StaticPage $record): string => rtrim((string) config('shared.frontend_url'), '/').'/p/'.$record->slug, shouldOpenInNewTab: true),
            ]);
    }

    public static function getRelations(): array
    {
        return [VersionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStaticPages::route('/'),
            'create' => Pages\CreateStaticPage::route('/create'),
            'edit' => Pages\EditStaticPage::route('/{record}/edit'),
        ];
    }
}
