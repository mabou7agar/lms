<?php

namespace App\Platform\Homepage\Filament\Resources;

use App\Platform\Homepage\Enums\BlockType;
use App\Platform\Homepage\Enums\HomepageStatus;
use App\Platform\Homepage\Filament\Resources\HomepageSectionResource\Pages;
use App\Platform\Homepage\Filament\Resources\HomepageSectionResource\RelationManagers\VersionsRelationManager;
use App\Platform\Homepage\Models\HomepageSection;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
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

/**
 * The Homepage BUILDER — a bounded editor over the PREDEFINED block set (BlockType). Not a freeform
 * page builder: blocks are the fixed typed cases, edited / toggled / reordered / scheduled /
 * published / rolled back. Each block shows a type-appropriate bilingual form (Content tab), shared
 * presentation controls (Presentation tab) and the editorial status/schedule + device visibility
 * (Publishing tab). A Publish action and a version-history relation manager (list + per-version
 * Rollback) round it out — mirroring the Static Pages CMS. The public homepage reads the published
 * snapshot of the live blocks.
 */
class HomepageSectionResource extends Resource
{
    protected static ?string $model = HomepageSection::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-home-modern';

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Homepage';

    protected static ?string $recordRouteKeyName = 'public_id';

    /** Blocks are the fixed set of BlockType cases — never created ad hoc. */
    public static function canCreate(): bool
    {
        return false;
    }

    private static function isType(?HomepageSection $record, BlockType $type): bool
    {
        return $record?->type === $type;
    }

