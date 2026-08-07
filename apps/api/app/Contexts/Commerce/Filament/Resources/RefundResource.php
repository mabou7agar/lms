<?php

namespace App\Contexts\Commerce\Filament\Resources;

use App\Contexts\Commerce\Actions\Refund\IssueRefundAction;
use App\Contexts\Commerce\Enums\CommercePermission;
use App\Contexts\Commerce\Enums\OrderStatus;
use App\Contexts\Commerce\Enums\RefundReason;
use App\Contexts\Commerce\Enums\RefundStatus;
use App\Contexts\Commerce\Filament\Resources\RefundResource\Pages;
use App\Contexts\Commerce\Models\CreditNote;
use App\Contexts\Commerce\Models\Order;
use App\Contexts\Commerce\Models\Refund;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Shared\Audit\AuditLog;
use App\Platform\Shared\Audit\AuditLogger;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Throwable;
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
                TextEntry::make('customer_name')->label('Customer')
                    ->state(fn (Refund $record): string => self::customerName($record)),
                TextEntry::make('customer_email')->label('Customer email')
                    ->state(fn (Refund $record): string => self::customerEmail($record)),
                TextEntry::make('status')->badge(),
                TextEntry::make('reason')->badge()->default('—'),
                TextEntry::make('captured_total')
                    ->label('Captured / paid total (minor units)')
                    ->numeric()
                    ->state(fn (Refund $record): int => $record->order !== null
                        ? (int) $record->order->getAttribute('total_minor')
                        : 0),
                TextEntry::make('amount_minor')->label('Refunded (minor units)')->numeric(),
                TextEntry::make('refundable_remaining')
                    ->label('Refundable remaining (minor units)')
                    ->state(fn (Refund $record): int => $record->order !== null
                        ? self::remainingRefundableMinor($record->order)
                        : 0),
                TextEntry::make('currency'),
                TextEntry::make('entitlement_effect')
                    ->label('Entitlement effect')
                    ->columnSpanFull()
                    ->state(fn (Refund $record): string => self::entitlementEffect($record)),
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
            // Eager-load order + its purchasing user (customer column) and the transaction so the
            // list never issues a per-row query (N+1 guard).
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['order.user', 'transaction']))
            ->columns([
                TextColumn::make('public_id')->label('Refund')->searchable(),
                TextColumn::make('order.public_id')->label('Order')->searchable(),
                TextColumn::make('order.user.email')->label('Customer')->toggleable()->searchable(),
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
                self::retryAction(),
            ])
            ->defaultSort('id', 'desc');
    }

    /**
     * R4 — retry a FAILED refund as a NEW attempt. Financial immutability means a failed refund row is
     * frozen forever, so this never mutates it: it re-invokes IssueRefundAction against the same order
     * for the failed amount, which under RefundOrderAction's lock re-derives the remaining refundable
     * balance (paid total minus non-failed refunds) and creates a fresh pending → settled refund. That
     * makes it inherently idempotent and over-refund-safe — a concurrent double-retry cannot exceed the
     * balance, and a credit note is minted at most once per order by IssueCreditNoteOnRefund. Visible
     * ONLY for a failed refund; requires the finance permission (support cannot); confirmed; audited.
     */
    public static function retryAction(): Action
    {
        return Action::make('retryRefund')
            ->label('Retry as new attempt')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Retry refund as a new attempt')
            ->modalDescription('The failed refund is immutable and is kept as-is. This issues a NEW refund for the same amount against the order; the engine re-checks the refundable balance under a lock, so it cannot over-refund or duplicate a credit note.')
            ->modalSubmitActionLabel('Retry refund')
            // Only a genuinely failed refund is retryable — never a succeeded or pending (in-flight) one.
            ->visible(fn (Refund $record): bool => self::userCanManage()
                && $record->statusEnum() === RefundStatus::Failed)
            ->action(function (Refund $record): void {
                $order = $record->order;

                if (! $order instanceof Order) {
                    Notification::make()->title('Retry failed')->body('The refund has no order to retry against.')->danger()->send();

                    return;
                }

                if (self::orderStatusOf($order) !== OrderStatus::Paid) {
                    Notification::make()
                        ->title('Retry rejected')
                        ->body('The order is no longer in a refundable (paid) state.')
                        ->danger()
                        ->send();

                    return;
                }

                $reason = $record->reasonEnum() ?? RefundReason::RequestedByCustomer;

                try {
                    $new = app(IssueRefundAction::class)->execute($order, $record->amountMinor(), $reason);

                    app(AuditLogger::class)->log('commerce.refund.retry_attempted', $new, [
                        'retried_refund' => (string) $record->getAttribute('public_id'),
                        'order_id' => (string) $order->getAttribute('public_id'),
                        'amount_minor' => $record->amountMinor(),
                    ]);

                    Notification::make()
                        ->title('Retry issued')
                        ->body(sprintf('New refund %s issued for %d %s.', (string) $new->getAttribute('public_id'), $new->amountMinor(), (string) $order->getAttribute('currency')))
                        ->success()
                        ->send();
                } catch (Throwable $e) {
                    // Surface the domain guard's rejection verbatim; never re-implement or bypass it.
                    Notification::make()->title('Retry rejected')->body($e->getMessage())->danger()->send();
                }
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRefund::route('/'),
            'view' => Pages\ViewRefund::route('/{record}'),
        ];
    }

    /** The purchasing customer's display name for a refund's order, or an em dash. */
    private static function customerName(Refund $record): string
    {
        $user = $record->order?->user;

        if (! $user instanceof Model) {
            return '—';
        }

        $name = $user->getAttribute('name');

        return is_string($name) && $name !== '' ? $name : '—';
    }

    /** The purchasing customer's email for a refund's order, or an em dash. */
    private static function customerEmail(Refund $record): string
    {
        $user = $record->order?->user;

        if (! $user instanceof Model) {
            return '—';
        }

        $email = $user->getAttribute('email');

        return is_string($email) && $email !== '' ? $email : '—';
    }

    /**
     * The read-only entitlement consequence of this refund, derived from the engine's behavior
     * (RefundOrderAction dispatches OrderRefunded — which RevokeEnrollmentsOnRefund handles — ONLY on
     * a FULL refund). A partial or non-succeeded refund leaves access untouched.
     */
    private static function entitlementEffect(Refund $record): string
    {
        if ($record->statusEnum() !== RefundStatus::Succeeded) {
            return 'No entitlement change (refund not succeeded).';
        }

        $order = $record->order;

        if ($order instanceof Order && self::orderStatusOf($order) === OrderStatus::Refunded) {
            return 'Enrollment revoked (order fully refunded).';
        }

        return 'Access retained (partial refund — order still paid).';
    }

    /** Derive an order's status enum PHPStan-clean. */
    private static function orderStatusOf(Order $order): OrderStatus
    {
        $status = $order->getAttribute('status');

        return $status instanceof OrderStatus ? $status : OrderStatus::from((string) $status);
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
