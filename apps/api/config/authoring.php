<?php

/*
 | Authoring domain configuration (curriculum + media metadata). No business rules here.
 */
return [
    'publish' => [
        // A course may publish only when it has at least one published lesson.
        'require_published_lesson' => true,
    ],
    'preview' => [
        'default' => false,
    ],

    // P2/W02: master switch for the Content Block model (Block/Module aggregates + backfill).
    // Off by default. The backfill — the only path that writes blocks today — and every future
    // block read/write path consult this flag, so the feature is genuinely dormant until enabled.
    'blocks_enabled' => (bool) env('AUTHORING_BLOCKS_ENABLED', false),
];
