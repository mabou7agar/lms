<?php

use App\Platform\Shared\Time\InvalidTimezoneException;
use App\Platform\Shared\Time\TimezoneResolver;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config(['shared.default_timezone' => 'UTC']);
    $this->tz = new TimezoneResolver();
});

it('accepts only IANA identifiers', function () {
    expect($this->tz->isValid('Africa/Cairo'))->toBeTrue()
        ->and($this->tz->isValid('Asia/Riyadh'))->toBeTrue()
        ->and($this->tz->isValid('Europe/London'))->toBeTrue()
        ->and($this->tz->isValid('UTC'))->toBeTrue()
        ->and($this->tz->isValid('GMT+3'))->toBeFalse()
        ->and($this->tz->isValid('EST'))->toBeFalse()
        ->and($this->tz->isValid('Not/AZone'))->toBeFalse();
});

it('throws on an invalid timezone', function () {
    expect(fn () => $this->tz->assertValid('Mars/Phobos'))->toThrow(InvalidTimezoneException::class);
});

it('converts a Cairo wall clock to the correct UTC instant', function () {
    // Cairo standard time is UTC+2 in January.
    expect($this->tz->toUtc('2026-01-15 10:00:00', 'Africa/Cairo')->toIso8601String())
        ->toBe('2026-01-15T08:00:00+00:00');
});

it('converts a Riyadh wall clock to the correct UTC instant', function () {
    // Riyadh is UTC+3 year-round (no DST).
    expect($this->tz->toUtc('2026-06-15 12:00:00', 'Asia/Riyadh')->toIso8601String())
        ->toBe('2026-06-15T09:00:00+00:00');
});

it('presents a UTC instant in a target zone', function () {
    expect($this->tz->inZone(CarbonImmutable::parse('2026-06-15T09:00:00Z'), 'Asia/Riyadh'))
        ->toBe('2026-06-15T12:00:00+03:00');
});

it('resolves the first valid candidate then the app default', function () {
    expect($this->tz->resolveFor('Africa/Cairo', 'Asia/Riyadh'))->toBe('Africa/Cairo')
        ->and($this->tz->resolveFor(null, 'Asia/Riyadh'))->toBe('Asia/Riyadh')
        ->and($this->tz->resolveFor('not-a-zone', null))->toBe('UTC');
});

it('detects a nonexistent local time in a DST-observing zone', function () {
    // London springs forward 2026-03-29 01:00 -> 02:00, so 01:30 does not exist.
    expect($this->tz->isNonexistent('2026-03-29 01:30:00', 'Europe/London'))->toBeTrue()
        ->and($this->tz->isNonexistent('2026-03-29 03:30:00', 'Europe/London'))->toBeFalse();
});

it('detects an ambiguous local time and resolves it to the earlier instant', function () {
    // London falls back 2026-10-25 02:00 -> 01:00, so 01:30 occurs twice.
    expect($this->tz->isAmbiguous('2026-10-25 01:30:00', 'Europe/London'))->toBeTrue();

    // Earlier occurrence is during BST (UTC+1) => 00:30 UTC.
    expect($this->tz->toUtc('2026-10-25 01:30:00', 'Europe/London')->toIso8601String())
        ->toBe('2026-10-25T00:30:00+00:00');
});

it('a normal wall clock is neither nonexistent nor ambiguous', function () {
    expect($this->tz->isNonexistent('2026-06-15 12:00:00', 'Europe/London'))->toBeFalse()
        ->and($this->tz->isAmbiguous('2026-06-15 12:00:00', 'Europe/London'))->toBeFalse();
});
