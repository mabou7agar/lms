<?php

namespace App\Contexts\Commerce\Filament\Resources\SubscriptionResource\Pages;

use App\Contexts\Commerce\Enums\SubscriptionChangeType;
use App\Contexts\Commerce\Enums\SubscriptionStatus;
use App\Contexts\Commerce\Filament\Resources\SubscriptionResource;
use App\Contexts\Commerce\Models\Subscription;
use App\Contexts\Commerce\Models\SubscriptionChange;
use App\Contexts\Commerce\Models\SubscriptionPlan;
use App\Platform\Shared\Audit\AuditLog;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Read-only detail of one subscription: status + entitlement, the (tz-aware) billing window, the
 * redacted charge/provider data, the entitlement (plan → product → courses) it grants, the
 * renewal + dunning state, the append-only lifecycle timeline, and the privileged-action audit
 * history. Lifecycle operations are exposed as header actions that delegate to the domain Actions;
 * nothing here mutates state directly.
 */
class ViewSubscription extends ViewRecord
{
    protected static string $resource = SubscriptionResource::class;

    protected function getHeaderActions(): array
    {
        return SubscriptionResource::lifecycleActions();
    }

    public function infolist(Schema $schema): Schema
    {
        $timezone = SubscriptionResource::adminTimezone();

        return $schema->components([
            Section::make('Status & entitlement')->columns(3)->schema([
                TextEntry::make('status')->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof SubscriptionStatus ? ucfirst($state->value) : $state)
                    ->color(fn ($state) => $state === SubscriptionStatus::Active || $state === SubscriptionStatus::Trialing ? 'success' : 'gray'),
                TextEntry::make('entitlement')->label('Grants access now')
                    ->state(fn (Subscription $record): string => $record->isActiveNow() ? 'Yes' : 'No')
                    ->badge()->color(fn (Subscription $record): string => $record->isActiveNow() ? 'success' : 'danger'),
                IconEntry::make('cancel_at_period_end')->label('Cancel at period end')->boolean(),
            ]),
            Section::make('Billing window')->columns(3)->schema([
                TextEntry::make('current_period_start')->label('Period start')->dateTime(timezone: $timezone)->placeholder('—'),
                TextEntry::make('current_period_end')->label('Period end')->dateTime(timezone: $timezone)->placeholder('—'),
                TextEntry::make('trial_ends_at')->label('Trial ends')->dateTime(timezone: $timezone)->placeholder('—'),
                TextEntry::make('grace_ends_at')->label('Grace ends')->dateTime(timezone: $timezone)->placeholder('—'),
                TextEntry::make('canceled_at')->label('Canceled at')->dateTime(timezone: $timezone)->placeholder('—'),
                TextEntry::make('plan.name')->label('Plan')->placeholder('—'),
            ]),
            Section::make('Entitlement (what this subscription grants)')->columns(3)->schema([
                TextEntry::make('entitlement_source')->label('Source')
                    ->state(fn (Subscription $record): string => self::entitlementSource($record)),
                TextEntry::make('entitlement_state')->label('Access state')->badge()
                    ->state(fn (Subscription $record): string => $record->isActiveNow() ? 'Active' : 'Inactive')
                    ->color(fn (Subscription $record): string => $record->isActiveNow() ? 'success' : 'gray'),
                TextEntry::make('entitlement_expiry')->label('Access expires')
                    ->state(fn (Subscription $record): string => self::formatInstant($record->effectiveEnd(), $timezone)),
                TextEntry::make('entitled_courses')->label('Entitled courses')
                    ->columnSpanFull()
                    ->listWithLineBreaks()
                    ->state(fn (Subscription $record): array => self::entitledCourses($record)),
            ]),
            Section::make('Charges (sensitive data redacted)')->columns(3)->schema([
                TextEntry::make('provider')->label('Provider')->placeholder('—'),
                TextEntry::make('provider_reference')->label('Provider reference')
                    ->formatStateUsing(fn ($state) => SubscriptionResource::redact(is_string($state) ? $state : null)),
                TextEntry::make('currency')->label('Currency'),
                TextEntry::make('amount_minor')->label('Recurring amount (minor units)'),
            ]),
            Section::make('Renewal & dunning')->columns(3)->schema([
                TextEntry::make('dunning_state')->label('Dunning state')->badge()
                    ->state(fn (Subscription $record): string => self::dunningState($record))
                    ->color(fn (Subscription $record): string => self::dunningColor($record)),
                TextEntry::make('next_action_at')->label('Next renewal / retry')
                    ->state(fn (Subscription $record): string => self::formatInstant(self::nextActionAt($record), $timezone)),
                TextEntry::make('failed_renewals')->label('Failed renewal attempts')
                    ->state(fn (Subscription $record): int => self::failedRenewalCount($record)),
                TextEntry::make('last_renewal_at')->label('Last successful renewal')
                    ->state(fn (Subscription $record): string => self::formatInstant(self::lastRenewalAt($record), $timezone)),
                TextEntry::make('grace_ends_at_dunning')->label('Grace / recovery deadline')
                    ->state(fn (Subscription $record): string => self::formatInstant($record->graceEndsAt(), $timezone)),
            ]),
            Section::make('Lifecycle timeline')->schema([
                RepeatableEntry::make('changes')->label('Transitions')
                    ->schema([
                        TextEntry::make('type')->badge()
                            ->formatStateUsing(fn ($state) => $state instanceof SubscriptionChangeType ? ucfirst(str_replace('_', ' ', $state->value)) : $state),
                        TextEntry::make('amount_minor')->label('Amount (minor)'),
                        TextEntry::make('note')->placeholder('—'),
                        TextEntry::make('created_at')->label('When')->dateTime(timezone: $timezone),
                    ])
                    ->columns(4),
            ]),
            Section::make('Audit history')->schema([
                TextEntry::make('audit_trail')
                    ->label('Privileged actions')
                    ->columnSpanFull()
                    ->listWithLineBreaks()
                    ->state(fn (Subscription $record): array => SubscriptionResource::auditTrail($record)),
            ]),
        ]);
    }

    /** Human description of where the entitlement originates: plan → product. */
    private static function entitlementSource(Subscription $record): string
    {
        $plan = $record->plan instanceof SubscriptionPlan ? $record->plan : $record->plan()->first();

        if (! $plan instanceof SubscriptionPlan) {
            return '—';
        }

        $product = $plan->product instanceof Model ? $plan->product : $plan->product()->first();
        $planName = (string) ($plan->getAttribute('name') ?? $plan->getAttribute('public_id'));

        if (! $product instanceof Model) {
            return sprintf('Plan: %s', $planName);
        }

        $productTitle = (string) ($product->getAttribute('title') ?? $product->getAttribute('public_id'));

        return sprintf('Plan: %s → Product: %s', $planName, $productTitle);
    }

    /**
     * The course titles this subscription entitles, derived from plan → product → courses (read-only;
     * never imports a Learning model — the courses relation is resolved through the catalogue Product).
     *
     * @return array<int, string>
     */
    private static function entitledCourses(Subscription $record): array
    {
        $plan = $record->plan instanceof SubscriptionPlan ? $record->plan : $record->plan()->first();

        if (! $plan instanceof SubscriptionPlan) {
            return ['—'];
        }

        $product = $plan->product instanceof Model ? $plan->product : $plan->product()->first();

        if (! $product instanceof Model) {
            return ['No product linked to this plan.'];
        }

        $courses = $product->getRelationValue('courses');

        if (! is_iterable($courses)) {
            return ['No courses linked to this product.'];
        }

        $titles = [];

        foreach ($courses as $course) {
            if ($course instanceof Model) {
                $titles[] = (string) ($course->getAttribute('title') ?? ('#'.$course->getKey()));
            }
        }

        return $titles === [] ? ['No courses linked to this product.'] : $titles;
    }

    /** The dunning state label derived from the subscription status (no manual state is invented). */
    private static function dunningState(Subscription $record): string
    {
        return match ($record->statusEnum()) {
            SubscriptionStatus::Active, SubscriptionStatus::Trialing => 'Current',
            SubscriptionStatus::PastDue => 'Past due (retrying)',
            SubscriptionStatus::Grace => 'In grace (recovery)',
            SubscriptionStatus::Expired => 'Expired (recovery failed)',
            SubscriptionStatus::Canceled => 'Canceled',
            SubscriptionStatus::Paused => 'Paused',
        };
    }

    private static function dunningColor(Subscription $record): string
    {
        return match ($record->statusEnum()) {
            SubscriptionStatus::Active, SubscriptionStatus::Trialing => 'success',
            SubscriptionStatus::PastDue, SubscriptionStatus::Grace => 'warning',
            SubscriptionStatus::Expired => 'danger',
            default => 'gray',
        };
    }

    /**
     * When the billing worker will next act on this subscription:
     *   - active/trialing → the next renewal charge at current_period_end,
     *   - past_due        → escalation to grace after the retry window (current_period_end + retry_days),
     *   - grace           → expiry at grace_ends_at.
     * Mirrors RenewDueSubscriptionsCommand's clocks; terminal states have no next action.
     */
    private static function nextActionAt(Subscription $record): ?Carbon
    {
        $retryDays = max(0, (int) config('commerce.subscriptions.retry_days', 1));

        return match ($record->statusEnum()) {
            SubscriptionStatus::Active, SubscriptionStatus::Trialing => $record->currentPeriodEnd(),
            SubscriptionStatus::PastDue => $record->currentPeriodEnd()?->copy()->addDays($retryDays),
            SubscriptionStatus::Grace => $record->graceEndsAt(),
            default => null,
        };
    }

    /** Number of failed-renewal audit entries recorded for this subscription. */
    private static function failedRenewalCount(Subscription $record): int
    {
        return AuditLog::query()
            ->where('subject_type', $record->getMorphClass())
            ->where('subject_id', $record->getKey())
            ->where('action', 'commerce.subscription.renewal_failed')
            ->count();
    }

    /** Timestamp of the most recent successful renewal transition, if any. */
    private static function lastRenewalAt(Subscription $record): ?Carbon
    {
        $renewal = SubscriptionChange::query()
            ->where('subscription_id', $record->getKey())
            ->where('type', SubscriptionChangeType::Renewal->value)
            ->latest('id')
            ->first();

        $createdAt = $renewal?->getAttribute('created_at');

        return $createdAt instanceof Carbon ? $createdAt : null;
    }

    /** Format an instant in the admin timezone, or an em dash when null. */
    private static function formatInstant(?Carbon $instant, string $timezone): string
    {
        return $instant instanceof Carbon
            ? $instant->copy()->setTimezone($timezone)->toDayDateTimeString()
            : '—';
    }
}
