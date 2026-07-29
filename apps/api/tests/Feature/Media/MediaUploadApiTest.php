<?php

use App\Platform\Identity\Models\User;
use App\Platform\Media\Models\MediaAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('media.ingestion.default', 'fake');
});

it('requires authentication to create an upload', function () {
    $this->postJson('/api/v1/media/assets', [])->assertUnauthorized();
});

it('creates a direct upload and returns instructions + a token', function () {
    Sanctum::actingAs(User::factory()->create());

    $res = $this->postJson('/api/v1/media/assets', [
        'type' => 'video',
        'purpose' => 'lesson_video',
        'filename' => 'lecture.mp4',
        'mime_type' => 'video/mp4',
        'size_bytes' => 10 * 1024 * 1024,
        'idempotency_key' => 'abc-123',
    ])->assertCreated();

    expect($res->json('data.media.status'))->toBe('waiting_for_upload')
        ->and($res->json('data.upload.url'))->toContain('upload.fake.test')
        ->and($res->json('data.upload_token'))->not->toBeNull()
        // Storage/provider identifiers must never leak.
        ->and($res->json('data.media'))->not->toHaveKey('provider_ref')
        ->and($res->json('data.media'))->not->toHaveKey('storage_key');
});

it('rejects a mime/type that the purpose does not accept', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/media/assets', [
        'type' => 'document',
        'purpose' => 'lesson_video',
        'filename' => 'notes.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 1024,
        'idempotency_key' => 'bad-1',
    ])->assertStatus(422)->assertJsonPath('error.code', 'MEDIA_VALIDATION');
});

it('finalizes an upload for the owner and reports ready', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $asset = MediaAsset::factory()->awaitingUpload()->ownedBy($user->id)->create();

    $this->postJson("/api/v1/media/assets/{$asset->public_id}/finalize", [
        'upload_token' => $asset->upload_token,
    ])->assertOk()->assertJsonPath('data.status', 'ready');
});

it('returns 404 for another user\'s asset (no existence leak)', function () {
    Sanctum::actingAs(User::factory()->create());
    $asset = MediaAsset::factory()->ready()->ownedBy(4242)->create();

    $this->getJson("/api/v1/media/assets/{$asset->public_id}")->assertNotFound();
});

it('lists only the actor\'s own media', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    MediaAsset::factory()->ready()->ownedBy($user->id)->count(2)->create();
    MediaAsset::factory()->ready()->ownedBy(4242)->create();

    $res = $this->getJson('/api/v1/media/assets')->assertOk();

    expect($res->json('meta.total'))->toBe(2);
});
