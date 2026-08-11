<?php

/*
 | Catalog domain configuration (listing, search, related). No business rules here.
 */
return [
    'pagination' => [
        'per_page' => (int) env('CATALOG_PER_PAGE', 15),
        'max_per_page' => 60,
    ],
    'related' => [
        'limit' => 8,
    ],
    'search' => [
        'min_query_length' => 2,
    ],

    // Deterministic course recommendations (RecommendationService). `enabled` is the admin kill
    // switch: when false every recommendation surface returns an empty list.
    'recommendations' => [
        'enabled' => (bool) env('CATALOG_RECOMMENDATIONS_ENABLED', true),
        'limit' => (int) env('CATALOG_RECOMMENDATIONS_LIMIT', 8),
    ],
];
