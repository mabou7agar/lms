<?php

use App\Platform\Media\Models\MediaAsset;
use App\Platform\Shared\Media\Contracts\PublicAssetUrlResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * P1 - PublicAssetUrlResolver behaviour at the resolver (service) boundary. Every case asserts the
 * exact contract: a PUBLIC asset yields a stable (unsigned) URL, an AUTHENTICATED asset yields a
 * signed URL, a PRIVATE asset is never exposed, legacy passes through, empty is null, a missing/
 * deleted asset is null, a replacement busts the URL, and NO storage key / provider ref ever leaks.
 */
beforeEach(function () {
    // Deterministic signer for AUTHENTICATED assets.
    config()->set('learning.playback.provider', 'fake');
    config()->set('media.public.base_url', 'https://cdn.test');

    $this->resolver = app(PublicAssetUrlResolver::class);
});

it('resolves a PUBLIC asset to a stable, fingerprinted URL with NO expiry token', function () {
    $asset = MediaAsset::factory()->ready()->publicVisibility()->create([
        'storage_key' => 'media/lesson_image/secret-object-key.jpg',
    ]);

    $url = $this->resolver->resolve((string) $asset->public_id);

    expect($url)->toBeString()
        ->and($url)->toContain('https://cdn.test/media/public/')
        ->and($url)->toContain((string) $asset->public_id)
        ->and($url)->toContain('?v=')          // fingerprint present
        ->and($url)->not->toContain('expires') // NOT a short-lived signed URL
        // Stable across calls (safe for long CDN caching).
        ->and($this->resolver->resolve((string) $asset->public_id))->toBe($url)
        // Never leaks the storage key / provider ref.
        ->and($url)->not->toContain('secret-object-key')
        ->and($url)->not->toContain((string) $asset->provider_ref);
});

it('resolves an AUTHENTICATED asset to a signed, expiring URL', function () {
    $asset = MediaAsset::factory()->ready()->authenticatedVisibility()->create([
        'storage_key' => 'media/lesson_image/authed-key.jpg',
    ]);

    $url = $this->resolver->resolve((string) $asset->public_id);

    expect($url)->toBeString()
        ->and($url)->toContain('/media/stream/')
        ->and($url)->toContain('expires=')                 // short-lived token
        ->and($url)->not->toContain('authed-key')          // no storage key
        ->and($url)->not->toContain((string) $asset->playback_id); // no provider identifier
});

it('never exposes a PRIVATE asset in a public context', function () {
    // Factory default visibility is PRIVATE (secure by default).
    $asset = MediaAsset::factory()->ready()->create([
        'storage_key' => 'media/lesson_image/private-key.jpg',
    ]);

    expect($asset->visibility->value)->toBe('private')
        ->and($this->resolver->resolve((string) $asset->public_id))->toBeNull();
});

it('passes a legacy URL/path through unchanged', function () {
    $absolute = 'https://cdn.example.com/legacy/logo.svg';
    $relative = 'storage/branding/logo-light.png';

    expect($this->resolver->resolve($absolute))->toBe($absolute)
        ->and($this->resolver->resolve($relative))->toBe($relative);
});

it('returns null for empty / whitespace / null input', function () {
    expect($this->resolver->resolve(null))->toBeNull()
        ->and($this->resolver->resolve(''))->toBeNull()
        ->and($this->resolver->resolve('   '))->toBeNull();
});

it('returns null for a missing or soft-deleted asset (no broken/secret-leaking URL)', function () {
    // A well-formed but unknown public_id reference.
    $unknown = \App\Platform\Shared\Helpers\Uuid::v7();
    expect($this->resolver->resolve($unknown))->toBeNull();

    // A deleted PUBLIC asset resolves to null, safely.
    $asset = MediaAsset::factory()->ready()->publicVisibility()->create();
    $reference = (string) $asset->public_id;
    $asset->delete();

    expect($this->resolver->resolve($reference))->toBeNull();
});

it('version-busts the public URL when a reference is repointed to a replacement asset', function () {
    // The engine replaces content by minting a NEW asset (new public id + new storage key); a
    // repointed reference therefore resolves to a fresh fingerprint — the CDN cache is busted.
    $original = MediaAsset::factory()->ready()->publicVisibility()->create([
        'storage_key' => 'media/lesson_image/v1.jpg',
    ]);
    $replacement = MediaAsset::factory()->ready()->publicVisibility()->create([
        'storage_key' => 'media/lesson_image/v2.jpg',
    ]);

    $urlBefore = $this->resolver->resolve((string) $original->public_id);
    $urlAfter = $this->resolver->resolve((string) $replacement->public_id);

    expect($urlAfter)->not->toBe($urlBefore); // different fingerprint => busted

    // After the original is retired (deleted) the stale reference resolves to null, safely.
    $original->delete();
    expect($this->resolver->resolve((string) $original->public_id))->toBeNull()
        ->and($this->resolver->resolve((string) $replacement->public_id))->toBe($urlAfter);
});

it('resolveMany preserves keys', function () {
    $public = MediaAsset::factory()->ready()->publicVisibility()->create();
    $private = MediaAsset::factory()->ready()->create();

    $out = $this->resolver->resolveMany([
        'logo' => (string) $public->public_id,
        'secret' => (string) $private->public_id,
        'legacy' => 'https://cdn.example.com/x.png',
        'empty' => '',
    ]);

    expect($out['logo'])->toContain('/media/public/')
        ->and($out['secret'])->toBeNull()
        ->and($out['legacy'])->toBe('https://cdn.example.com/x.png')
        ->and($out['empty'])->toBeNull();
});
