<?php

use App\Contexts\Commerce\Enums\CommercePermission;
use App\Contexts\Commerce\Enums\CreditNoteStatus;
use App\Contexts\Commerce\Enums\OrderStatus;
use App\Contexts\Commerce\Enums\RefundStatus;
use App\Contexts\Commerce\Events\OrderRefunded;
use App\Contexts\Commerce\Filament\Resources\CreditNoteResource;
use App\Contexts\Commerce\Filament\Resources\CreditNoteResource\Pages\ViewCreditNote;
use App\Contexts\Commerce\Listeners\IssueCreditNoteOnRefund;
use App\Contexts\Commerce\Models\CreditNote;
use App\Contexts\Commerce\Models\Invoice;
use App\Contexts\Commerce\Models\InvoiceLine;
use App\Contexts\Commerce\Models\Order;
use App\Contexts\Commerce\Models\Refund;
use App\Contexts\Commerce\Services\CreditNoteNumberService;
use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Models\User;
use Database\Seeders\StaffRoleTemplatesSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

/** A fully-refunded order with an invoice snapshot, then the engine-issued credit note it mints. */
function engineCreditNote(): CreditNote
{
    $user = User::factory()->create();

    $order = Order::create([
        'user_id' => $user->id,
        'status' => OrderStatus::Refunded->value,
        'currency' => 'SAR',
        'subtotal_minor' => 15000,
        'discount_minor' => 0,
        'tax_minor' => 2250,
        'total_minor' => 17250,
        'placed_at' => now(),
    ]);
    $order->forceFill(['paid_at' => now(), 'refunded_at' => now()])->save();

    $invoice = Invoice::create([
        'order_id' => $order->getKey(),
        'number' => 'INV-2026-000001',
        'status' => 'paid',
        'currency' => 'SAR',
        'subtotal_minor' => 15000,
        'tax_minor' => 2250,
        'total_minor' => 17250,
        'issued_at' => now(),
    ]);

    InvoiceLine::create([
        'invoice_id' => $invoice->getKey(),
        'description' => 'Course A',
        'quantity' => 1,
        'unit_amount_minor' => 10000,
        'tax_minor' => 1500,
        'total_minor' => 11500,
    ]);
    InvoiceLine::create([
        'invoice_id' => $invoice->getKey(),
        'description' => 'Course B',
        'quantity' => 1,
        'unit_amount_minor' => 5000,
        'tax_minor' => 750,
        'total_minor' => 5750,
    ]);

    Refund::create([
        'order_id' => $order->getKey(),
        'amount_minor' => 17250,
        'currency' => 'SAR',
        'status' => RefundStatus::Succeeded->value,
        'reason' => 'requested_by_customer',
    ]);

    (new IssueCreditNoteOnRefund(app(CreditNoteNumberService::class)))->handle(new OrderRefunded($order));

    return CreditNote::where('order_id', $order->getKey())->firstOrFail();
}

it('reconciles credit-note lines (net + tax) to the stored total', function () {
    $creditNote = engineCreditNote();

    $linesTotal = (int) $creditNote->lines()->sum('amount_minor') + (int) $creditNote->lines()->sum('tax_minor');

    expect($creditNote->statusEnum())->toBe(CreditNoteStatus::Issued)
        ->and($creditNote->lines()->count())->toBe(2)
        ->and($linesTotal)->toBe($creditNote->totalMinor())
        ->and($linesTotal)->toBe(17250);
});

it('renders the credit-note read detail with its number and a balanced reconciliation', function () {
    test()->seed(RolePermissionSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    test()->actingAs($admin, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $creditNote = engineCreditNote();

    Livewire::test(ViewCreditNote::class, ['record' => $creditNote->getAttribute('public_id')])
        ->assertSuccessful()
        ->assertSee((string) $creditNote->getAttribute('number'))
        ->assertSee('Balanced');
});

it('never exposes a manual creation path for credit notes', function () {
    expect(CreditNoteResource::canCreate())->toBeFalse();
});

it('denies the credit-notes resource to a student and support agent, but allows a finance manager', function () {
    test()->seed(RolePermissionSeeder::class);
    foreach (CommercePermission::values() as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    test()->seed(StaffRoleTemplatesSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $student = User::factory()->create();
    $student->assignRole('student');

    $support = User::factory()->create();
    $support->assignRole('support_agent');

    $finance = User::factory()->create();
    $finance->assignRole('finance_manager');

    test()->actingAs($student, 'web');
    expect(CreditNoteResource::canViewAny())->toBeFalse();

    test()->actingAs($support, 'web');
    expect(CreditNoteResource::canViewAny())->toBeFalse();

    test()->actingAs($finance, 'web');
    expect(CreditNoteResource::canViewAny())->toBeTrue();
});
