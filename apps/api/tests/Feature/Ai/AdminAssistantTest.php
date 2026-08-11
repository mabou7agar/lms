<?php

declare(strict_types=1);

use App\Contexts\Analytics\Database\Seeders\AnalyticsSeeder;
use App\Contexts\Analytics\Enums\AnalyticsPermission;
use App\Contexts\Analytics\Models\MetricSnapshot;
use App\Platform\AI\Models\AiPrompt;
use App\Platform\AI\Models\AiUsage;
use App\Platform\Identity\Database\Seeders\IdentitySeeder;
use App\Platform\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * The ADMIN AI ANALYTICS ASSISTANT. Mirrors the tutor/copilot feature tests: FAKE provider only,
 * no network (Http::preventStrayRequests), governance + quota fail-closed, and the analytics money
 * gate + tenant scope + no-PII invariants pinned end-to-end.
 */
beforeEach(function (): void {
    // Roles + analytics permissions (admin gets all incl. revenue; instructor gets view only).
    $this->seed(IdentitySeeder::class);
    $this->seed(AnalyticsSeeder::class);

    config([
        'ai.enabled' => true,
        'ai.default_provider' => 'fake',
        'ai.features.admin_assistant' => true,
    ]);
    Http::preventStrayRequests();

    // The versioned prompt the assistant resolves (feature tests seed their own; never the seeder).
    AiPrompt::factory()->create([
        'key' => 'admin.analytics', 'version' => 1, 'active' => true, 'locale' => 'en',
        'system_prompt' => 'You are an analytics assistant. Use ONLY the summary. Never reveal individual learners.',
        'user_template' => 'Scope: {{ scope }} ({{ from }}..{{ to }}) Summary: {{ summary }} Question: {{ question }}',
    ]);
});

function adminAssistantUser(string $role, ?int $organizationId = null): User
{
    $user = User::factory()->create($organizationId === null ? [] : ['organization_id' => $organizationId]);
    $user->assignRole(SpatieRole::findByName($role, 'web'));

    return $user;
}

/** Seed a small set of GLOBAL (platform-wide) aggregate snapshots for the trailing window. */
function seedSummaryMetrics(): void
{
    $period = CarbonImmutable::now()->toDateString();

    foreach ([['enrollments', 40], ['completions', 10], ['signups', 25], ['revenue', 500000]] as [$key, $value]) {
        MetricSnapshot::factory()->create(['metric_key' => $key, 'period' => $period, 'value' => $value]);
    }
}

it('answers an admin with a grounded aggregate-KPI summary and records usage against the fake provider', function (): void {
    seedSummaryMetrics();
    $admin = adminAssistantUser('admin');

    $data = $this->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/ai/admin-assistant', ['question' => 'How are enrollments and completions trending?'])
        ->assertOk()
        ->json('data');

    expect($data['refused'])->toBeFalse()
        ->and($data['label'])->toBe('AI-generated')
        ->and($data['metrics_used'])->toContain('enrollments')
        ->and($data['metrics_used'])->toContain('completions')
        ->and($data['summary']['metrics']['enrollments']['value'])->toBe(40)
        ->and($data['summary']['metrics']['completions']['value'])->toBe(10)
        // The fake provider echoes the grounded context, so the answer cites the aggregate figures.
        ->and($data['answer'])->toContain('40');

    // Usage recorded against the admin_assistant feature with real tokens; no network hit.
    $row = AiUsage::query()->where('feature', 'admin_assistant')->firstOrFail();
    expect($row->prompt_key)->toBe('admin.analytics')
        ->and($row->user_id)->toBe($admin->id)
        ->and($row->input_tokens)->toBeGreaterThan(0)
        ->and($row->request_id)->toBe($data['request_id']);
});

