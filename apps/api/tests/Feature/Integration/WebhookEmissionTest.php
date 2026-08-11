<?php

use App\Contexts\Learning\Events\CourseCompleted;
use App\Contexts\Learning\Models\Enrollment;
use App\Platform\Integration\Models\WebhookDelivery;
use App\Platform\Integration\Models\WebhookEndpoint;
use App\Platform\Integration\Signing\WebhookSigner;
use App\Platform\Shared\Tenancy\TenantContext;
use App\Platform\Shared\Tenancy\TenantId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('queue.default', 'sync');
    Http::fake(['*' => Http::response('', 200)]);
    app(TenantContext::class)->forget();
});

afterEach(function (): void {
    app(TenantContext::class)->forget();
});

/** Count deliveries across all tenants (bypassing the tenant scope) for assertions. */
function allDeliveries(): int
{
    return app(TenantContext::class)->runWithoutTenancy(fn (): int => WebhookDelivery::query()->count());
}

it('creates a signed delivery only to subscribed endpoints when a domain event fires', function (): void {
    app(TenantContext::class)->set(TenantId::from(1));

    $subscribed = WebhookEndpoint::factory()->organization(1)->subscribedTo(['course.completed'])->create();
    WebhookEndpoint::factory()->organization(1)->subscribedTo(['payment.succeeded'])->create();

    $enrollment = Enrollment::factory()->completed()->create();

    CourseCompleted::dispatch($enrollment);

    // Exactly one delivery — only the subscribed endpoint.
    expect(allDeliveries())->toBe(1);

    $delivery = WebhookDelivery::query()->firstOrFail();
    expect($delivery->webhook_endpoint_id)->toBe($subscribed->id)
        ->and($delivery->event_type)->toBe('course.completed')
        ->and($delivery->status)->toBe('success');

    // The request that was sent carries a valid HMAC signature over "{timestamp}.{body}".
    Http::assertSent(function ($request) use ($subscribed): bool {
        $ts = $request->header(WebhookSigner::TIMESTAMP_HEADER)[0] ?? '';
        $sig = $request->header(WebhookSigner::SIGNATURE_HEADER)[0] ?? '';
        $expected = 'sha256='.hash_hmac('sha256', $ts.'.'.$request->body(), $subscribed->secret);

        return $request->url() === $subscribed->url && hash_equals($expected, $sig);
    });
});

it('never delivers an org1 event to an org2 endpoint (tenant isolation)', function (): void {
    // An org2 endpoint subscribed to the same event.
    app(TenantContext::class)->set(TenantId::from(2));
    $org2 = WebhookEndpoint::factory()->organization(2)->subscribedTo(['course.completed'])->create();

    // Now act as org1 and fire the event.
    app(TenantContext::class)->set(TenantId::from(1));
    $org1 = WebhookEndpoint::factory()->organization(1)->subscribedTo(['course.completed'])->create();

    CourseCompleted::dispatch(Enrollment::factory()->completed()->create());

    expect(allDeliveries())->toBe(1);

    $org2Count = app(TenantContext::class)->runWithoutTenancy(
        fn (): int => WebhookDelivery::query()->where('webhook_endpoint_id', $org2->id)->count(),
    );
    $org1Count = app(TenantContext::class)->runWithoutTenancy(
        fn (): int => WebhookDelivery::query()->where('webhook_endpoint_id', $org1->id)->count(),
    );

    expect($org2Count)->toBe(0)->and($org1Count)->toBe(1);
    Http::assertSent(fn ($request): bool => $request->url() === $org1->url);
    Http::assertNotSent(fn ($request) => $request->url() === $org2->url);
});

it('is idempotent: the same event dispatched twice delivers once', function (): void {
    app(TenantContext::class)->set(TenantId::from(1));
    WebhookEndpoint::factory()->organization(1)->subscribedTo(['course.completed'])->create();

    $enrollment = Enrollment::factory()->completed()->create();

    CourseCompleted::dispatch($enrollment);
    CourseCompleted::dispatch($enrollment);

    expect(allDeliveries())->toBe(1);
});
