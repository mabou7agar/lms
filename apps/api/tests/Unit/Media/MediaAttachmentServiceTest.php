<?php

use App\Platform\Media\Exceptions\MediaAccessDeniedException;
use App\Platform\Media\Exceptions\MediaInUseException;
use App\Platform\Media\Exceptions\MediaNotReadyException;
use App\Platform\Media\Exceptions\MediaValidationException;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Media\Services\MediaAttachmentService;
use App\Platform\Media\Services\MediaDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('media.ingestion.default', 'fake');
    $this->attachments = app(MediaAttachmentService::class);
});

it('attaches a ready asset to content and is idempotent', function () {
    $asset = MediaAsset::factory()->ready()->ownedBy(7)->create();

    $this->attachments->attach($asset, 7, 'authoring.block', 42, 'primary');
    $this->attachments->attach($asset, 7, 'authoring.block', 42, 'primary'); // no duplicate

    expect($this->attachments->usageCount($asset))->toBe(1);
});

it('rejects attaching an asset the actor does not own', function () {
    $asset = MediaAsset::factory()->ready()->ownedBy(7)->create();

    $this->attachments->attach($asset, 999, 'authoring.block', 42);
})->throws(MediaAccessDeniedException::class);

it('rejects attaching a non-ready asset', function () {
    $asset = MediaAsset::factory()->processing()->ownedBy(7)->create();

    $this->attachments->attach($asset, 7, 'authoring.block', 42);
})->throws(MediaNotReadyException::class);

it('rejects a cross-course attachment', function () {
    $asset = MediaAsset::factory()->ready()->ownedBy(7)->forCourse(100)->create();

    // Attaching into course 200's content is refused for a course-100 asset.
    $this->attachments->attach($asset, 7, 'authoring.block', 42, 'primary', 200);
})->throws(MediaValidationException::class);

it('blocks deleting an asset that is still in use unless forced', function () {
    $asset = MediaAsset::factory()->ready()->ownedBy(7)->create();
    $this->attachments->attach($asset, 7, 'authoring.block', 42);

    $deletion = app(MediaDeletionService::class);

    expect(fn () => $deletion->deleteAsset($asset, 7, force: false))
        ->toThrow(MediaInUseException::class);

    // Forcing cascades the detach and deletes.
    $deletion->deleteAsset($asset, 7, force: true);

    expect(MediaAsset::withTrashed()->find($asset->id)->trashed())->toBeTrue();
});
