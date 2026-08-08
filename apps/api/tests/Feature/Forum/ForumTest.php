<?php

declare(strict_types=1);

use App\Contexts\Learning\Models\Enrollment;
use App\Domains\Catalog\Models\Course;
use App\Domains\Forum\Models\ForumPost;
use App\Domains\Forum\Models\ForumThread;
use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Enums\Role;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Moderation\Enums\ReportReason;
use App\Platform\Shared\Tenancy\TenantContext;
use App\Platform\Shared\Tenancy\TenantId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role as SpatieRole;

uses(RefreshDatabase::class);

/**
 * Forum domain feature suite. Assumes ForumServiceProvider is registered (integration step) so its
 * migrations/routes/policies are active. Tenancy is asserted at the model and HTTP boundary; the
 * tenant is only ever resolved server-side (never from client input).
 */
beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    app(TenantContext::class)->forget();
});

afterEach(fn () => app(TenantContext::class)->forget());

// ── helpers ──────────────────────────────────────────────────────────────────

function forumInstructor(): User
{
    $user = User::factory()->create();
    $user->assignRole(SpatieRole::findByName(Role::Instructor->value, 'web'));

    return $user;
}

function forumCourse(?User $instructor = null): Course
{
    $course = Course::factory()->published()->create();

    if ($instructor !== null) {
        $course->syncTrainers([$instructor->id]);
    }

    return $course;
}

function forumLearner(Course $course): User
{
    $learner = User::factory()->create();
    Enrollment::factory()->create(['user_id' => $learner->id, 'course_id' => $course->id]);

    return $learner;
}

function makeThread(Course $course, User $author, array $attrs = []): ForumThread
{
    return ForumThread::factory()->create(array_merge([
        'course_id' => $course->id,
        'user_id' => $author->id,
    ], $attrs));
}

// ── creation + participation ──────────────────────────────────────────────────

it('lets an enrolled learner create a thread', function (): void {
    $course = forumCourse();
    $learner = forumLearner($course);

    Sanctum::actingAs($learner);
    $res = $this->postJson("/api/v1/courses/{$course->public_id}/forum/threads", [
        'title' => 'How do I start?',
        'body' => '<p>Hello class</p>',
    ])->assertCreated();

    expect($res->json('data.title'))->toBe('How do I start?')
        ->and($res->json('data.author.public_id'))->toBe($learner->public_id);

    $this->assertDatabaseHas('forum_threads', [
        'course_id' => $course->id,
        'user_id' => $learner->id,
        'title' => 'How do I start?',
    ]);
});

it('forbids a non-enrolled user from creating a thread', function (): void {
    $course = forumCourse();

    Sanctum::actingAs(User::factory()->create());
    $this->postJson("/api/v1/courses/{$course->public_id}/forum/threads", [
        'title' => 'Sneaky', 'body' => '<p>x</p>',
    ])->assertForbidden();

    $this->assertDatabaseCount('forum_threads', 0);
});

it('lets an enrolled learner reply and maintains posts_count + last_post_at', function (): void {
    $course = forumCourse();
    $author = forumLearner($course);
    $thread = makeThread($course, $author, ['last_post_at' => now()->subDay(), 'posts_count' => 0]);

    $learner = forumLearner($course);
    Sanctum::actingAs($learner);

    $res = $this->postJson("/api/v1/forum/threads/{$thread->public_id}/posts", [
        'body' => '<p>Try the intro lesson</p>',
    ])->assertCreated();

    expect($res->json('data.is_instructor'))->toBeFalse();

    $fresh = $thread->fresh();
    expect((int) $fresh->posts_count)->toBe(1)
        ->and($fresh->last_post_at->greaterThan(now()->subMinute()))->toBeTrue();
});

// ── locking ────────────────────────────────────────────────────────────────────

