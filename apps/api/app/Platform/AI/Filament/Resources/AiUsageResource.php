<?php

namespace App\Platform\AI\Filament\Resources;

use App\Platform\AI\Enums\AiFeature;
use App\Platform\AI\Enums\AiProvider;
use App\Platform\AI\Filament\Resources\AiUsageResource\Pages;
use App\Platform\AI\Models\AiUsage;
use App\Platform\Identity\Contracts\Actor;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * Read-only AI usage / spend ledger. Shows metered calls with tokens and estimated cost so admins
 * can see where AI budget is going. Never shows API keys or secrets — only provider/model
 * identifiers. Admin/super-admin gated. No create/edit: rows are append-only facts.
 */
class AiUsageResource extends Resource
{
    protected static ?string $model = AiUsage::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-currency-dollar';

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'AI Usage';

    protected static ?string $recordRouteKeyName = 'public_id';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof Actor && $user->hasRole(['admin', 'super_admin']);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->dateTime()->since()->sortable(),
                TextColumn::make('feature')->badge()->sortable(),
                TextColumn::make('provider')->badge()->sortable(),
                TextColumn::make('model')->toggleable(),
                TextColumn::make('organization_id')->label('Org')->placeholder('—')->toggleable(),
                TextColumn::make('user_id')->label('User')->placeholder('—')->toggleable(),
                TextColumn::make('input_tokens')->label('In')->numeric()->toggleable(),
                TextColumn::make('output_tokens')->label('Out')->numeric()->toggleable(),
                TextColumn::make('estimated_cost_micros')->label('Est. cost')
                    ->formatStateUsing(fn (int $state): string => '$'.number_format($state / 1_000_000, 4)),
                TextColumn::make('prompt_key')->label('Prompt')->placeholder('—')
                    ->formatStateUsing(fn (?string $state, AiUsage $record): string => $state !== null
                        ? $state.' v'.(string) $record->prompt_version
                        : '—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('feature')->options(self::enumOptions(AiFeature::cases())),
                SelectFilter::make('provider')->options(self::enumOptions(AiProvider::cases())),
            ]);
    }

    /**
     * @param  array<int, AiFeature|AiProvider>  $cases
     * @return array<string, string>
     */
    private static function enumOptions(array $cases): array
    {
        $options = [];

        foreach ($cases as $case) {
            $options[$case->value] = ucfirst($case->value);
        }

        return $options;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAiUsage::route('/'),
        ];
    }
}
