<?php

namespace App\Platform\Branding\Filament\Resources;

use App\Platform\Branding\Filament\Resources\BrandSettingResource\Pages;
use App\Platform\Branding\Models\BrandSetting;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Shared\Filament\Forms\Components\MediaPicker;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * The white-label / branding ADMIN editor — a SINGLETON resource (mirrors CertificateSettingResource:
 * List shows the one seeded record → Edit). Grouped into tabs (Identity, Logos, Theme, Email,
 * Certificate). Fields write into the model's JSON group columns via dot-paths (e.g.
 * theme.colors.primary). Presentation only: standard Eloquent persistence, no business logic. The
 * live public theme/site read this record through GET /api/v1/branding. Gated to admin/super_admin.
 */
class BrandSettingResource extends Resource
{
    protected static ?string $model = BrandSetting::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-swatch';

    protected static string|\UnitEnum|null $navigationGroup = 'Branding';

    protected static ?string $navigationLabel = 'Branding & Theme';

    protected static ?string $recordRouteKeyName = 'public_id';

    /** Singleton: the row is created by the seeder / current(); never created ad hoc in the panel. */
    public static function canCreate(): bool
    {
        return false;
    }

    /** Gate the whole resource to admins (the panel already requires it; defence in depth). */
    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof Actor && $user->hasRole(['admin', 'super_admin']);
    }

    /** @var list<string> The theme colour keys edited by the ColorPickers (light + dark). */
    private const COLOR_KEYS = [
        'primary', 'secondary', 'accent', 'success', 'warning',
        'danger', 'info', 'background', 'surface', 'sidebar', 'header', 'footer',
    ];

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Branding')->columnSpanFull()->tabs([
                self::identityTab(),
                self::logosTab(),
                self::themeTab(),
                self::emailTab(),
                self::certificateTab(),
            ]),
        ]);
    }

    private static function identityTab(): Tab
    {
        return Tab::make('Identity')->icon('heroicon-o-identification')->schema([
            Section::make('Brand & company')->columns(2)->schema([
                TextInput::make('identity.brand_name.en')->label('Brand name (EN)')->maxLength(120),
                TextInput::make('identity.brand_name.ar')->label('Brand name (AR)')->maxLength(120),
                TextInput::make('identity.short_name')->label('Short name')->maxLength(60),
                TextInput::make('identity.company_name')->label('Legal company name')->maxLength(160),
                TextInput::make('identity.copyright.en')->label('Copyright line (EN)')->maxLength(200),
                TextInput::make('identity.copyright.ar')->label('Copyright line (AR)')->maxLength(200),
                TextInput::make('identity.address.en')->label('Address (EN)')->maxLength(200),
                TextInput::make('identity.address.ar')->label('Address (AR)')->maxLength(200),
            ]),
            Section::make('Support & social')->columns(2)->schema([
                TextInput::make('identity.support_email')->label('Support email')->email()->maxLength(160),
                TextInput::make('identity.support_phone')->label('Support phone')->maxLength(60),
                TextInput::make('identity.social_links.twitter')->label('Twitter / X URL')->url()->maxLength(255),
                TextInput::make('identity.social_links.linkedin')->label('LinkedIn URL')->url()->maxLength(255),
                TextInput::make('identity.social_links.facebook')->label('Facebook URL')->url()->maxLength(255),
                TextInput::make('identity.social_links.instagram')->label('Instagram URL')->url()->maxLength(255),
                TextInput::make('identity.social_links.youtube')->label('YouTube URL')->url()->maxLength(255),
            ]),
            Section::make('Localization defaults')->columns(2)->schema([
                Select::make('identity.default_language')->label('Default language')
                    ->options(['en' => 'English', 'ar' => 'العربية']),
                TextInput::make('identity.timezone')->label('Timezone')->maxLength(64)
                    ->helperText('IANA timezone, e.g. Asia/Riyadh.'),
                TextInput::make('identity.currency')->label('Currency')->maxLength(8)
                    ->helperText('ISO 4217 code, e.g. SAR.'),
                TextInput::make('identity.date_format')->label('Date format')->maxLength(32),
                TextInput::make('identity.time_format')->label('Time format')->maxLength(32),
            ]),
        ]);
    }

    private static function logosTab(): Tab
    {
        return Tab::make('Logos')->icon('heroicon-o-photo')->schema([
            Section::make('Logos & icons')->columns(2)
                ->description('Pick each asset from the media library or upload a new image. Existing paths/URLs are kept until replaced.')
                ->schema([
                    self::imagePicker('logos.logo_light', 'Logo (light background)'),
                    self::imagePicker('logos.logo_dark', 'Logo (dark background)'),
                    self::imagePicker('logos.favicon', 'Favicon'),
                    self::imagePicker('logos.apple_icon', 'Apple touch icon'),
                    self::imagePicker('logos.pwa_icon', 'PWA icon'),
                    self::imagePicker('logos.email_logo', 'Email logo'),
                    self::imagePicker('logos.certificate_logo', 'Certificate logo'),
                    self::imagePicker('logos.loader', 'Loading spinner / splash'),
                    self::imagePicker('logos.login_background', 'Login background'),
                ]),
        ]);
    }

    /**
     * A media picker bound to a branding image field. Stores a MediaAsset public_id for new picks and
     * preserves any pre-existing path/URL via the picker's dual-read until it is replaced.
     */
    private static function imagePicker(string $path, string $label): MediaPicker
    {
        return MediaPicker::make($path)
            ->label($label)
            ->purpose('lesson_image')
            ->acceptedTypes(['image'])
            ->allowLegacyUrl()
            ->reusable()
            ->searchable();
    }

    private static function themeTab(): Tab
    {
        return Tab::make('Theme')->icon('heroicon-o-paint-brush')->schema([
            Placeholder::make('theme_preview_note')->label('')
                ->content('These values override the site CSS variables (OKLCH/hex accepted). Leave a colour blank to keep the built-in default. Open the public site to preview live changes.'),
            Section::make('Light colours')->columns(3)->schema(
                array_map(
                    fn (string $key): ColorPicker => ColorPicker::make("theme.colors.{$key}")->label(ucfirst($key)),
                    self::COLOR_KEYS,
                ),
            ),
            Section::make('Dark colours (optional)')->columns(3)->collapsed()->schema(
                array_map(
                    fn (string $key): ColorPicker => ColorPicker::make("theme.dark.{$key}")->label(ucfirst($key)),
                    self::COLOR_KEYS,
                ),
            ),
            Section::make('Shape & typography')->columns(2)->schema([
                TextInput::make('theme.radius')->label('Corner radius')->maxLength(16)
                    ->helperText('CSS length, e.g. 0.75rem.'),
                TextInput::make('theme.container_width')->label('Container width')->maxLength(16)
                    ->helperText('CSS length, e.g. 72rem.'),
                Select::make('theme.shadow_preset')->label('Shadow preset')
                    ->options(['none' => 'None', 'soft' => 'Soft', 'medium' => 'Medium', 'strong' => 'Strong']),
                Select::make('theme.spacing_scale')->label('Spacing scale')
                    ->options(['compact' => 'Compact', 'default' => 'Default', 'comfortable' => 'Comfortable']),
                TextInput::make('theme.font_body')->label('Body font')->maxLength(80),
                TextInput::make('theme.font_heading')->label('Heading font')->maxLength(80),
                TextInput::make('theme.google_font')->label('Google font family')->maxLength(80)
                    ->helperText('Optional. Loads via Google Fonts for body/UI text. Bundled fonts (Inter/Fraunces) are the default.'),
                TextInput::make('theme.preset')->label('Theme preset key')->maxLength(40),
            ]),
        ]);
    }

    private static function emailTab(): Tab
    {
        return Tab::make('Email branding')->icon('heroicon-o-envelope')->schema([
            Section::make('Email header & footer')->columns(2)->schema([
                TextInput::make('email.header.en')->label('Header (EN)')->maxLength(200),
                TextInput::make('email.header.ar')->label('Header (AR)')->maxLength(200),
                TextInput::make('email.footer.en')->label('Footer (EN)')->maxLength(255),
                TextInput::make('email.footer.ar')->label('Footer (AR)')->maxLength(255),
                TextInput::make('email.signature.en')->label('Signature (EN)')->maxLength(160),
                TextInput::make('email.signature.ar')->label('Signature (AR)')->maxLength(160),
            ]),
            Section::make('Email colours')->columns(3)->schema([
                ColorPicker::make('email.colors.background')->label('Background'),
                ColorPicker::make('email.colors.text')->label('Text'),
                ColorPicker::make('email.colors.button')->label('Button'),
            ]),
        ]);
    }

    private static function certificateTab(): Tab
    {
        return Tab::make('Certificate branding')->icon('heroicon-o-academic-cap')->schema([
            Section::make('Certificate assets')->columns(2)->schema([
                self::imagePicker('certificate.background', 'Background image'),
                self::imagePicker('certificate.logo', 'Logo'),
                self::imagePicker('certificate.signature', 'Signature image'),
                self::imagePicker('certificate.stamp', 'Stamp / seal image'),
            ]),
            Section::make('Typography & layout')->columns(2)->schema([
                Select::make('certificate.qr_position')->label('QR position')
                    ->options([
                        'top-left' => 'Top left',
                        'top-right' => 'Top right',
                        'bottom-left' => 'Bottom left',
                        'bottom-right' => 'Bottom right',
                    ]),
                TextInput::make('certificate.font')->label('Font')->maxLength(80),
                ColorPicker::make('certificate.colors.text')->label('Text colour'),
                ColorPicker::make('certificate.colors.accent')->label('Accent colour'),
                TextInput::make('certificate.margins.top')->label('Margin top (px)')->numeric(),
                TextInput::make('certificate.margins.right')->label('Margin right (px)')->numeric(),
                TextInput::make('certificate.margins.bottom')->label('Margin bottom (px)')->numeric(),
                TextInput::make('certificate.margins.left')->label('Margin left (px)')->numeric(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('identity.brand_name.en')->label('Brand')->placeholder('HElbaron'),
            TextColumn::make('theme.preset')->label('Theme preset')->badge()->placeholder('helbaron'),
            TextColumn::make('updated_at')->dateTime()->since()->label('Last updated'),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBrandSetting::route('/'),
            'edit' => Pages\EditBrandSetting::route('/{record}/edit'),
        ];
    }
}
