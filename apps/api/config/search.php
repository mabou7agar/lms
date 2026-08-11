<?php

declare(strict_types=1);

/*
 | Search + RAG configuration.
 |
 | The vector store is pgvector-OPTIONAL. The DEFAULT driver is the portable store, which pre-filters
 | candidate rows in SQL (tenant / visibility / locale / source_type) and computes cosine similarity
 | in PHP over the candidate set. It needs no database extension and runs on the same Postgres the
 | platform already ships (which has NO pgvector). Switching `driver` to `pgvector` selects the
 | PgVectorStore, which is a guarded stub until the extension + an ANN index migration are provisioned
 | (LOCAL / INFRA required) — it throws a clear error rather than silently degrading.
 |
 | Embeddings run ONLY through the AI EmbeddingModel contract, which defaults to the deterministic
 | FAKE provider (config/ai.php). No search path ever reaches the network.
 */

return [

    'vector' => [
        // portable (default, no extension) | pgvector (LOCAL/INFRA required — guarded stub).
        'driver' => env('SEARCH_VECTOR_DRIVER', 'portable'),

        // Upper bound on candidate rows the portable store pulls into PHP before scoring. This is the
        // documented scaling limit of the portable driver: cosine is O(candidates * dims) in PHP, so
        // the pre-filter must stay selective. At true catalog scale, switch to the pgvector driver.
        'max_candidates' => (int) env('SEARCH_VECTOR_MAX_CANDIDATES', 2000),

        // Minimum cosine similarity for a semantic hit to count. Deterministic fake vectors are
        // L2-normalised, so unrelated 128-dim texts score ~0 (std ~0.09) and an exact (canonicalised)
        // match scores ~1 — 0.5 sits well clear of the noise floor. A REAL embedding model expresses
        // graded similarity; tune this down (e.g. 0.2-0.3) when a real provider is configured.
        'min_similarity' => (float) env('SEARCH_VECTOR_MIN_SIMILARITY', 0.5),
    ],

    'embedding' => [
        // Canonicalise text (normalise -> tokenise -> unique -> sort) BEFORE embedding. This makes
        // bag-of-words-equivalent phrases (word reorderings) collide under the deterministic FAKE
        // provider, so the semantic arm demonstrably retrieves reordered paraphrases the keyword arm
        // misses. A REAL embedding model captures true synonymy/paraphrase and does NOT need this;
        // set false when a real provider is configured. Kept true by default (fake-first).
        'canonicalize' => (bool) env('SEARCH_EMBEDDING_CANONICALIZE', true),

        // Hard cap on characters embedded per chunk (defensive; keeps token estimates bounded).
        'max_chars' => (int) env('SEARCH_EMBEDDING_MAX_CHARS', 4000),
    ],

    'hybrid' => [
        // Fusion weights for the weighted-sum of the semantic + keyword arms.
        'semantic_weight' => (float) env('SEARCH_SEMANTIC_WEIGHT', 0.6),
        'keyword_weight' => (float) env('SEARCH_KEYWORD_WEIGHT', 0.4),
    ],

    'limits' => [
        'default_limit' => (int) env('SEARCH_DEFAULT_LIMIT', 20),
        'max_limit' => (int) env('SEARCH_MAX_LIMIT', 50),
        // Minimum normalised query length before the query runs (mirrors catalog.search).
        'min_query_length' => (int) env('SEARCH_MIN_QUERY_LENGTH', 2),
    ],

    // Async (re)embedding queue. A dedicated queue keeps embedding work off the request path and
    // out of the way of latency-sensitive jobs.
    'queue' => env('SEARCH_QUEUE', 'search'),

];
