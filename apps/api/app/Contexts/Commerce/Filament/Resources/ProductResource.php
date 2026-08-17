<?php

namespace App\Contexts\Commerce\Filament\Resources;

use App\Contexts\Commerce\Enums\AccessDurationType;
use App\Contexts\Commerce\Enums\CertificateExpiryType;
use App\Contexts\Commerce\Enums\CertificateRefundPolicy;
use App\Contexts\Commerce\Enums\CompanyCertificateBranding;
use App\Contexts\Commerce\Enums\PricingBasis;
use App\Contexts\Commerce\Enums\ProductAudience;
use App\Contexts\Commerce\Enums\ProductStatus;
use App\Contexts\Commerce\Enums\ProductType;
use App\Contexts\Commerce\Enums\RefundAccessPolicy;
use App\Contexts\Commerce\Enums\ReminderChannel;
use App\Contexts\Commerce\Enums\SeatMode;
use App\Contexts\Commerce\Enums\SeatReassignmentPolicy;
use App\Contexts\Commerce\Filament\Resources\ProductResource\Pages;
use App\Contexts\Commerce\Models\Product;
use App\Platform\Shared\Filament\Forms\Components\MediaPicker;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The commercial control surface. A product is the only thing that can be bought: either a single
 * course or a bundle of them. Everything that decides what a buyer gets — for how long, with what
 * certificate, on what refund terms, and (for companies) how many seats on what reassignment rules —
 * is configured here rather than hardcoded, so commercial policy can change without a deploy.
 */
