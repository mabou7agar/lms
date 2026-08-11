<?php

use App\Domains\Crm\Services\LeadScoringService;

it('scores a high-intent business lead and clamps at the configured max', function () {
    $score = app(LeadScoringService::class)->score([
        'request_type' => 'demo',        // +40
        'company_size' => '201-500',     // +30
        'utm_medium' => 'cpc',           // +20
        'gclid' => 'abc',                // +15
        'email' => 'dana@acme-corp.com', // +10 (business) ; base +10 => 125 -> clamp 100
    ]);

    expect($score)->toBe(100);
});

it('scores a low-intent free-mail contact lead', function () {
    $score = app(LeadScoringService::class)->score([
        'request_type' => 'contact',     // +10
        'email' => 'someone@gmail.com',  // no business bonus ; base +10 => 20
    ]);

    expect($score)->toBe(20);
});

it('is deterministic for identical input', function () {
    $signals = ['request_type' => 'pricing', 'company_size' => '11-50', 'email' => 'x@corp.io'];
    $service = app(LeadScoringService::class);

    expect($service->score($signals))->toBe($service->score($signals));
});
