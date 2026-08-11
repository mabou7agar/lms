<?php

use App\Platform\Notifications\Models\MarketingSuppression;
use App\Platform\Notifications\Support\UnsubscribeLinkGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

it('unsubscribes a recipient via a valid signed link and is idempotent', function (): void {
    $url = app(UnsubscribeLinkGenerator::class)->for('reader@example.test', 'marketing', 1);

    $this->get($url)->assertOk()->assertJsonPath('status', 'ok');

    // Idempotent: following it again does not create a second suppression.
    $this->get($url)->assertOk();

    expect(MarketingSuppression::where('email', 'reader@example.test')->where('category', 'marketing')->count())->toBe(1);
});

it('rejects a request with no signature', function (): void {
    $this->get('/api/v1/marketing/unsubscribe?email=reader@example.test&category=marketing&org=1')
        ->assertForbidden();

    expect(MarketingSuppression::count())->toBe(0);
});

it('rejects a tampered link (signature covers the whole URL)', function (): void {
    $url = app(UnsubscribeLinkGenerator::class)->for('reader@example.test', 'marketing', 1);

    // Editing the category after signing invalidates the signature — the token is single-purpose.
    $tampered = str_replace('category=marketing', 'category=account', $url);

    $this->get($tampered)->assertForbidden();

    expect(MarketingSuppression::count())->toBe(0);
});

it('never suppresses a transactional category even with a valid signature', function (): void {
    // A validly-signed link that names a transactional category must still be refused.
    $signed = URL::signedRoute(UnsubscribeLinkGenerator::ROUTE_NAME, [
        'email' => 'reader@example.test', 'category' => 'account', 'org' => 1,
    ]);

    $this->get($signed)->assertStatus(422);

    expect(MarketingSuppression::count())->toBe(0);
});