    /**
     * The signed-in admin's IANA timezone (falling back to the platform default) so the schedule
     * pickers store the operator's local wall-clock correctly. Guards a missing/non-IANA value.
     */
    private static function adminTimezone(): string
    {
        $timezone = auth()->user()?->timezone ?? config('shared.default_timezone', 'UTC');

        return is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true)
            ? $timezone
            : 'UTC';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Block')->columnSpanFull()->tabs([
                self::contentTab(),
                self::presentationTab(),
                self::publishingTab(),
            ]),
        ]);
    }

    // ----- Content tab (per-type bilingual fields) -----

    private static function contentTab(): Tab
    {
        return Tab::make('Content')->icon('heroicon-o-document-text')->schema([
            TextInput::make('key')->label('Block')->disabled()->dehydrated(false),

            // --- Hero ---
            ...self::visibleGroup(BlockType::Hero, [
                TextInput::make('content.headline.en')->label('Headline (EN)'),
                TextInput::make('content.headline.ar')->label('Headline (AR)'),
                Textarea::make('content.subheadline.en')->label('Subheadline (EN)')->rows(2),
                Textarea::make('content.subheadline.ar')->label('Subheadline (AR)')->rows(2),
                TextInput::make('content.cta_primary.label.en')->label('Primary CTA label (EN)'),
                TextInput::make('content.cta_primary.label.ar')->label('Primary CTA label (AR)'),
                TextInput::make('content.cta_primary.href')->label('Primary CTA link'),
                TextInput::make('content.cta_secondary.label.en')->label('Secondary CTA label (EN)'),
                TextInput::make('content.cta_secondary.label.ar')->label('Secondary CTA label (AR)'),
                TextInput::make('content.cta_secondary.href')->label('Secondary CTA link'),
                TextInput::make('content.image')->label('Hero image URL')->url(),
            ]),

            // --- Features ---
            Repeater::make('content.items')->label('Features')
                ->visible(fn (?HomepageSection $record) => self::isType($record, BlockType::Features))
                ->schema([
                    TextInput::make('title.en')->label('Title (EN)')->required(),
                    TextInput::make('title.ar')->label('Title (AR)')->required(),
                    Textarea::make('description.en')->label('Description (EN)')->rows(2),
                    Textarea::make('description.ar')->label('Description (AR)')->rows(2),
                    TextInput::make('icon')->label('Icon key'),
                ])->reorderable()->collapsible()->defaultItems(1),

            // --- Testimonials ---
            Repeater::make('content.items')->label('Testimonials')
                ->visible(fn (?HomepageSection $record) => self::isType($record, BlockType::Testimonials))
                ->schema([
                    Textarea::make('quote.en')->label('Quote (EN)')->rows(2)->required(),
                    Textarea::make('quote.ar')->label('Quote (AR)')->rows(2)->required(),
                    TextInput::make('author')->label('Author'),
                    TextInput::make('role.en')->label('Role (EN)'),
                    TextInput::make('role.ar')->label('Role (AR)'),
                    TextInput::make('avatar')->label('Avatar URL')->url(),
                ])->reorderable()->collapsible()->defaultItems(1),

            // --- Partners / Clients / Logo cloud (shared logo-item shape) ---
            ...self::logoRepeater(BlockType::Partners, 'Partners'),
            ...self::logoRepeater(BlockType::Clients, 'Clients'),
            ...self::logoRepeater(BlockType::LogoCloud, 'Logos'),

            // --- FAQ ---
            Repeater::make('content.items')->label('FAQ')
                ->visible(fn (?HomepageSection $record) => self::isType($record, BlockType::Faq))
                ->schema([
                    TextInput::make('question.en')->label('Question (EN)')->required(),
                    TextInput::make('question.ar')->label('Question (AR)')->required(),
                    Textarea::make('answer.en')->label('Answer (EN)')->rows(2),
                    Textarea::make('answer.ar')->label('Answer (AR)')->rows(2),
                ])->reorderable()->collapsible()->defaultItems(1),

            // --- Footer ---
            ...self::visibleGroup(BlockType::Footer, [
                Textarea::make('content.tagline.en')->label('Tagline (EN)')->rows(2),
                Textarea::make('content.tagline.ar')->label('Tagline (AR)')->rows(2),
            ]),
            Repeater::make('content.columns')->label('Footer columns')
                ->visible(fn (?HomepageSection $record) => self::isType($record, BlockType::Footer))
                ->schema([
                    TextInput::make('title.en')->label('Column title (EN)')->required(),
                    TextInput::make('title.ar')->label('Column title (AR)')->required(),
                    Repeater::make('links')->label('Links')
                        ->schema([
                            TextInput::make('label.en')->label('Label (EN)')->required(),
                            TextInput::make('label.ar')->label('Label (AR)')->required(),
                            TextInput::make('href')->label('Link')->required(),
                        ])->reorderable()->defaultItems(1),
                ])->reorderable()->collapsible()->defaultItems(1),

            // --- SEO ---
            ...self::visibleGroup(BlockType::Seo, [
                TextInput::make('content.meta_title.en')->label('Meta title (EN)'),
                TextInput::make('content.meta_title.ar')->label('Meta title (AR)'),
                Textarea::make('content.meta_description.en')->label('Meta description (EN)')->rows(2),
                Textarea::make('content.meta_description.ar')->label('Meta description (AR)')->rows(2),
                TextInput::make('content.og_image')->label('OG image URL')->url(),
                TextInput::make('content.canonical')->label('Canonical path'),
            ]),

            // ===== Expansion blocks =====

            // --- Statistics ---
            ...self::heading(BlockType::Statistics),
            Repeater::make('content.items')->label('Statistics')
                ->visible(fn (?HomepageSection $record) => self::isType($record, BlockType::Statistics))
                ->schema([
                    TextInput::make('value')->label('Value')->required(),
                    TextInput::make('suffix')->label('Suffix')->maxLength(8),
                    TextInput::make('label.en')->label('Label (EN)')->required(),
                    TextInput::make('label.ar')->label('Label (AR)')->required(),
                ])->reorderable()->collapsible()->defaultItems(1),

            // --- Numbers ---
            ...self::heading(BlockType::Numbers),
            Repeater::make('content.items')->label('Numbers')
                ->visible(fn (?HomepageSection $record) => self::isType($record, BlockType::Numbers))
                ->schema([
                    TextInput::make('value')->label('Value')->required(),
                    TextInput::make('label.en')->label('Label (EN)')->required(),
                    TextInput::make('label.ar')->label('Label (AR)')->required(),
                ])->reorderable()->collapsible()->defaultItems(1),

            // --- Categories ---
            ...self::headingWithSub(BlockType::Categories),
            ...self::visibleGroup(BlockType::Categories, [
                TagsInput::make('content.category_slugs')->label('Category slugs (optional allow-list)')
                    ->helperText('Leave empty to auto-show active root categories.'),
                TextInput::make('content.limit')->label('Max items')->numeric()->default(8),
            ]),

            // --- Featured Courses ---
            ...self::headingWithSub(BlockType::FeaturedCourses),
            ...self::visibleGroup(BlockType::FeaturedCourses, [
                TagsInput::make('content.course_slugs')->label('Course slugs (optional allow-list)')
                    ->helperText('Leave empty to auto-show featured/recent published courses.'),
                TextInput::make('content.limit')->label('Max items')->numeric()->default(6),
                TextInput::make('content.cta.label.en')->label('CTA label (EN)'),
                TextInput::make('content.cta.label.ar')->label('CTA label (AR)'),
                TextInput::make('content.cta.href')->label('CTA link'),
            ]),

            // --- Featured Events ---
            ...self::headingWithSub(BlockType::FeaturedEvents),
            ...self::visibleGroup(BlockType::FeaturedEvents, [
                TextInput::make('content.limit')->label('Max items')->numeric()->default(4),
                TextInput::make('content.cta.label.en')->label('CTA label (EN)'),
                TextInput::make('content.cta.label.ar')->label('CTA label (AR)'),
                TextInput::make('content.cta.href')->label('CTA link'),
            ]),

            // --- Pricing preview ---
            ...self::headingWithSub(BlockType::PricingPreview),
            Repeater::make('content.plans')->label('Plans')
                ->visible(fn (?HomepageSection $record) => self::isType($record, BlockType::PricingPreview))
                ->schema([
                    TextInput::make('name.en')->label('Name (EN)')->required(),
                    TextInput::make('name.ar')->label('Name (AR)')->required(),
                    TextInput::make('price')->label('Price'),
                    TextInput::make('period.en')->label('Period (EN)'),
                    TextInput::make('period.ar')->label('Period (AR)'),
                    Toggle::make('highlighted')->label('Highlighted')->inline(false),
                    Repeater::make('features')->label('Features')
                        ->schema([
                            TextInput::make('en')->label('Feature (EN)')->required(),
                            TextInput::make('ar')->label('Feature (AR)')->required(),
                        ])->defaultItems(1),
                    TextInput::make('cta.label.en')->label('CTA label (EN)'),
                    TextInput::make('cta.label.ar')->label('CTA label (AR)'),
                    TextInput::make('cta.href')->label('CTA link'),
                ])->reorderable()->collapsible()->defaultItems(1),

            // --- CTA ---
            ...self::visibleGroup(BlockType::Cta, [
                TextInput::make('content.headline.en')->label('Headline (EN)'),
                TextInput::make('content.headline.ar')->label('Headline (AR)'),
                Textarea::make('content.subheadline.en')->label('Subheadline (EN)')->rows(2),
                Textarea::make('content.subheadline.ar')->label('Subheadline (AR)')->rows(2),
                TextInput::make('content.cta_primary.label.en')->label('Primary CTA label (EN)'),
                TextInput::make('content.cta_primary.label.ar')->label('Primary CTA label (AR)'),
                TextInput::make('content.cta_primary.href')->label('Primary CTA link'),
                TextInput::make('content.cta_secondary.label.en')->label('Secondary CTA label (EN)'),
                TextInput::make('content.cta_secondary.label.ar')->label('Secondary CTA label (AR)'),
                TextInput::make('content.cta_secondary.href')->label('Secondary CTA link'),
            ]),

            // --- Video ---
            ...self::heading(BlockType::Video),
            ...self::visibleGroup(BlockType::Video, [
                TextInput::make('content.url')->label('Video URL')->url(),
                TextInput::make('content.poster')->label('Poster image URL')->url(),
                TextInput::make('content.caption.en')->label('Caption (EN)'),
                TextInput::make('content.caption.ar')->label('Caption (AR)'),
            ]),

            // --- Gallery ---
            ...self::heading(BlockType::Gallery),
            Repeater::make('content.items')->label('Gallery images')
                ->visible(fn (?HomepageSection $record) => self::isType($record, BlockType::Gallery))
                ->schema([
                    TextInput::make('image')->label('Image URL')->url()->required(),
                    TextInput::make('caption.en')->label('Caption (EN)'),
                    TextInput::make('caption.ar')->label('Caption (AR)'),
                ])->reorderable()->collapsible()->defaultItems(1),

            // --- Timeline ---
            ...self::heading(BlockType::Timeline),
            Repeater::make('content.items')->label('Timeline steps')
                ->visible(fn (?HomepageSection $record) => self::isType($record, BlockType::Timeline))
                ->schema([
                    TextInput::make('date.en')->label('Date/label (EN)'),
                    TextInput::make('date.ar')->label('Date/label (AR)'),
                    TextInput::make('title.en')->label('Title (EN)')->required(),
                    TextInput::make('title.ar')->label('Title (AR)')->required(),
                    Textarea::make('description.en')->label('Description (EN)')->rows(2),
                    Textarea::make('description.ar')->label('Description (AR)')->rows(2),
                ])->reorderable()->collapsible()->defaultItems(1),

            // --- Team ---
            ...self::heading(BlockType::Team),
            Repeater::make('content.items')->label('Team members')
                ->visible(fn (?HomepageSection $record) => self::isType($record, BlockType::Team))
                ->schema([
                    TextInput::make('name')->label('Name')->required(),
                    TextInput::make('role.en')->label('Role (EN)'),
                    TextInput::make('role.ar')->label('Role (AR)'),
                    TextInput::make('avatar')->label('Avatar URL')->url(),
                    TextInput::make('href')->label('Profile link')->url(),
                ])->reorderable()->collapsible()->defaultItems(1),

            // --- Newsletter ---
            ...self::headingWithSub(BlockType::Newsletter),
            ...self::visibleGroup(BlockType::Newsletter, [
                TextInput::make('content.placeholder.en')->label('Input placeholder (EN)'),
                TextInput::make('content.placeholder.ar')->label('Input placeholder (AR)'),
                TextInput::make('content.cta.en')->label('Button label (EN)'),
                TextInput::make('content.cta.ar')->label('Button label (AR)'),
                TextInput::make('content.action_url')->label('Subscribe action URL')->url(),
            ]),

            // --- Contact strip ---
            ...self::headingWithSub(BlockType::ContactStrip),
            ...self::visibleGroup(BlockType::ContactStrip, [
                TextInput::make('content.phone')->label('Phone'),
                TextInput::make('content.email')->label('Email')->email(),
                TextInput::make('content.address.en')->label('Address (EN)'),
                TextInput::make('content.address.ar')->label('Address (AR)'),
                TextInput::make('content.cta.label.en')->label('CTA label (EN)'),
                TextInput::make('content.cta.label.ar')->label('CTA label (AR)'),
                TextInput::make('content.cta.href')->label('CTA link'),
            ]),

            // --- Rich text ---
            ...self::visibleGroup(BlockType::RichText, [
                TextInput::make('content.title.en')->label('Title (EN)'),
                TextInput::make('content.title.ar')->label('Title (AR)'),
                RichEditor::make('content.body.en')->label('Body (EN)')
                    ->helperText('HTML is sanitized on save (scripts/iframes/styles removed).'),
                RichEditor::make('content.body.ar')->label('Body (AR)')
                    ->helperText('يُنقّى الـ HTML عند الحفظ.'),
            ]),

            // --- Comparison table ---
            ...self::heading(BlockType::ComparisonTable),
            Repeater::make('content.columns')->label('Columns')
                ->visible(fn (?HomepageSection $record) => self::isType($record, BlockType::ComparisonTable))
                ->schema([
                    TextInput::make('en')->label('Column (EN)')->required(),
                    TextInput::make('ar')->label('Column (AR)')->required(),
                ])->reorderable()->defaultItems(2),
            Repeater::make('content.rows')->label('Rows')
                ->visible(fn (?HomepageSection $record) => self::isType($record, BlockType::ComparisonTable))
                ->schema([
                    Repeater::make('cells')->label('Cells')
                        ->schema([
                            TextInput::make('en')->label('Cell (EN)'),
                            TextInput::make('ar')->label('Cell (AR)'),
                        ])->defaultItems(2),
                ])->reorderable()->collapsible()->defaultItems(1),
        ]);
    }

    /**
     * Wrap a list of components so they are only visible/dehydrated for the given block type.
     *
     * @param  array<int, Field>  $components
     * @return array<int, Field>
     */
    private static function visibleGroup(BlockType $type, array $components): array
    {
        return array_map(
            fn ($component) => $component->visible(fn (?HomepageSection $record) => self::isType($record, $type)),
            $components,
        );
    }

    /**
     * Bilingual heading inputs for a block type.
     *
     * @return array<int, Field>
     */
    private static function heading(BlockType $type): array
    {
        return self::visibleGroup($type, [
            TextInput::make('content.heading.en')->label('Heading (EN)'),
            TextInput::make('content.heading.ar')->label('Heading (AR)'),
        ]);
    }

    /**
     * Bilingual heading + subheading inputs.
     *
     * @return array<int, Field>
     */
    private static function headingWithSub(BlockType $type): array
    {
        return self::visibleGroup($type, [
            TextInput::make('content.heading.en')->label('Heading (EN)'),
            TextInput::make('content.heading.ar')->label('Heading (AR)'),
            Textarea::make('content.subheading.en')->label('Subheading (EN)')->rows(2),
            Textarea::make('content.subheading.ar')->label('Subheading (AR)')->rows(2),
        ]);
    }

    /**
     * Heading + a { name, logo, href } logo repeater for logo-style blocks.
     *
     * @return array<int, mixed>
     */
    private static function logoRepeater(BlockType $type, string $label): array
    {
        return [
            ...self::heading($type),
            Repeater::make('content.items')->label($label)
                ->visible(fn (?HomepageSection $record) => self::isType($record, $type))
                ->schema([
                    TextInput::make('name')->label('Name')->required(),
                    TextInput::make('logo')->label('Logo URL')->url(),
                    TextInput::make('href')->label('Link')->url(),
                ])->reorderable()->collapsible()->defaultItems(1),
        ];
    }

    // ----- Presentation tab (shared visual controls) -----

    private static function presentationTab(): Tab
    {
        return Tab::make('Presentation')->icon('heroicon-o-paint-brush')->schema([
            Section::make('Layout')->columns(2)->schema([
                TextInput::make('layout_variant')->label('Layout variant')
                    ->helperText('Optional variant key the renderer understands (e.g. "split", "grid-4").'),
                Select::make('spacing')->label('Spacing')
                    ->options(['none' => 'None', 'compact' => 'Compact', 'normal' => 'Normal', 'spacious' => 'Spacious']),
                Select::make('alignment')->label('Alignment')
                    ->options(['start' => 'Start', 'center' => 'Center', 'end' => 'End']),
                Select::make('container_width')->label('Container width')
                    ->options(['narrow' => 'Narrow', 'normal' => 'Normal', 'wide' => 'Wide', 'full' => 'Full-bleed']),
                Select::make('animation')->label('Animation')
                    ->options(['none' => 'None', 'fade' => 'Fade', 'slide-up' => 'Slide up', 'zoom' => 'Zoom']),
                TextInput::make('theme_variant')->label('Theme variant')
                    ->helperText('Optional theme key (e.g. "inverted", "muted").'),
            ]),
            Section::make('Background')->columns(2)->schema([
                ColorPicker::make('background.color')->label('Background color'),
                TextInput::make('background.image')->label('Background image URL')->url(),
                TextInput::make('background.video')->label('Background video URL')->url(),
                TextInput::make('background.overlay')->label('Overlay')
                    ->helperText('Optional overlay color/opacity (e.g. "rgba(0,0,0,.5)").'),
            ]),
            Section::make('Accessibility')->columns(2)->schema([
                TextInput::make('accessibility_label.en')->label('Accessible label (EN)')
                    ->helperText('aria-label for the section landmark.'),
                TextInput::make('accessibility_label.ar')->label('Accessible label (AR)'),
            ]),
        ]);
    }

    // ----- Publishing tab (status, schedule, device visibility) -----

    private static function publishingTab(): Tab
    {
        return Tab::make('Publishing')->icon('heroicon-o-rocket-launch')->schema([
            Section::make('Status & schedule')->columns(2)->schema([
                Toggle::make('is_enabled')->label('Enabled')->inline(false)
                    ->helperText('Disabled blocks are hidden from the public homepage regardless of status.'),
                Select::make('status')->options(HomepageStatus::options())
                    ->default(HomepageStatus::Published->value)->required()
                    ->helperText('Only "Published" blocks inside their schedule window are served publicly.'),
                DateTimePicker::make('published_at')->label('Publish at')->timezone(self::adminTimezone())
                    ->helperText('Leave blank to publish immediately when status is Published.'),
                DateTimePicker::make('unpublished_at')->label('Unpublish at')->timezone(self::adminTimezone())
                    ->helperText('Optional — automatically retires the block after this time.'),
                TextInput::make('position')->numeric()->required()
                    ->helperText('Lower numbers render first. You can also drag rows to reorder.'),
            ]),
            Section::make('Device visibility')->columns(3)->schema([
                Toggle::make('visible_desktop')->label('Desktop')->inline(false)->default(true),
                Toggle::make('visible_tablet')->label('Tablet')->inline(false)->default(true),
                Toggle::make('visible_mobile')->label('Mobile')->inline(false)->default(true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('position')
            ->defaultSort('position')
            ->columns([
                TextColumn::make('key')->label('Block')->badge()->searchable(),
                TextColumn::make('type')->badge()->toggleable()
                    ->formatStateUsing(fn ($state) => $state instanceof BlockType ? $state->label() : $state),
                TextColumn::make('position')->sortable(),
                IconColumn::make('is_enabled')->boolean()->label('Enabled'),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof HomepageStatus ? $state->label() : $state)
                    ->color(fn ($state) => $state === HomepageStatus::Published ? 'success' : 'gray'),
                TextColumn::make('published_at')->dateTime()->placeholder('Draft')->label('Published'),
                TextColumn::make('updated_at')->dateTime()->since()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(HomepageStatus::options()),
                SelectFilter::make('type')->options(BlockType::options()),
            ])
            ->recordActions([
                Action::make('publish')
                    ->label('Publish')
                    ->icon('heroicon-o-rocket-launch')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Publish this block? The current draft becomes the live copy on the public homepage.')
                    ->action(function (HomepageSection $record): void {
                        $record->publish();
                        Notification::make()->title('Block published')->success()->send();
                    }),
                Action::make('toggle')
                    ->label(fn (HomepageSection $record): string => $record->is_enabled ? 'Disable' : 'Enable')
                    ->icon(fn (HomepageSection $record): string => $record->is_enabled ? 'heroicon-o-eye-slash' : 'heroicon-o-eye')
                    ->color('gray')
                    ->action(function (HomepageSection $record): void {
                        $record->toggleEnabled();
                        Notification::make()->title($record->is_enabled ? 'Block enabled' : 'Block disabled')->success()->send();
                    }),
                Action::make('preview')
                    ->label('Preview')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn (): string => rtrim((string) config('shared.frontend_url'), '/').'/?preview=1', shouldOpenInNewTab: true),
            ]);
    }

    public static function getRelations(): array
    {
        return [VersionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListHomepageSections::route('/'),
            'edit' => Pages\EditHomepageSection::route('/{record}/edit'),
        ];
    }
}