class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shopping-bag';

    protected static string|\UnitEnum|null $navigationGroup = 'Commerce';

    protected static ?string $recordRouteKeyName = 'public_id';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Content')->columns(2)->schema([
                TextInput::make('title_i18n.en')->label('Title (EN)')->required()->maxLength(255)
                    ->helperText('English is the default and fallback locale.'),
                TextInput::make('title_i18n.ar')->label('Title (AR)')->maxLength(255)
                    ->extraInputAttributes(['dir' => 'rtl']),
                Textarea::make('description_i18n.en')->label('Description (EN)')->rows(3)->columnSpanFull()
                    ->helperText('English is the default and fallback locale.'),
                Textarea::make('description_i18n.ar')->label('Description (AR)')->rows(3)->columnSpanFull()
                    ->extraInputAttributes(['dir' => 'rtl']),
                MediaPicker::make('image_path')
                    ->label('Product image')
                    ->purpose('lesson_image')
                    ->acceptedTypes(['image'])
                    ->allowLegacyUrl()
                    ->searchable()
                    ->imageAspectRatios(['16:9'])
                    ->columnSpanFull()
                    ->helperText('Shown on the sales page. Crop to the 16:9 card frame.'),
            ]),

            Section::make('What is sold')->columns(2)->schema([
                Select::make('type')
                    ->label('Product kind')
                    ->options(collect(ProductType::cases())->mapWithKeys(fn (ProductType $t) => [
                        $t->value => $t === ProductType::Course ? 'Single course' : 'Bundle of courses',
                    ])->all())
                    ->required()
                    ->native(false)
                    ->default(ProductType::Course->value)
                    ->live()
                    ->helperText('A bundle grants every course listed below in one purchase.'),
                Select::make('status')
                    ->options(collect(ProductStatus::cases())->mapWithKeys(fn (ProductStatus $s) => [$s->value => ucfirst($s->value)])->all())
                    ->required()
                    ->native(false)
                    ->default(ProductStatus::Draft->value)
                    ->helperText('Only Active products can be bought or shown as purchasable.'),
                Select::make('courses')
                    ->label('Courses granted')
                    // Select only the two columns the picker needs. Filament issues SELECT DISTINCT
                    // for a BelongsToMany option query, and Postgres cannot compare the `json`
                    // columns on `courses` (no equality operator for that type), so `courses.*`
                    // fails outright.
                    ->relationship(
                        'courses',
                        'title',
                        fn (Builder $query): Builder => $query->select(['courses.id', 'courses.title']),
                    )
                    ->multiple()
                    ->preload()
                    ->searchable()
                    ->required()
                    ->columnSpanFull()
                    ->helperText('Everything the buyer gets access to. A single-course product lists exactly one.'),
                Select::make('audience')
                    ->label('Who can buy this')
                    ->options(ProductAudience::options())
                    ->required()
                    ->native(false)
                    ->default(ProductAudience::Individual->value)
                    ->live()
                    ->columnSpanFull()
                    ->helperText('Companies buy seats their manager distributes; individuals are enrolled directly.'),
            ]),

            Section::make('Pricing')->schema([
                Repeater::make('prices')
                    ->relationship()
                    ->label('Price per currency')
                    ->columns(3)
                    ->defaultItems(1)
                    ->minItems(1)
                    ->schema([
                        TextInput::make('currency')->required()->maxLength(3)
                            ->default('SAR')
                            ->helperText('ISO 4217, e.g. SAR.'),
                        TextInput::make('amount_minor')->label('Price (minor units)')->numeric()->required()->minValue(0)
                            ->helperText('In the smallest unit — 199.00 is 19900.'),
                        TextInput::make('sale_amount_minor')->label('Sale price (minor units)')->numeric()->minValue(0)
                            ->helperText('Leave empty when not on sale.'),
                        DateTimePicker::make('sale_starts_at')->label('Sale starts')
                            ->helperText('Empty means the sale is already running.'),
                        DateTimePicker::make('sale_ends_at')->label('Sale ends')
                            ->helperText('Empty means the sale never ends.'),
                        Toggle::make('is_default')->label('Default currency')->inline(false)
                            ->helperText('Used when the buyer has no currency preference.'),
                    ])
                    ->helperText('A product with no price cannot be sold.'),
            ]),

            Section::make('Access')->columns(2)->schema([
                Select::make('access_duration_type')
                    ->label('How long access lasts')
                    ->options(AccessDurationType::options())
                    ->required()
                    ->native(false)
                    ->default(AccessDurationType::Lifetime->value)
                    ->live()
                    ->helperText('Counted from the purchase date unless a specific end date is chosen.'),
                TextInput::make('access_duration_value')
                    ->label('Duration')
                    ->numeric()
                    ->minValue(1)
                    ->visible(fn ($get): bool => AccessDurationType::tryFrom((string) $get('access_duration_type'))?->needsValue() ?? false)
                    ->required(fn ($get): bool => AccessDurationType::tryFrom((string) $get('access_duration_type'))?->needsValue() ?? false)
                    ->helperText('How many days / months / years, matching the choice on the left.'),
                DateTimePicker::make('access_ends_at')
                    ->label('Access ends on')
                    ->visible(fn ($get): bool => AccessDurationType::tryFrom((string) $get('access_duration_type'))?->needsDate() ?? false)
                    ->required(fn ($get): bool => AccessDurationType::tryFrom((string) $get('access_duration_type'))?->needsDate() ?? false)
                    ->helperText('The same calendar date for every buyer — use for a dated cohort.'),
            ]),

            Section::make('Certificate')->columns(2)->schema([
                Toggle::make('certificate_enabled')
                    ->label('Issue a certificate on completion')
                    ->default(true)
                    ->live()
                    ->columnSpanFull(),
                Select::make('certificate_expiry_type')
                    ->label('Certificate validity')
                    ->options(CertificateExpiryType::options())
                    ->required()
                    ->native(false)
                    ->default(CertificateExpiryType::None->value)
                    ->live()
                    ->visible(fn ($get): bool => (bool) $get('certificate_enabled'))
                    ->helperText('Separate from access: a learner can keep a valid certificate after access ends.'),
                TextInput::make('certificate_expiry_value')
                    ->label('Valid for')
                    ->numeric()
                    ->minValue(1)
                    ->visible(fn ($get): bool => (bool) $get('certificate_enabled')
                        && (CertificateExpiryType::tryFrom((string) $get('certificate_expiry_type'))?->needsValue() ?? false))
                    ->required(fn ($get): bool => CertificateExpiryType::tryFrom((string) $get('certificate_expiry_type'))?->needsValue() ?? false),
                DateTimePicker::make('certificate_expires_at')
                    ->label('Certificates expire on')
                    ->visible(fn ($get): bool => (bool) $get('certificate_enabled')
                        && (CertificateExpiryType::tryFrom((string) $get('certificate_expiry_type'))?->needsDate() ?? false))
                    ->required(fn ($get): bool => CertificateExpiryType::tryFrom((string) $get('certificate_expiry_type'))?->needsDate() ?? false),
            ]),

            Section::make('Expiry reminders')->columns(2)->schema([
                TagsInput::make('reminder_offsets_days')
                    ->label('Remind this many days before expiry')
                    ->placeholder('30')
                    ->helperText('Add one entry per reminder, e.g. 30, 7, 1. Whole days. Leave empty for no reminders.'),
                CheckboxList::make('reminder_channels')
                    ->label('Deliver reminders through')
                    ->options(ReminderChannel::options())
                    ->helperText('Applies to course access, bundle access and certificate expiry.'),
            ]),

            Section::make('Refunds')->columns(2)->schema([
                Select::make('refund_access_policy')
                    ->label('When a purchase is refunded, access')
                    ->options(RefundAccessPolicy::options())
                    ->required()
                    ->native(false)
                    ->default(RefundAccessPolicy::RevokeImmediately->value),
                Select::make('certificate_refund_policy')
                    ->label('…and an already-earned certificate')
                    ->options(CertificateRefundPolicy::options())
                    ->required()
                    ->native(false)
                    ->default(CertificateRefundPolicy::Revoke->value)
                    ->helperText('Revoking makes public verification fail for a credential the learner already earned.'),
            ]),

            // Only meaningful once companies can buy this product.
            Section::make('Company purchases')
                ->columns(2)
                ->visible(fn ($get): bool => ProductAudience::tryFrom((string) $get('audience'))?->allowsCompany() ?? false)
                ->schema([
                    Select::make('seat_mode')
                        ->label('Seats included')
                        ->options(SeatMode::options())
                        ->required()
                        ->native(false)
                        ->default(SeatMode::NotApplicable->value)
                        ->live(),
                    TextInput::make('default_seat_count')
                        ->label(fn ($get): string => (SeatMode::tryFrom((string) $get('seat_mode'))?->buyerChoosesSeats() ?? false)
                            ? 'Seat count the buy box opens on'
                            : 'Number of seats')
                        ->numeric()
                        ->minValue(1)
                        ->visible(fn ($get): bool => ($m = SeatMode::tryFrom((string) $get('seat_mode'))) !== null
                            && ($m->needsSeatCount() || $m->buyerChoosesSeats()))
                        ->required(fn ($get): bool => SeatMode::tryFrom((string) $get('seat_mode'))?->needsSeatCount() ?? false)
                        ->helperText(fn ($get): ?string => (SeatMode::tryFrom((string) $get('seat_mode'))?->buyerChoosesSeats() ?? false)
                            ? 'Snapped to the minimum and step below, so the buyer starts on a count you actually sell.'
                            : null),
                    Select::make('pricing_basis')
                        ->label('The price above buys')
                        ->options(PricingBasis::options())
                        ->required()
                        ->native(false)
                        ->default(PricingBasis::FixedBundlePrice->value)
                        ->helperText('Per seat multiplies the listed price by the seat count on the order.'),
                    TextInput::make('min_seats')
                        ->label('Minimum seats')
                        ->numeric()
                        ->minValue(1)
                        ->visible(fn ($get): bool => SeatMode::tryFrom((string) $get('seat_mode'))?->buyerChoosesSeats() ?? false)
                        ->helperText('Leave empty for a minimum of one.'),
                    TextInput::make('max_seats')
                        ->label('Maximum seats')
                        ->numeric()
                        ->minValue(1)
                        ->visible(fn ($get): bool => SeatMode::tryFrom((string) $get('seat_mode'))?->buyerChoosesSeats() ?? false)
                        ->helperText('Leave empty for no ceiling. A company wanting more than this asks sales.'),
                    TextInput::make('seat_increment')
                        ->label('Sold in steps of')
                        ->numeric()
                        ->minValue(1)
                        ->default(1)
                        ->visible(fn ($get): bool => SeatMode::tryFrom((string) $get('seat_mode'))?->buyerChoosesSeats() ?? false)
                        ->helperText('1 lets a buyer pick any number; 5 sells packs of five.'),
                    Select::make('seat_reassignment_policy')
                        ->label('A company may move a seat to another employee')
                        ->options(SeatReassignmentPolicy::options())
                        ->required()
                        ->native(false)
                        ->default(SeatReassignmentPolicy::BeforeStart->value)
                        ->live()
                        ->helperText('Reclaiming a started seat discards that employee’s progress.'),
                    TextInput::make('reassignment_progress_threshold')
                        ->label('Progress threshold (%)')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(100)
                        ->visible(fn ($get): bool => SeatReassignmentPolicy::tryFrom((string) $get('seat_reassignment_policy'))?->needsThreshold() ?? false)
                        ->required(fn ($get): bool => SeatReassignmentPolicy::tryFrom((string) $get('seat_reassignment_policy'))?->needsThreshold() ?? false),
                    Select::make('company_certificate_branding')
                        ->label('Certificates earned through a company purchase show')
                        ->options(CompanyCertificateBranding::options())
                        ->required()
                        ->native(false)
                        ->default(CompanyCertificateBranding::HelbaronOnly->value),
                    Toggle::make('employee_access_expires_with_purchase')
                        ->label('Employee access ends when the company purchase expires')
                        ->default(true)
                        ->inline(false)
                        ->helperText('Turning this off leaves employees with access after the company licence lapses.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->searchable()->sortable(),
            TextColumn::make('type')->badge(),
            TextColumn::make('audience')->badge()
                ->formatStateUsing(fn (ProductAudience $state): string => $state->label()),
            TextColumn::make('courses_count')->counts('courses')->label('Courses'),
            IconColumn::make('certificate_enabled')->boolean()->label('Certificate'),
            TextColumn::make('status')->badge(),
        ])
            ->filters([
                SelectFilter::make('type')->options(collect(ProductType::cases())->mapWithKeys(fn (ProductType $t) => [$t->value => ucfirst($t->value)])->all()),
                SelectFilter::make('audience')->options(ProductAudience::options()),
                SelectFilter::make('status')->options(collect(ProductStatus::cases())->mapWithKeys(fn (ProductStatus $s) => [$s->value => ucfirst($s->value)])->all()),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProduct::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
