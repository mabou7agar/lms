<?php

use App\Platform\Integration\Exceptions\WebhookUrlNotAllowedException;
use App\Platform\Integration\Security\WebhookUrlGuard;

/**
 * SSRF guard unit matrix. Uses IP LITERALS (no DNS) or reserved *.localhost so no test touches a
 * network. A guard with require_https=true rejects plaintext http; with false it permits it.
 */
it('rejects loopback, private, link-local and metadata addresses', function (string $url): void {
    expect(fn () => (new WebhookUrlGuard(true))->assertAllowed($url))
        ->toThrow(WebhookUrlNotAllowedException::class);
})->with([
    'loopback v4' => ['https://127.0.0.1/hook'],
    'loopback name' => ['https://localhost/hook'],
    'private 10/8' => ['https://10.1.2.3/hook'],
    'private 192.168' => ['https://192.168.1.10/hook'],
    'link-local metadata' => ['https://169.254.169.254/latest/meta-data'],
    'ipv6 loopback' => ['https://[::1]/hook'],
]);

it('rejects a non-http(s) scheme', function (): void {
    expect(fn () => (new WebhookUrlGuard(true))->assertAllowed('ftp://example.com/x'))
        ->toThrow(WebhookUrlNotAllowedException::class);
});

it('rejects plaintext http when https is required', function (): void {
    expect(fn () => (new WebhookUrlGuard(true))->assertAllowed('http://8.8.8.8/hook'))
        ->toThrow(WebhookUrlNotAllowedException::class);
});

it('allows a public https destination', function (): void {
    expect((new WebhookUrlGuard(true))->isAllowed('https://8.8.8.8/hook'))->toBeTrue();
});

it('allows plaintext http when https is NOT required', function (): void {
    expect((new WebhookUrlGuard(false))->isAllowed('http://8.8.8.8/hook'))->toBeTrue();
});
