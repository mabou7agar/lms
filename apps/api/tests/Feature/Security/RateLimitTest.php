<?php

use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(fn () => RateLimiter::clear('checkout'));

it('throttles the public certificate verification endpoint', function () {
    // Limiter: certification-verify — 30/min per IP.
    for ($i = 0; $i < 30; $i++) {
        $this->getJson('/api/v1/certificates/verify/NO-SUCH-CODE')->assertStatus(404);
    }

    $this->getJson('/api/v1/certificates/verify/NO-SUCH-CODE')->assertStatus(429);
});

it('throttles checkout per user', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    // Limiter: commerce-checkout — 10/min per user. An empty cart still consumes an attempt.
    $statuses = [];
    for ($i = 0; $i < 11; $i++) {
        $statuses[] = $this->postJson('/api/v1/checkout')->getStatusCode();
    }

    expect(end($statuses))->toBe(429);
});

it('throttles the public payment webhook per source IP', function () {
    // Limiter: commerce-webhook — 60/min per IP. Each unsigned request is refused (400) by the
    // signature check, but every attempt still consumes an attempt, so the 61st is throttled.
    // This is defence in depth: a flood of signature-guessing attempts is capped regardless of
    // the (unforgeable) signature check that already rejects each one.
    $statuses = [];
    for ($i = 0; $i < 61; $i++) {
        $statuses[] = $this->postJson('/api/v1/payment/webhook', ['type' => 'payment.succeeded'])
            ->getStatusCode();
    }

    // The first 60 are refused on signature (400); the 61st never reaches the controller (429).
    expect($statuses[0])->toBe(400)
        ->and(end($statuses))->toBe(429);
});

// ---------------------------------------------------------------- M9: public read surface

it('permits public catalog reads within the limit and 429s beyond it', function () {
    // Limiter: public-read — 60/min per client. Public catalog was previously unthrottled.
    $ip = ['REMOTE_ADDR' => '198.51.100.10'];

    for ($i = 0; $i < 60; $i++) {
        $this->withServerVariables($ip)->getJson('/api/v1/courses')->assertOk();
    }

    $this->withServerVariables($ip)->getJson('/api/v1/courses')->assertStatus(429);
});

it('isolates public-read buckets by client IP so one source cannot exhaust another', function () {
    $a = ['REMOTE_ADDR' => '198.51.100.20'];
    $b = ['REMOTE_ADDR' => '198.51.100.21'];

    for ($i = 0; $i < 61; $i++) {
        $this->withServerVariables($a)->getJson('/api/v1/courses');
    }

    // A is now throttled; a request from a different IP must still be served from its own bucket.
    $this->withServerVariables($a)->getJson('/api/v1/courses')->assertStatus(429);
    $this->withServerVariables($b)->getJson('/api/v1/courses')->assertOk();
});

it('throttles the public-config surface on its own higher ceiling', function () {
    // Limiter: public-config — 120/min per client (branding/navigation/feature-flags fire on every
    // page load). 61 successful reads prove branding is NOT on the 60/min public-read bucket.
    $ip = ['REMOTE_ADDR' => '198.51.100.30'];

    for ($i = 0; $i < 61; $i++) {
        $this->withServerVariables($ip)->getJson('/api/v1/branding')->assertOk();
    }

    for ($i = 61; $i < 120; $i++) {
        $this->withServerVariables($ip)->getJson('/api/v1/branding');
    }

    $this->withServerVariables($ip)->getJson('/api/v1/branding')->assertStatus(429);
});

// ---------------------------------------------------------------- M9: credential + IP keying

it('keys password-reset throttling on email + IP, not IP alone', function () {
    // Limiter: identity-password — 6/min per (email|ip). Keying on IP alone let one source spray
    // resets across accounts; combining the email means a different account is a different bucket.
    $ip = ['REMOTE_ADDR' => '198.51.100.40'];

    $first = $this->withServerVariables($ip)->postJson('/api/v1/auth/forgot-password', ['email' => 'victim@test.com']);
    expect($first->getStatusCode())->not->toBe(429);

    for ($i = 0; $i < 5; $i++) {
        $this->withServerVariables($ip)->postJson('/api/v1/auth/forgot-password', ['email' => 'victim@test.com']);
    }

    // 7th attempt for the same email from the same IP is throttled…
    $this->withServerVariables($ip)->postJson('/api/v1/auth/forgot-password', ['email' => 'victim@test.com'])
        ->assertStatus(429);

    // …but the same IP targeting a DIFFERENT email is a separate bucket, so it is not throttled.
    $other = $this->withServerVariables($ip)->postJson('/api/v1/auth/forgot-password', ['email' => 'someone-else@test.com']);
    expect($other->getStatusCode())->not->toBe(429);
});
