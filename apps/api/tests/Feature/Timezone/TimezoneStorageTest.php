<?php

use App\Contexts\Learning\Models\Enrollment;
use App\Domains\Catalog\Models\Course;
use App\Domains\Crm\Models\Organization;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Time\InvalidTimezoneException;
use App\Platform\Shared\Time\TimezoneResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Timezone storage boundary: IANA zone columns persist verbatim, invalid zones are rejected by the
 * resolver, persisted timestamps are UTC, and Cairo/Riyadh wall-clock -> UTC conversion is exact.
 * All assertions use explicit IANA zones and fixed instants — never the machine timezone.
 */
it('persists a valid IANA timezone on users.timezone', function () {
    $user = User::factory()->create(['timezone' => 'Africa/Cairo']);

    expect($user->refresh()->timezone)->toBe('Africa/Cairo');
});

it('persists a valid IANA timezone on crm_organizations.timezone', function () {
    $org = Organization::factory()->create(['timezone' => 'Asia/Riyadh']);

    expect($org->refresh()->timezone)->toBe('Asia/Riyadh');
});

it('rejects an invalid timezone through the resolver', function () {
    $resolver = app(TimezoneResolver::class);

    expect($resolver->isValid('Mars/Phobos'))->toBeFalse()
        ->and($resolver->isValid('UTC+3'))->toBeFalse()
        ->and($resolver->isValid('Africa/Cairo'))->toBeTrue();

    expect(fn () => $resolver->assertValid('Mars/Phobos'))->toThrow(InvalidTimezoneException::class);
    expect(fn () => $resolver->assertValid('UTC+3'))->toThrow(InvalidTimezoneException::class);
});

it('stores a persisted timestamp as UTC in the database', function () {
    $resolver = app(TimezoneResolver::class);
    $course = Course::factory()->create();

    // A Cairo wall clock (winter, +02) persisted as its canonical UTC instant.
    $utc = $resolver->toUtc('2024-01-15 12:00:00', 'Africa/Cairo'); // 2024-01-15 10:00:00 UTC

    $enrollment = Enrollment::factory()->create([
        'course_id' => $course->id,
        'enrolled_at' => $utc,
    ]);

    // The Eloquent-read value is a UTC Carbon at the exact instant.
    expect($enrollment->refresh()->enrolled_at->timezoneName)->toBe('UTC')
        ->and($enrollment->enrolled_at->format('Y-m-d H:i:s'))->toBe('2024-01-15 10:00:00');

    // And the raw column (timestamp without time zone) holds the UTC wall clock, not a shifted one.
    $raw = DB::table('enrollments')->where('id', $enrollment->id)->value('enrolled_at');
    expect(CarbonImmutable::parse($raw, 'UTC')->format('Y-m-d H:i:s'))->toBe('2024-01-15 10:00:00');
});

it('converts Cairo and Riyadh wall clocks to the correct UTC instant', function () {
    $resolver = app(TimezoneResolver::class);

    // Africa/Cairo in January is standard time (+02): 12:00 local -> 10:00 UTC.
    expect($resolver->toUtc('2024-01-15 12:00:00', 'Africa/Cairo')->format('Y-m-d H:i:s'))
        ->toBe('2024-01-15 10:00:00');

    // Asia/Riyadh never observes DST (+03): 12:00 local -> 09:00 UTC, regardless of month.
    expect($resolver->toUtc('2024-06-15 12:00:00', 'Asia/Riyadh')->format('Y-m-d H:i:s'))
        ->toBe('2024-06-15 09:00:00');
    expect($resolver->toUtc('2024-01-15 12:00:00', 'Asia/Riyadh')->format('Y-m-d H:i:s'))
        ->toBe('2024-01-15 09:00:00');

    // The resolver always yields a UTC instant.
    expect($resolver->toUtc('2024-06-15 12:00:00', 'Asia/Riyadh')->timezoneName)->toBe('UTC');
});
