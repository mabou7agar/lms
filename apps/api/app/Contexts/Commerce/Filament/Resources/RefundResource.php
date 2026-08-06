<?php

namespace App\Contexts\Commerce\Filament\Resources;

use App\Contexts\Commerce\Enums\CommercePermission;
use App\Contexts\Commerce\Enums\RefundStatus;
use App\Contexts\Commerce\Filament\Resources\RefundResource\Pages;
use App\Contexts\Commerce\Models\CreditNote;
use App\Contexts\Commerce\Models\Order;
use App\Contexts\Commerce\Models\Refund;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Shared\Audit\AuditLog;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
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
 * Read + reconciliation view over the refund ledger. Refunds are engine-generated (RefundOrderAction
 * settles them against the gateway); this resource never edits a refund's status or amount. The one
 * write path — "Issue refund" on the list page — delegates wholesale to IssueRefundAction, whose
 * domain guards (amount <= refundable balance, idempotency, no retry of a succeeded refund) are the
 * single source of truth; their failures surface here only as a Notification. Money is integer minor
 * units. Gateway reference tokens are masked, never rendered raw.
 */
class RefundResource extends Resource
{
    protected static ?string $model = Refund::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-uturn-left';

    protected static string|UnitEnum|null $navigationGroup = 'Commerce';

    protected static ?string $navigationLabel = 'Refunds';

    protected static ?string $recordRouteKeyName = 'public_id';

    /**
     * Server-side authorization: only a super_admin or a staff member holding the real
     * commerce.refunds.manage permission may reach the ledger. This is the finance/support boundary
     * from Sprint 0.1 — finance_manager carries ManageRefunds, support_agent does not.
     */
    public static function canViewAny(): bool
    {
        return self::userCanManage();
    }

    public static function canView(Model $record): bool
    {
        return self::userCanManage();
    }

    /** Refunds are never hand-authored; the only creation path is the delegating "Issue refund" action. */
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

        return $user->hasRole('super_admin') || $user->can(CommercePermission::ManageRefunds->value);
    }

    /**
     * Remaining refundable balance for an order, in integer minor units. Mirrors the authoritative
     * formula inside RefundOrderAction (paid total minus the sum of the order's non-failed —
     * pending + succeeded — refunds), so an in-flight refund already reserves its capacity. This is
     * an advisory display value; the domain action re-derives it under a lock and is the real guard.
     */
    public static function remainingRefundableMinor(Order $order): int
    {
        $reserved = (int) $order->refunds()
            ->whereIn('status', [RefundStatus::Pending->value, RefundStatus::Succeeded->value])
            ->sum('amount_minor');

        return max(0, (int) $order->getAttribute('total_minor') - $reserved);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Refund')->columns(2)->schema([
                TextEntry::make('public_id')->label('Refund ID'),
                TextEntry::make('order.public_id')->label('Order'),
                TextEntry::make('status')->badge(),
                TextEntry::make('reason')->badge()->default('—'),
                TextEntry::make('amount_minor')->label('Refunded (minor units)')->numeric(),
                TextEntry::make('refundable_remaining')
                    ->label('Refundable remaining (minor units)')
                    ->state(fn (Refund $record): int => $record->order !== null
                        ? self::remainingRefundableMinor($record->order)
                        : 0),
                TextEntry::make('currency'),
            ]),
            Section::make('Gateway & linkage')->columns(2)->schema([
                TextEntry::make('gateway_result')
                    ->label('Gateway result')
                    ->state(fn (Refund $record): string => ucfirst($record->statusEnum()->value)),
                TextEntry::make('failure_state')
                    ->label('Failure state')
                    ->state(fn (Refund $record): string => $record->statusEnum() === RefundStatus::Failed ? 'Failed' : 'None'),
                TextEntry::make('gateway_reference')
                    ->label('Gateway reference (masked)')
                    ->state(fn (Refund $record): string => self::maskReference((string) ($record->getAttribute('provider_reference') ?? ''))),
                TextEntry::make('transaction.public_id')->label('Payment transaction')->default('—'),
                TextEntry::make('credit_note')
                    ->label('Linked credit note')
                    ->state(fn (Refund $record): string => (string) (CreditNote::query()
                        ->where('refund_id', $record->getKey())
                        ->value('number') ?? '—')),
            ]),
            Section::make('Timeline & audit')->columns(2)->schema([
                TextEntry::make('created_at')->label('Created')->dateTime(),
                TextEntry::make('processed_at')->label('Processed')->dateTime(),
                TextEntry::make('audit_trail')
                    ->label('Audit trail')
                    ->columnSpanFull()
                    ->listWithLineBreaks()
                    ->state(fn (Refund $record): array => self::auditTrail($record)),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['order', 'transaction']))
            ->columns([
                TextColumn::make('public_id')->label('Refund')->searchable(),
                TextColumn::make('order.public_id')->label('Order')->searchable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('amount_minor')->label('Amount (minor)')->numeric()->sortable(),
                TextColumn::make('currency')->toggleable(),
                TextColumn::make('reason')->badge()->toggleable(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(RefundStatus::cases())
                    ->mapWithKeys(fn (RefundStatus $s): array => [$s->value => ucfirst($s->value)])
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
            'index' => Pages\ListRefund::route('/'),
            'view' => Pages\ViewRefund::route('/{record}'),
        ];
    }

    /** Redact a gateway reference/token: never surface the raw value, only a last-4 fingerprint. */
    private static function maskReference(string $reference): string
    {
        if ($reference === '') {
            return '—';
        }

        return '••••'.substr($reference, -4);
    }

    /**
     * @return array<int, string>
     */
    private static function auditTrail(Refund $record): array
    {
        $entries = AuditLog::query()
            ->where('subject_type', Refund::class)
            ->where('subject_id', $record->getKey())
            ->orderBy('id')
            ->get(['action', 'created_at'])
            ->map(fn (AuditLog $log): string => sprintf(
                '%s — %s',
                (string) $log->getAttribute('action'),
                optional($log->getAttribute('created_at'))->toDateTimeString() ?? '',
            ))
            ->all();

        return $entries === [] ? ['No audit entries.'] : $entries;
    }
}
