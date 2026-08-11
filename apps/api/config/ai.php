<?php

declare(strict_types=1);

/*
 | Provider-neutral AI foundation configuration.
 |
 | Mirrors the commerce gateway / SSO convention: providers are selected from config, default to the
 | deterministic `fake` provider, and `fake` is refused in production (ProductionConfigValidator +
 | AiProviderManager) unless AI_ALLOW_FAKE is set. Secrets come from env and are read ONLY by the
 | concrete provider adapters — never printed, never surfaced in the admin panel.
 |
 | Real providers are LOCAL REQUIRED: their adapters only reach the network when credentials are
 | present, and throw ProviderCredentialsRequiredException otherwise.
 */

return [
    // Master switch. When false, every AI call fails closed (AiDisabledException).
    'enabled' => (bool) env('AI_ENABLED', true),

    // Default provider when a caller does not specify one. `fake` runs everywhere without creds.
    'default_provider' => env('AI_PROVIDER', 'fake'),

    // Permit the fake provider in production (a deliberate non-AI preview environment only).
    'allow_fake' => (bool) env('AI_ALLOW_FAKE', false),

    // Cache TTL (seconds) for cacheable AI reads (e.g. resolved prompts) — consumed by later features.
    'cache_ttl' => (int) env('AI_CACHE_TTL', 3600),

    // The disclosure label attached to every AI-produced result.
    'content_label' => env('AI_CONTENT_LABEL', 'AI-generated'),

    // Provider adapters. Secrets are env-only; a missing key makes a real provider LOCAL REQUIRED.
    'providers' => [

        'fake' => [
            'enabled' => (bool) env('AI_FAKE_ENABLED', true),
            'chat_model' => 'fake-chat-v1',
            'embedding_model' => 'fake-embed-v1',
            'embedding_dimensions' => (int) env('AI_FAKE_EMBED_DIMS', 128),
        ],

        'openai' => [
            'enabled' => (bool) env('AI_OPENAI_ENABLED', false),
            'api_key' => env('OPENAI_API_KEY'),
            'organization' => env('OPENAI_ORGANIZATION'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'chat_model' => env('OPENAI_CHAT_MODEL', 'gpt-4o-mini'),
            'embedding_model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
        ],

        'anthropic' => [
            'enabled' => (bool) env('AI_ANTHROPIC_ENABLED', false),
            'api_key' => env('ANTHROPIC_API_KEY'),
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
            'version' => env('ANTHROPIC_VERSION', '2023-06-01'),
            'chat_model' => env('ANTHROPIC_CHAT_MODEL', 'claude-3-5-sonnet-latest'),
        ],

        'gemini' => [
            'enabled' => (bool) env('AI_GEMINI_ENABLED', false),
            'api_key' => env('GEMINI_API_KEY'),
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
            'chat_model' => env('GEMINI_CHAT_MODEL', 'gemini-1.5-flash'),
            'embedding_model' => env('GEMINI_EMBEDDING_MODEL', 'text-embedding-004'),
        ],

        'openrouter' => [
            'enabled' => (bool) env('AI_OPENROUTER_ENABLED', false),
            'api_key' => env('OPENROUTER_API_KEY'),
            'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
            'chat_model' => env('OPENROUTER_CHAT_MODEL', 'openai/gpt-4o-mini'),
        ],

        'ollama' => [
            'enabled' => (bool) env('AI_OLLAMA_ENABLED', false),
            // Keyless local daemon — the "credential" is a reachable base URL.
            'base_url' => env('AI_OLLAMA_BASE_URL', 'http://localhost:11434'),
            'chat_model' => env('OLLAMA_CHAT_MODEL', 'llama3.1'),
            'embedding_model' => env('OLLAMA_EMBEDDING_MODEL', 'nomic-embed-text'),
        ],
    ],

    // Provider-neutral call defaults.
    'defaults' => [
        'temperature' => (float) env('AI_TEMPERATURE', 0.7),
        'max_tokens' => (int) env('AI_MAX_TOKENS', 1024),
        'timeout' => (int) env('AI_TIMEOUT', 30),
        'retries' => (int) env('AI_RETRIES', 2),
    ],

    // Usage ceilings (tokens). 0 = unlimited. Enforced BEFORE a call by AiQuotaGuard.
    'limits' => [
        'max_tokens_per_request' => (int) env('AI_MAX_TOKENS_PER_REQUEST', 8000),
        'max_output_tokens' => (int) env('AI_MAX_OUTPUT_TOKENS', 2048),
        'per_user_daily_tokens' => (int) env('AI_PER_USER_DAILY_TOKENS', 200000),
        'per_org_monthly_tokens' => (int) env('AI_PER_ORG_MONTHLY_TOKENS', 5000000),
        'global_monthly_tokens' => (int) env('AI_GLOBAL_MONTHLY_TOKENS', 100000000),
    ],

    // Governance kill-switches (config-driven; absent => enabled). Fail-closed in AiGovernance.
    'features' => [
        'tutor' => (bool) env('AI_FEATURE_TUTOR', true),
        'copilot' => (bool) env('AI_FEATURE_COPILOT', true),
        'admin_assistant' => (bool) env('AI_FEATURE_ADMIN_ASSISTANT', true),
        'analytics' => (bool) env('AI_FEATURE_ANALYTICS', true),
        'search' => (bool) env('AI_FEATURE_SEARCH', true),
        'embedding' => (bool) env('AI_FEATURE_EMBEDDING', true),
        'other' => (bool) env('AI_FEATURE_OTHER', true),
    ],

    // RAG grounding: how many retrieved chunks the tutor/copilot feed the model as context +
    // return as citations. Consumed by TutorService / CopilotService; retrieval itself is always
    // course-, tenant- and visibility-scoped by the KnowledgeRetrievalPort.
    'retrieval' => [
        'tutor_snippets' => (int) env('AI_TUTOR_SNIPPETS', 5),
        'copilot_snippets' => (int) env('AI_COPILOT_SNIPPETS', 6),
    ],

    // Admin AI Analytics Assistant grounding knobs. The AGGREGATE KPI summary (via the Shared
    // AnalyticsSummaryPort) covers the trailing window and lists at most this many top courses.
    // These bound the grounded context only; tenant scope + money gating are enforced elsewhere.
    'assistant' => [
        'summary_window_days' => (int) env('AI_ASSISTANT_SUMMARY_WINDOW_DAYS', 30),
        'top_courses' => (int) env('AI_ASSISTANT_TOP_COURSES', 5),
    ],

    // Per-tenant enable/disable: [organizationId => bool]. Absent => enabled.
    'tenant_overrides' => [],

    // Per-course enable/disable: [courseId => bool]. Absent => enabled.
    'course_overrides' => [],

    // Model allow-list + capabilities per provider (ModelRegistry). A model not listed is refused.
    'models' => [
        'fake' => [
            'fake-chat-v1' => ['chat' => true, 'embedding' => false],
            'fake-embed-v1' => ['chat' => false, 'embedding' => true],
        ],
        'openai' => [
            'gpt-4o-mini' => ['chat' => true, 'embedding' => false],
            'gpt-4o' => ['chat' => true, 'embedding' => false],
            'text-embedding-3-small' => ['chat' => false, 'embedding' => true],
            'text-embedding-3-large' => ['chat' => false, 'embedding' => true],
        ],
        'anthropic' => [
            'claude-3-5-sonnet-latest' => ['chat' => true, 'embedding' => false],
            'claude-3-5-haiku-latest' => ['chat' => true, 'embedding' => false],
        ],
        'gemini' => [
            'gemini-1.5-flash' => ['chat' => true, 'embedding' => false],
            'gemini-1.5-pro' => ['chat' => true, 'embedding' => false],
            'text-embedding-004' => ['chat' => false, 'embedding' => true],
        ],
        'openrouter' => [
            'openai/gpt-4o-mini' => ['chat' => true, 'embedding' => false],
        ],
        'ollama' => [
            'llama3.1' => ['chat' => true, 'embedding' => false],
            'nomic-embed-text' => ['chat' => false, 'embedding' => true],
        ],
    ],

    // Cost table: USD micros (1e-6 USD) per 1,000 tokens, per provider/model (input/output). Used by
    // CostCalculator to estimate spend. Unknown models cost 0.
    'pricing' => [
        'fake' => [
            'fake-chat-v1' => ['input' => 0, 'output' => 0],
            'fake-embed-v1' => ['input' => 0, 'output' => 0],
        ],
        'openai' => [
            'gpt-4o-mini' => ['input' => 150, 'output' => 600],
            'gpt-4o' => ['input' => 2500, 'output' => 10000],
            'text-embedding-3-small' => ['input' => 20, 'output' => 0],
            'text-embedding-3-large' => ['input' => 130, 'output' => 0],
        ],
        'anthropic' => [
            'claude-3-5-sonnet-latest' => ['input' => 3000, 'output' => 15000],
            'claude-3-5-haiku-latest' => ['input' => 800, 'output' => 4000],
        ],
        'gemini' => [
            'gemini-1.5-flash' => ['input' => 75, 'output' => 300],
            'gemini-1.5-pro' => ['input' => 1250, 'output' => 5000],
            'text-embedding-004' => ['input' => 0, 'output' => 0],
        ],
        'openrouter' => [
            'openai/gpt-4o-mini' => ['input' => 150, 'output' => 600],
        ],
        'ollama' => [
            'llama3.1' => ['input' => 0, 'output' => 0],
            'nomic-embed-text' => ['input' => 0, 'output' => 0],
        ],
    ],
];
