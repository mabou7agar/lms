<?php

use App\Platform\Media\Exceptions\MediaNotReadyException;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Media\Models\MediaAttachment;
use App\Platform\Media\Services\MediaAttachmentService;
use App\Platform\Media\Services\MediaReplacementService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('media.ingestion.default', 'fake');
    $this->attachments = app(MediaAttachmentService::class);
    $this->replacement = app(MediaReplacementService::class);
});

it('D2: repoints every reference from the old asset onto the new one and leaves no orphan', function () {
    $old = MediaAsset::factory()->ready()->ownedBy(7)->create();
    $new = MediaAsset::factory()->ready()->ownedBy(7)->create();

    // The old asset is used in two places.
    $this->attachments->attach($old, 7, 'authoring.block', 10, 'primary');
    $this->attachments->attach($old, 7, 'authoring.block', 20, 'attachment');

    $repointed = $this->replacement->replace($old, $new, 7);

    expect($repointed)->toBe(2);

    // Every reference now points at the NEW asset; none point at the old one.
    expect(MediaAttachment::query()->where('media_asset_id', $old->id)->count())->toBe(0)
        ->and(MediaAttachment::query()->where('media_asset_id', $new->id)->count())->toBe(2);

    // Roles/targets are preserved on the repointed references.
    $repointedRows = MediaAttachment::query()->where('media_asset_id', $new->id)->orderBy('attachable_id')->get();
    expect($repointedRows[0]->attachable_id)->toBe(10)
        ->and($repointedRows[0]->role)->toBe('primary')
        ->and($repointedRows[1]->attachable_id)->toBe(20);

    // The original is retired (soft-deleted), and NO attachment references a trashed asset.
    expect(MediaAsset::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(MediaAsset::withTrashed()->whereKey($old->id)->first()->trashed())->toBeTrue();

    $orphans = MediaAttachment::query()
        ->whereNotIn('media_asset_id', MediaAsset::query()->select('id'))
        ->count();
    expect($orphans)->toBe(0);
});

it('D2: rolls back completely when the replacement asset is not ready — no references move', function () {
    $old = MediaAsset::factory()->ready()->ownedBy(7)->create();
    $notReady = MediaAsset::factory()->processing()->ownedBy(7)->create();

    $this->attachments->attach($old, 7, 'authoring.block', 10, 'primary');
    $this->attachments->attach($old, 7, 'authoring.block', 20);

    expect(fn () => $this->replacement->replace($old, $notReady, 7))
        ->toThrow(MediaNotReadyException::class);

    // Nothing changed: the old asset keeps all of its references and is not deleted; the new asset
    // received nothing.
    expect(MediaAttachment::query()->where('media_asset_id', $old->id)->count())->toBe(2)
        ->and(MediaAttachment::query()->where('media_asset_id', $notReady->id)->count())->toBe(0)
        ->and(MediaAsset::query()->whereKey($old->id)->exists())->toBeTrue();
});

it('D2: replacing an asset with no references simply retires the old asset', function () {
    $old = MediaAsset::factory()->ready()->ownedBy(7)->create();
    $new = MediaAsset::factory()->ready()->ownedBy(7)->create();

    $repointed = $this->replacement->replace($old, $new, 7);

    expect($repointed)->toBe(0)
        ->and(MediaAsset::query()->whereKey($old->id)->exists())->toBeFalse();
});
