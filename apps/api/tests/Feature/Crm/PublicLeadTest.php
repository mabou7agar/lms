<?php

use App\Domains\Crm\Enums\ActivityType;
use App\Domains\Crm\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @return array<string, mixed> */
function publicLeadPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Dana Buyer',
        'work_email' => 'dana@acme-corp.com',
        'company' => 'Acme Corp',
        'phone' => '+201000000000',
        'company_size' => '201-500',
        'country' => 'EG',
        'request_type' => 'demo',
        'message' => 'We want a demo for 400 learners.',
        'source_page' => '/enterprise',
        'utm' => ['source' => 'google', 'medium' => 'cpc', 'campaign' => 'q3', 'term' => 'lms', 'content' => 'hero'],
        'gclid' => 'CjwKCAiA-abc123',
        'referrer' => 'https://www.google.com/',
        'locale' => 'en',
        'marketing_consent' => true,
    ], $overrides);
}

it('accepts a guest submission (no auth), creating a scored lead with consent + timeline', function () {
    $this->postJson('/api/v1/public/leads', publicLeadPayload())
        ->assertCreated()
        ->assertJsonPath('data.status', 'new')
        ->assertJsonStructure(['data' => ['id', 'status'], 'message']);

    $lead = Lead::firstOrFail();
    expect($lead->email)->toBe('dana@acme-corp.com')
        ->and($lead->source)->toBe('enterprise_funnel')
        ->and($lead->marketing_consent)->toBeTrue()
        ->and($lead->consented_at)->not->toBeNull()
        ->and($lead->consent_version)->not->toBeNull()
        ->and($lead->lead_score)->toBeGreaterThan(0)
        ->and($lead->next_follow_up_at)->not->toBeNull();

    // Sales inbox signal + reused LeadCreated listener both write to the timeline.
    expect($lead->activities()->count())->toBeGreaterThanOrEqual(2);
});

it('persists UTM / marketing attribution on the lead', function () {
    $this->postJson('/api/v1/public/leads', publicLeadPayload())->assertCreated();

    $lead = Lead::firstOrFail();
    expect($lead->utm_source)->toBe('google')
        ->and($lead->utm_medium)->toBe('cpc')
        ->and($lead->utm_campaign)->toBe('q3')
        ->and($lead->gclid)->toBe('CjwKCAiA-abc123')
        ->and($lead->landing_path)->toBe('/enterprise');
});

it('rejects a submission whose honeypot is filled and creates no lead', function () {
    $this->postJson('/api/v1/public/leads', publicLeadPayload(['website' => 'http://spam.example']))
        ->assertStatus(422);

    expect(Lead::count())->toBe(0);
});

it('dedupes a repeat submission (same email + company) into an update, not a duplicate', function () {
    $this->postJson('/api/v1/public/leads', publicLeadPayload(['request_type' => 'pricing']))->assertCreated();
    $this->postJson('/api/v1/public/leads', publicLeadPayload(['request_type' => 'demo']))->assertCreated();

    expect(Lead::count())->toBe(1);
    expect(Lead::firstOrFail()->request_type)->toBe('demo'); // updated, not duplicated
});

it('sanitizes free-text so no HTML/script is ever stored', function () {
    $this->postJson('/api/v1/public/leads', publicLeadPayload([
        'message' => 'Hello <script>alert(1)</script> <b>team</b>',
    ]))->assertCreated();

    $note = Lead::firstOrFail()->activities()->where('type', ActivityType::Note->value)->first();
    expect($note)->not->toBeNull()
        ->and($note->description)->not->toContain('<script')
        ->and($note->description)->not->toContain('<b>');
});

it('never trusts a client-supplied tenant/owner/organization_id', function () {
    $this->postJson('/api/v1/public/leads', publicLeadPayload([
        'organization_id' => 999999,
        'owner_id' => 999999,
        'lead_score' => 100000,
    ]))->assertCreated();

    $lead = Lead::firstOrFail();
    expect($lead->owner_id)->toBeNull()          // owner comes from server config only (unset here)
        ->and($lead->lead_score)->toBeLessThanOrEqual(100); // score is computed, not client-supplied
});

it('throttles abusive bursts from the same origin', function () {
    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/api/v1/public/leads', publicLeadPayload())->assertCreated();
    }

    $this->postJson('/api/v1/public/leads', publicLeadPayload())->assertStatus(429);
});