it('blocks a learner from replying to a locked thread but allows an instructor', function (): void {
    $instructor = forumInstructor();
    $course = forumCourse($instructor);
    $author = forumLearner($course);
    $thread = makeThread($course, $author, ['locked_at' => now()]);

    $learner = forumLearner($course);
    Sanctum::actingAs($learner);
    $this->postJson("/api/v1/forum/threads/{$thread->public_id}/posts", ['body' => '<p>nope</p>'])
        ->assertForbidden();

    Sanctum::actingAs($instructor);
    $res = $this->postJson("/api/v1/forum/threads/{$thread->public_id}/posts", ['body' => '<p>closing note</p>'])
        ->assertCreated();

    expect($res->json('data.is_instructor'))->toBeTrue();
});

// ── moderation authorization ────────────────────────────────────────────────────

it('requires an instructor to pin, lock and mark solved (learner is forbidden)', function (): void {
    $instructor = forumInstructor();
    $course = forumCourse($instructor);
    $author = forumLearner($course);
    $thread = makeThread($course, $author);

    $learner = forumLearner($course);
    Sanctum::actingAs($learner);
    $this->postJson("/api/v1/forum/threads/{$thread->public_id}/pin")->assertForbidden();
    $this->postJson("/api/v1/forum/threads/{$thread->public_id}/lock")->assertForbidden();
    $this->postJson("/api/v1/forum/threads/{$thread->public_id}/solve")->assertForbidden();

    Sanctum::actingAs($instructor);
    $this->postJson("/api/v1/forum/threads/{$thread->public_id}/pin")->assertOk();
    expect($thread->fresh()->isPinned())->toBeTrue();

    // accept a learner's answer
    $answer = ForumPost::factory()->create(['thread_id' => $thread->id, 'user_id' => $author->id]);
    $this->postJson("/api/v1/forum/threads/{$thread->public_id}/solve", ['post_id' => $answer->public_id])->assertOk();
    expect((int) $thread->fresh()->solved_post_id)->toBe($answer->id);
});

// ── nesting cap ──────────────────────────────────────────────────────────────────

it('caps reply nesting at one level', function (): void {
    $course = forumCourse();
    $author = forumLearner($course);
    $thread = makeThread($course, $author);

    $learner = forumLearner($course);
    Sanctum::actingAs($learner);

    // Top-level post.
    $top = $this->postJson("/api/v1/forum/threads/{$thread->public_id}/posts", ['body' => '<p>top</p>'])
        ->assertCreated()->json('data.id');

    // A reply to the top-level post is allowed (depth 1).
    $reply = $this->postJson("/api/v1/forum/threads/{$thread->public_id}/posts", [
        'body' => '<p>reply</p>', 'parent_id' => $top,
    ])->assertCreated()->json('data.id');

    // A reply to that reply is rejected (would be depth 2).
    $this->postJson("/api/v1/forum/threads/{$thread->public_id}/posts", [
        'body' => '<p>nested</p>', 'parent_id' => $reply,
    ])->assertStatus(422);
});

// ── ownership / IDOR ─────────────────────────────────────────────────────────────

it('lets only the author (or an instructor) update or delete their thread', function (): void {
    $course = forumCourse();
    $author = forumLearner($course);
    $thread = makeThread($course, $author);

    $other = forumLearner($course);
    Sanctum::actingAs($other);
    $this->patchJson("/api/v1/forum/threads/{$thread->public_id}", ['title' => 'hijack'])->assertForbidden();
    $this->deleteJson("/api/v1/forum/threads/{$thread->public_id}")->assertForbidden();

    Sanctum::actingAs($author);
    $this->patchJson("/api/v1/forum/threads/{$thread->public_id}", ['title' => 'edited'])->assertOk();
    expect($thread->fresh()->title)->toBe('edited');
    $this->deleteJson("/api/v1/forum/threads/{$thread->public_id}")->assertOk();
    expect($thread->fresh()->trashed())->toBeTrue();
});

it('lets only the author (or an instructor) update or delete their post', function (): void {
    $course = forumCourse();
    $author = forumLearner($course);
    $thread = makeThread($course, $author);
    $post = ForumPost::factory()->create(['thread_id' => $thread->id, 'user_id' => $author->id]);

    $other = forumLearner($course);
    Sanctum::actingAs($other);
    $this->patchJson("/api/v1/forum/posts/{$post->public_id}", ['body' => '<p>hijack</p>'])->assertForbidden();
    $this->deleteJson("/api/v1/forum/posts/{$post->public_id}")->assertForbidden();

    Sanctum::actingAs($author);
    $this->patchJson("/api/v1/forum/posts/{$post->public_id}", ['body' => '<p>mine</p>'])->assertOk();
    $this->deleteJson("/api/v1/forum/posts/{$post->public_id}")->assertOk();
});

