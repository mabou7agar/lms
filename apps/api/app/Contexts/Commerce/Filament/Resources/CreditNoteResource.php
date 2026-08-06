<?php

namespace App\Contexts\Commerce\Filament\Resources;

use App\Contexts\Commerce\Enums\CommercePermission;
use App\Contexts\Commerce\Enums\CreditNoteStatus;
use App\Contexts\Commerce\Filament\Resources\CreditNoteResource\Pages;
use App\Contexts\Commerce\Models\CreditNote;
use App\Platform\Identity\Contracts\Actor;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Read + reconciliation view over credit notes. Credit notes are engine-generated: the
 * IssueCreditNoteOnRefund listener mints exactly one issued note per fully-refunded order, mirroring
 * the invoice's line snapshot, with a serialized number from CreditNoteNumberService. There is no
 * safe standalone creation path in the engine (only ListCreditNotesAction reads them), and a manual
 * create could mint a duplicate against a refund — so this resource is strictly read-only. Money is
 * integer minor units; the detail view reconciles the line net + tax back to the stored total.
 */
class CreditNoteResource extends Resource
{
    protected static ?string $model = CreditNote::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-minus';

    protected static string|UnitEnum|null $navigationGroup = 'Commerce';

    protected static ?string $navigationLabel = 'Credit notes';

    protected static ?string $recordRouteKeyName = 'public_id';

    /**
     * Server-side authorization on the real commerce.credit-notes.manage permission (super_admin
     * bypasses). finance_manager holds it; support_agent does not.
     */
    public static function canViewAny(): bool
    {
        return self::userCanManage();
    }

    public static function canView(Model $record): bool
    {
        return self::userCanManage();
    }

    /** Credit notes are engine-generated on refund; manual creation could duplicate a note, so it is disabled. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function userCanManage(): bool
    {
        $user = Filament::auth()->user() ?? auth()->user();

        if (! $user instanceof Actor) {
            return false;
        }

        return $user->hasRole('super_admin') || $user->can(CommercePermission::ManageCreditNotes->value);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Credit note')->columns(2)->schema([
                TextEntry::make('number')->label('Number'),
                TextEntry::make('status')->badge(),
                TextEntry::make('order.public_id')->label('Order'),
                TextEntry::make('refund.public_id')->label('Linked refund')->default('—'),
                TextEntry::make('order.invoice.number')->label('Linked invoice')->default('—'),
                TextEntry::make('currency'),
                TextEntry::make('total_minor')->label('Total (minor units)')->numeric(),
                TextEntry::make('issued_at')->dateTime(),
            ]),
            Section::make('Lines')->schema([
                RepeatableEntry::make('lines')->schema([
                    TextEntry::make('description'),
                    TextEntry::make('amount_minor')->label('Net (minor)')->numeric(),
                    TextEntry::make('tax_minor')->label('Tax (minor)')->numeric(),
                ])->columns(3),
            ]),
            Section::make('Reconciliation')->columns(2)->schema([
                TextEntry::make('lines_net_total')
                    ->label('Sum of line net (minor)')
                    ->state(fn (CreditNote $record): int => (int) $record->lines()->sum('amount_minor')),
                TextEntry::make('lines_tax_total')
                    ->label('Sum of line tax (minor)')
                    ->state(fn (CreditNote $record): int => (int) $record->lines()->sum('tax_minor')),
                TextEntry::make('lines_grand_total')
                    ->label('Net + tax (minor)')
                    ->state(fn (CreditNote $record): int => (int) $record->lines()->sum('amount_minor') + (int) $record->lines()->sum('tax_minor')),
                TextEntry::make('reconciles')
                    ->label('Reconciles to total')
                    ->state(fn (CreditNote $record): string => (((int) $record->lines()->sum('amount_minor') + (int) $record->lines()->sum('tax_minor')) === $record->totalMinor())
                        ? 'Balanced'
                        : 'Mismatch'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['order', 'refund', 'lines']))
            ->columns([
                TextColumn::make('number')->searchable()->sortable(),
                TextColumn::make('order.public_id')->label('Order')->searchable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('total_minor')->label('Total (minor)')->numeric()->sortable(),
                TextColumn::make('currency')->toggleable(),
                TextColumn::make('issued_at')->dateTime()->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(CreditNoteStatus::cases())
                    ->mapWithKeys(fn (CreditNoteStatus $s): array => [$s->value => ucfirst($s->value)])
                    ->all()),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCreditNote::route('/'),
            'view' => Pages\ViewCreditNote::route('/{record}'),
        ];
    }
}
