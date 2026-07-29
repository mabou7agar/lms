<?php

use App\Domains\Live\Models\LiveSession;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Sprint 7 — the audit's "N+1 user lookup in EventListResource". The public events list resolved
 * speaker names with one UserLookupPort::refsByIds() call PER event. It now batches the whole page
 * into a single lookup. These pin that the query count no longer scales with the number of events
 * and that the speaker output is unchanged.
 */
function eventWithSpeakers(int $speakers): LiveSession
{
    $session = LiveSession::factory()->create();
    $session->syncTrainers(User::factory()->count($speakers)->create()->pluck('id')->all());

    return $session;
}

it('lists events with a query count that does not scale with the number of events', function () {
    foreach (range(1, 5) as $ignored) {
        eventWithSpeakers(2);
    }

    // Warm up first-request initialization so the two measurements compare like for like.
    $this->getJson('/api/v1/events?filter=upcoming&per_page=20')->assertOk();

    DB::enableQueryLog();
    $this->getJson('/api/v1/events?filter=upcoming&per_page=20')->assertOk();
    $fiveEvents = count(DB::getQueryLog());

    // Double the events (still one page). Create them BEFORE the second measurement window: the
    // first sample is clean because its fixtures predate enableQueryLog(), so the second must be
    // made symmetric the same way. flushQueryLog() only empties the buffer (it does NOT stop
    // logging), so the flush is placed AFTER this loop to discard the fixture INSERT/SELECT
    // statements — leaving $tenEvents counting only the endpoint's page-wide queries.
    foreach (range(1, 5) as $ignored) {
        eventWithSpeakers(2);
    }

    DB::flushQueryLog();
    $this->getJson('/api/v1/events?filter=upcoming&per_page=20')->assertOk();
    $tenEvents = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($tenEvents)->toBe($fiveEvents);
});

it('renders each event\'s speakers on the list unchanged', function () {
    $event = LiveSession::factory()->create();
    $ada = User::factory()->create(['name' => 'Ada Speaker']);
    $bob = User::factory()->create(['name' => 'Bob Speaker']);
    $event->syncTrainers([$ada->id, $bob->id]);

    $res = $this->getJson('/api/v1/events?filter=upcoming')->assertOk();
    $row = collect($res->json('data'))->firstWhere('id', $event->public_id);

    expect($row)->not->toBeNull();
    $names = collect($row['speakers'])->pluck('name');
    expect($names)->toHaveCount(2)
        ->and($names)->toContain('Ada Speaker')
        ->and($names)->toContain('Bob Speaker');
});
