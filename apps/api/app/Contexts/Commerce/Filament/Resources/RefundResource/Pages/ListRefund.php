<?php

namespace App\Contexts\Commerce\Filament\Resources\RefundResource\Pages;

use App\Contexts\Commerce\Actions\Refund\IssueRefundAction;
use App\Contexts\Commerce\Enums\OrderStatus;
use App\Contexts\Commerce\Enums\RefundReason;
use App\Contexts\Commerce\Filament\Resources\RefundResource;
use App\Contexts\Commerce\Models\Order;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Throwable;

class ListRefund extends ListRecords
{
    protected static string $resource = RefundResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            // Orchestration only. The confirmation form surfaces the (advisory) refundable remaining
            // and captures amount + reason, then hands the whole request to IssueRefundAction. Every
            // guard — amount <= refundable balance, no duplicate/over-refund, no retry of a succeeded
            // refund — lives in the domain action; here we only relay its success or its error.
            Action::make('issueRefund')
                ->label('Issue refund')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('danger')
                ->visible(fn (): bool => RefundResource::userCanManage())
                ->modalHeading('Issue refund')
                ->modalSubmitActionLabel('Issue refund')
                ->schema([
                    // Dynamic search (not a fixed option list) so the confirmation form never
                    // second-guesses the engine: the chosen order is handed to IssueRefundAction,
                    // which is the sole authority on whether it is refundable.
                    Select::make('order_id')
                        ->label('Order')
                        ->required()
                        ->live()
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => Order::query()
                            ->where('status', OrderStatus::Paid->value)
                            ->where('public_id', 'like', '%'.$search.'%')
                            ->orderByDesc('id')
                            ->limit(50)
                            ->pluck('public_id', 'id')
                            ->all())
                        ->getOptionLabelUsing(fn ($value): ?string => Order::whereKey($value)->value('public_id')),
                    Placeholder::make('refundable_remaining')
                        ->label('Refundable remaining (minor units)')
                        ->content(function (Get $get): string {
                            $orderId = $get('order_id');

                            if ($orderId === null || $orderId === '') {
                                return 'Select an order';
                            }

                            $order = Order::find($orderId);

                            return $order === null
                                ? 'Select an order'
                                : (string) RefundResource::remainingRefundableMinor($order);
                        }),
                    TextInput::make('amount_minor')
                        ->label('Amount to refund (minor units)')
                        ->numeric()
                        ->integer()
                        ->helperText('Leave blank to refund the full remaining balance. The refund is rejected server-side if it exceeds the refundable balance.'),
                    Select::make('reason')
                        ->label('Reason')
                        ->required()
                        ->default(RefundReason::RequestedByCustomer->value)
                        ->options(collect(RefundReason::cases())
                            ->mapWithKeys(fn (RefundReason $reason): array => [$reason->value => ucfirst(str_replace('_', ' ', $reason->value))])
                            ->all()),
                ])
                ->action(function (array $data): void {
                    $order = Order::find($data['order_id'] ?? null);

                    if ($order === null) {
                        Notification::make()->title('Order not found')->danger()->send();

                        return;
                    }

                    $rawAmount = $data['amount_minor'] ?? null;
                    $amountMinor = ($rawAmount === null || $rawAmount === '') ? null : (int) $rawAmount;
                    $reason = RefundReason::from((string) ($data['reason'] ?? RefundReason::RequestedByCustomer->value));

                    try {
                        $refund = app(IssueRefundAction::class)->execute($order, $amountMinor, $reason);

                        Notification::make()
                            ->title('Refund issued')
                            ->body(sprintf('Refunded %d %s.', $refund->amountMinor(), (string) $order->getAttribute('currency')))
                            ->success()
                            ->send();
                    } catch (Throwable $e) {
                        // Surface the domain guard's rejection verbatim; never re-implement or bypass it.
                        Notification::make()->title('Refund rejected')->body($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }
}