it('blocks a non-administrator (403) and never reaches the provider', function (string $role): void {
    seedSummaryMetrics();
    $user = adminAssistantUser($role);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/ai/admin-assistant', ['question' => 'Give me the platform KPIs please'])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'ANALYTICS_ADMIN_REQUIRED');

    // An instructor holds analytics.view but is not an administrator; a student holds nothing.
    expect(AiUsage::query()->count())->toBe(0);
})->with(['student', 'instructor']);

it('refuses an unauthenticated caller', function (): void {
    $this->postJson('/api/v1/ai/admin-assistant', ['question' => 'What are the KPIs?'])
        ->assertUnauthorized();
});

it('withholds revenue from an admin who lacks the money permission (summary omits it, answer cannot cite it)', function (): void {
    seedSummaryMetrics();

    // Mirror the analytics money gate: revoke the revenue permission from the admin role.
    SpatieRole::findByName('admin', 'web')->revokePermissionTo(AnalyticsPermission::ViewRevenue->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $admin = adminAssistantUser('admin');
    expect($admin->hasPermission(AnalyticsPermission::ViewRevenue->value))->toBeFalse();

    $data = $this->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/ai/admin-assistant', ['question' => 'What is our revenue this month?'])
        ->assertOk()
        ->json('data');

    expect($data['money_included'])->toBeFalse()
        ->and($data['metrics_used'])->not->toContain('revenue')
        ->and($data['summary']['metrics'])->not->toHaveKey('revenue')
        // The revenue sentinel is never in the grounding, so the answer cannot echo it.
        ->and($data['answer'])->not->toContain('500000');
});

it('scopes an org-admin answer to their own org and never another tenant\'s numbers', function (): void {
    $period = CarbonImmutable::now()->toDateString();

    MetricSnapshot::factory()->create(['metric_key' => 'enrollments', 'period' => $period, 'value' => 7]);                 // global
    MetricSnapshot::factory()->forOrganization(1)->create(['metric_key' => 'enrollments', 'period' => $period, 'value' => 30]);
    MetricSnapshot::factory()->forOrganization(2)->create(['metric_key' => 'enrollments', 'period' => $period, 'value' => 99999]); // other tenant sentinel

    // The org-admin belongs to organization 1 — ResolveTenant derives the tenant from the user.
    $admin = adminAssistantUser('admin', organizationId: 1);

    $data = $this->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/ai/admin-assistant', ['question' => 'How many enrollments do we have?'])
        ->assertOk()
        ->json('data');

    // Own org + global only: 7 + 30 = 37. Never org 2's 99999.
    expect($data['summary']['metrics']['enrollments']['value'])->toBe(37)
        ->and($data['answer'])->not->toContain('99999');
});

it('returns a clear disabled response when the assistant feature is governance-disabled', function (): void {
    config(['ai.features.admin_assistant' => false]);
    seedSummaryMetrics();
    $admin = adminAssistantUser('admin');

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/ai/admin-assistant', ['question' => 'Show me the platform KPIs'])
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'AI_FEATURE_DISABLED')
        ->assertJsonPath('error.details.reason', 'feature');

    expect(AiUsage::query()->count())->toBe(0);
});

it('blocks the call when the token quota is exhausted, before any provider spend', function (): void {
    config(['ai.limits.max_tokens_per_request' => 1]);
    seedSummaryMetrics();
    $admin = adminAssistantUser('admin');

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/ai/admin-assistant', ['question' => 'What are the platform KPIs right now?'])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'AI_QUOTA_EXCEEDED');

    // Quota trips BEFORE the provider, so no usage row is written.
    expect(AiUsage::query()->count())->toBe(0);
});

it('never exposes individual-learner PII in the summary or the answer', function (): void {
    seedSummaryMetrics();

    // A learner whose email must never surface in an aggregate analytics answer.
    User::factory()->create(['email' => 'secret-learner@private.example']);

    $admin = adminAssistantUser('admin');

    $body = $this->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/ai/admin-assistant', ['question' => 'Summarize learner activity for me'])
        ->assertOk()
        ->getContent();

    expect($body)->not->toContain('secret-learner@private.example');
});
