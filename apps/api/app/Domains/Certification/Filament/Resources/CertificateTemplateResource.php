<?php

namespace App\Domains\Certification\Filament\Resources;

use App\Domains\Certification\Filament\Resources\CertificateTemplateResource\Pages;
use App\Domains\Certification\Models\CertificateTemplate;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

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
            Section::make('Content')->columns(2)->schema([
                TextInput::make('name_i18n.en')->label('Name (EN)')->required()
                    ->helperText('English is the default and fallback locale.'),
                TextInput::make('name_i18n.ar')->label('Name (AR)')
                    ->extraInputAttributes(['dir' => 'rtl']),
                RichEditor::make('html_i18n.en')->label('HTML (EN)')->required()->columnSpanFull()
                    ->helperText('English is the default and fallback locale.'),
                RichEditor::make('html_i18n.ar')->label('HTML (AR)')->columnSpanFull()
                    ->extraInputAttributes(['dir' => 'rtl']),
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
            IconColumn::make('is_active')->boolean(),
        ])->defaultSort('id', 'desc');
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