// ── tenancy ───────────────────────────────────────────────────────────────────

it('hides an org1 thread from a resolved org2 tenant at the model boundary', function (): void {
    // Course owned by org1 (stamped server-side by the tenant context).
    app(TenantContext::class)->set(TenantId::from(1));
    $org1Course = Course::factory()->published()->create();
    app(TenantContext::class)->forget();

    $author = User::factory()->create();
    $thread = ForumThread::factory()->create(['course_id' => $org1Course->id, 'user_id' => $author->id]);

    // Under org2 the org1-private course — and its thread — are invisible.
    app(TenantContext::class)->set(TenantId::from(2));

    expect(ForumThread::count())->toBe(0)
        ->and(ForumThread::find($thread->id))->toBeNull();

    // ... but visible again to org1.
    app(TenantContext::class)->set(TenantId::from(1));
    expect(ForumThread::find($thread->id))->not->toBeNull();
});

it('stamps organization_id on a thread server-side from the resolved tenant', function (): void {
    app(TenantContext::class)->set(TenantId::from(1));
    $course = Course::factory()->published()->create(); // org1-private

    $learner = User::factory()->create(['organization_id' => 1]);
    Enrollment::factory()->create(['user_id' => $learner->id, 'course_id' => $course->id]);

    Sanctum::actingAs($learner);
    app(TenantContext::class)->set(TenantId::from(1)); // re-arm after acting

    $res = $this->postJson("/api/v1/courses/{$course->public_id}/forum/threads", [
        'title' => 'Org scoped',
        'body' => '<p>hi</p>',
        'organization_id' => 2, // forged — must be ignored (not fillable, stamped from course)
    ])->assertCreated();

    $thread = ForumThread::withoutGlobalScopes()->where('public_id', $res->json('data.id'))->firstOrFail();

    expect((int) $thread->organization_id)->toBe(1);
});

it('makes an org1 course forum uncreatable under an org2 tenant (HTTP)', function (): void {
    app(TenantContext::class)->set(TenantId::from(1));
    $org1Course = Course::factory()->published()->create();
    app(TenantContext::class)->forget();

    $org2User = User::factory()->create(['organization_id' => 2]);
    Sanctum::actingAs($org2User);
    app(TenantContext::class)->set(TenantId::from(2));

    // The org1-private course does not resolve under org2 => 404 (no existence leak).
    $this->postJson("/api/v1/courses/{$org1Course->public_id}/forum/threads", [
        'title' => 'cross-tenant', 'body' => '<p>x</p>',
    ])->assertNotFound();

    $this->assertDatabaseCount('forum_threads', 0);
});

// ── sanitization ─────────────────────────────────────────────────────────────

it('strips <script> from a thread body on write', function (): void {
    $course = forumCourse();
    $learner = forumLearner($course);

    Sanctum::actingAs($learner);
    $res = $this->postJson("/api/v1/courses/{$course->public_id}/forum/threads", [
        'title' => 'xss',
        'body' => '<p>hello</p><script>alert(1)</script>',
    ])->assertCreated();

    $thread = ForumThread::query()->where('public_id', $res->json('data.id'))->firstOrFail();

    expect($thread->body)->toContain('hello')
        ->and($thread->body)->not->toContain('<script>')
        ->and($thread->body)->not->toContain('alert(1)');
});

// ── reporting ───────────────────────────────────────────────────────────────

it('creates a content report when a participant reports a thread', function (): void {
    $course = forumCourse();
    $author = forumLearner($course);
    $thread = makeThread($course, $author);

    $reporter = forumLearner($course);
    Sanctum::actingAs($reporter);

    $this->postJson("/api/v1/forum/threads/{$thread->public_id}/report", [
        'reason' => ReportReason::cases()[0]->value,
    ])->assertStatus(201);

    expect($thread->reports()->count())->toBe(1);
});
