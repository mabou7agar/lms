<?php

use App\Platform\Media\Events\MediaDeleted;
use App\Platform\Media\Events\MediaDetached;
use App\Platform\Media\Exceptions\MediaInUseException;
use App\Platform\Media\Exceptions\MediaNotReadyException;
use App\Platform\Media\Ingestion\Providers\FakeIngestionProvider;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Media\Models\MediaAttachment;
use App\Platform\Media\Services\MediaAttachmentService;
use App\Platform\Media\Services\MediaDeletionService;
use App\Platform\Shared\Media\Enums\MediaStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/**
 * A fake ingestion adapter that records the persisted state of the asset at the moment deleteRemote
 * is invoked. It lets a single-threaded test prove ordering: the row must already be soft-deleted
 * and status=Deleted before any remote purge happens. Uniquely named so ParaTest can load this file
 * alongside its siblings in one worker process without a redeclare clash.
 */
if (! class_exists('MediaDeletionServiceTestRecordingProvider', false)) {
    class MediaDeletionServiceTestRecordingProvider extends FakeIngestionProvider
    {
        /** @var list<array{status: ?MediaStatus, trashed: ?bool}> */
        public array $observations = [];

        public int $calls = 0;

        public function deleteRemote(string $providerRef): void
        {
            $this->calls++;

            $asset = MediaAsset::withTrashed()->where('provider_ref', $providerRef)->first();

            $this->observations[] = [
                'status' => $asset?->status,
                'trashed' => $asset?->trashed(),
            ];
        }
    }
}

beforeEach(function () {
    config()->set('media.ingestion.default', 'fake');
    $this->attachments = app(MediaAttachmentService::class);
    $this->deletion = app(MediaDeletionService::class);
});

it('refuses a non-forced delete when an attachment exists, re-checking usage under the row lock', function () {
    $spy = new MediaDeletionServiceTestRecordingProvider;
    app()->instance(FakeIngestionProvider::class, $spy);

    $asset = MediaAsset::factory()->ready()->ownedBy(7)->create();
    $this->attachments->attach($asset, 7, 'authoring.block', 42);

    expect(fn () => $this->deletion->deleteAsset($asset, 7, force: false))
        ->toThrow(MediaInUseException::class);

    // The in-transaction re-check aborted before any write: nothing was soft-deleted, the status is
    // untouched, the usage record survives, and no remote delete was attempted.
    $fresh = MediaAsset::withTrashed()->findOrFail($asset->id);
    expect($fresh->trashed())->toBeFalse()
        ->and($fresh->status)->toBe(MediaStatus::Ready)
        ->and($this->attachments->usageCount($asset))->toBe(1)
        ->and($spy->calls)->toBe(0);
});

it('refuses to attach to an asset a delete already soft-deleted, so no attachment can orphan', function () {
    $asset = MediaAsset::factory()->ready()->ownedBy(7)->create();

    // The delete commits first (no usage): the row is soft-deleted and marked Deleted.
    $this->deletion->deleteAsset($asset, 7, force: false);

    // $asset is now stale in memory (it still looks Ready), so the pre-transaction guards pass — but
    // attach re-locks and re-checks the row inside its transaction and refuses the soft-deleted asset.
    expect(fn () => $this->attachments->attach($asset, 7, 'authoring.block', 42))
        ->toThrow(MediaNotReadyException::class);

    expect(MediaAttachment::query()->where('media_asset_id', $asset->id)->count())->toBe(0);
});

it('purges the remote asset only after the delete transaction commits the row as Deleted', function () {
    $spy = new MediaDeletionServiceTestRecordingProvider;
    app()->instance(FakeIngestionProvider::class, $spy);

    $asset = MediaAsset::factory()->ready()->ownedBy(7)->create();

    $this->deletion->deleteAsset($asset, 7, force: false);

    expect($spy->calls)->toBe(1)
        ->and($spy->observations[0]['status'])->toBe(MediaStatus::Deleted)
        ->and($spy->observations[0]['trashed'])->toBeTrue();
});

it('performs no remote delete and leaves the asset Ready when the delete transaction rolls back', function () {
    $spy = new MediaDeletionServiceTestRecordingProvider;
    app()->instance(FakeIngestionProvider::class, $spy);

    $asset = MediaAsset::factory()->ready()->ownedBy(7)->create();
    $originalRef = $asset->provider_ref;

    // Force the transaction body to throw at soft-delete time on an isolated dispatcher clone, so the
    // throwing listener never leaks to sibling tests.
    $dispatcher = MediaAsset::getEventDispatcher();
    MediaAsset::setEventDispatcher(clone $dispatcher);
    MediaAsset::deleting(function (): void {
        throw new RuntimeException('forced failure inside the delete transaction');
    });

    try {
        expect(fn () => $this->deletion->deleteAsset($asset, 7, force: false))
            ->toThrow(RuntimeException::class);
    } finally {
        MediaAsset::setEventDispatcher($dispatcher);
    }

    $fresh = MediaAsset::withTrashed()->findOrFail($asset->id);
    expect($fresh->trashed())->toBeFalse()
        ->and($fresh->status)->toBe(MediaStatus::Ready)
        ->and($fresh->provider_ref)->toBe($originalRef)
        ->and($spy->calls)->toBe(0);
});

it('force-deletes by cascading the detach then soft-deleting the asset', function () {
    Event::fake([MediaDetached::class, MediaDeleted::class]);

    $asset = MediaAsset::factory()->ready()->ownedBy(7)->create();
    MediaAttachment::factory()->create([
        'media_asset_id' => $asset->id,
        'attachable_type' => 'authoring.block',
        'attachable_id' => 1,
        'attached_by' => 7,
    ]);
    MediaAttachment::factory()->create([
        'media_asset_id' => $asset->id,
        'attachable_type' => 'authoring.block',
        'attachable_id' => 2,
        'attached_by' => 7,
    ]);

    $this->deletion->deleteAsset($asset, 7, force: true);

    expect(MediaAttachment::query()->where('media_asset_id', $asset->id)->count())->toBe(0)
        ->and(MediaAsset::withTrashed()->findOrFail($asset->id)->trashed())->toBeTrue();

    Event::assertDispatched(MediaDetached::class, 2);
    Event::assertDispatched(MediaDeleted::class);
});
