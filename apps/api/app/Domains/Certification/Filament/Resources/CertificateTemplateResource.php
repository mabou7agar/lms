<?php

namespace App\Domains\Certification\Filament\Resources;

use App\Domains\Certification\Filament\Resources\CertificateTemplateResource\Pages;
use App\Domains\Certification\Models\CertificateTemplate;
use App\Domains\Certification\Services\CertificateVariableRenderer;
use App\Platform\Shared\Filament\Forms\Components\MediaPicker;
use Filament\Actions\Action;
use Filament\Actions\ReplicateAction;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/**
 * Certificate DESIGNER. A constrained, safe editing surface:
 *   • EN/AR rich-text bodies (html_i18n) are sanitized through the shared HtmlSanitizer on save
 *     (via HasTranslations) — a designer can never persist <script>/onerror/js: markup.
 *   • Layout imagery (background, company logo, signature images) is chosen through the MediaPicker
 *     and injected at render time as TRUSTED server-generated <img> markup — the "narrow,
 *     allowlisted styling channel" that keeps the global HTML sanitizer closed.
 *   • PREVIEW renders SAMPLE data through the exact same allowlist renderer used at issuance.
 *   • REPLICATE duplicates a template (new version, inactive) as a safe starting point.
 */
class CertificateTemplateResource extends Resource
{
    protected static ?string $model = CertificateTemplate::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-check';

    protected static string|\UnitEnum|null $navigationGroup = 'Certification';

    protected static ?string $recordRouteKeyName = 'public_id';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('key')->required(),
            TextInput::make('version')->numeric()->default(1),
            Select::make('orientation')
                ->options(['landscape' => 'Landscape', 'portrait' => 'Portrait'])
                ->default('landscape')
                ->required()
                ->helperText('Page orientation used when rendering the PDF.'),
            Section::make('Content')->columns(2)->schema([
                TextInput::make('name_i18n.en')->label('Name (EN)')->required()
                    ->helperText('English is the default and fallback locale.'),
                TextInput::make('name_i18n.ar')->label('Name (AR)')
                    ->extraInputAttributes(['dir' => 'rtl']),
                RichEditor::make('html_i18n.en')->label('HTML (EN)')->required()->columnSpanFull()
                    ->helperText('English is the default and fallback locale. Use {{ holder_name }}, {{ course_title }}, {{ number }}, {{ verify_url }}, {{ qr_code }}, {{ company_logo }}, {{ signature_image }}, {{ score }}, {{ instructor_names }} …'),
                RichEditor::make('html_i18n.ar')->label('HTML (AR)')->columnSpanFull()
                    ->extraInputAttributes(['dir' => 'rtl']),
            ]),
            Section::make('Design assets')->columns(2)->schema([
                MediaPicker::make('design.background_image')->label('Background image')
                    ->purpose('lesson_image')->acceptedTypes(['image'])->allowLegacyUrl()->reusable()->searchable(),
                MediaPicker::make('design.company_logo')->label('Company logo')
                    ->purpose('lesson_image')->acceptedTypes(['image'])->allowLegacyUrl()->reusable()->searchable(),
                MediaPicker::make('design.signature_image')->label('Primary signature image')
                    ->purpose('lesson_image')->acceptedTypes(['image'])->allowLegacyUrl()->reusable()->searchable(),
            ]),
            Section::make('Additional signatory')->columns(2)->schema([
                TextInput::make('design.signature_2_name')->label('Second signature name')->maxLength(255),
                TextInput::make('design.signature_2_title')->label('Second signature title')->maxLength(255),
                MediaPicker::make('design.signature_2_image')->label('Second signature image')->columnSpanFull()
                    ->purpose('lesson_image')->acceptedTypes(['image'])->allowLegacyUrl()->reusable()->searchable(),
            ]),
            Toggle::make('is_active'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable(),
            TextColumn::make('key'),
            TextColumn::make('version'),
            TextColumn::make('orientation')->badge()->toggleable(),
            IconColumn::make('is_active')->boolean(),
        ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                self::previewAction(),
                ReplicateAction::make()
                    ->excludeAttributes(['public_id', 'is_active'])
                    ->beforeReplicaSaved(function (CertificateTemplate $replica): void {
                        $replica->version = (int) CertificateTemplate::where('key', $replica->key)->max('version') + 1;
                        $replica->is_active = false;
                    }),
            ]);
    }

    /**
     * Read-only preview that renders the template body with SAMPLE values through the same
     * constrained allowlist renderer used at issuance. Shows EN, plus AR (dir=rtl) when present.
     */
    public static function previewAction(): Action
    {
        return Action::make('preview')
            ->label('Preview')
            ->icon('heroicon-o-eye')
            ->color('gray')
            ->modalHeading('Certificate preview (sample data)')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalContent(fn (CertificateTemplate $record): HtmlString => self::previewHtml($record));
    }

    private static function previewHtml(CertificateTemplate $record): HtmlString
    {
        $renderer = app(CertificateVariableRenderer::class);
        /** @var array<string, mixed> $design */
        $design = is_array($record->design) ? $record->design : [];

        /** @var array<string, string> $map */
        $map = is_array($record->html_i18n) && $record->html_i18n !== []
            ? $record->html_i18n
            : ['en' => (string) $record->html];

        $sections = [];

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $dir) {
            $body = $map[$locale] ?? null;

            if (! is_string($body) || $body === '') {
                continue;
            }

            $rendered = $renderer->renderSample($body, $design);
            $sections[] = '<div dir="'.$dir.'" style="border:1px solid #e5e7eb;padding:1rem;margin-bottom:1rem;">'
                .'<div style="font-size:0.75rem;color:#6b7280;margin-bottom:0.5rem;">'.strtoupper($locale).'</div>'
                .$rendered.'</div>';
        }

        return new HtmlString('<div>'.implode('', $sections).'</div>');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCertificateTemplate::route('/'),
            'create' => Pages\CreateCertificateTemplate::route('/create'),
            'edit' => Pages\EditCertificateTemplate::route('/{record}/edit'),
        ];
    }
}
