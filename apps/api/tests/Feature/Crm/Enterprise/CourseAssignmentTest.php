<?php

declare(strict_types=1);

use App\Contexts\Learning\Models\Enrollment;
use App\Domains\Catalog\Models\Course;
use App\Domains\Crm\Models\Department;
use App\Domains\Crm\Models\Organization;
use App\Domains\Crm\Models\OrganizationMember;
use App\Domains\Crm\Models\Team;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

/**
 * The FREE enterprise course grant — a manager handing out a catalog course with no purchase behind
 * it. It was verified by hand but never pinned down, and the seat portal now shares its target
 * resolution, so these cover the behaviour both surfaces depend on: organization / member /
 * department / team scoping, members without an account, and the organization boundary.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => app(TenantContext::class)->forget());
afterEach(fn () => app(TenantContext::class)->forget());

function assignmentOwner(Organization $org): User
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    OrganizationMember::create([
        'organization_id' => $org->id, 'user_id' => $user->id, 'email' => $user->email,
        'role' => 'owner', 'status' => 'active',
    ]);

    return $user;
}

function assignmentMember(Organization $org, string $email, ?int $departmentId = null, bool $withAccount = true): OrganizationMember
{
    return OrganizationMember::create([
        'organization_id' => $org->id,
        'user_id' => $withAccount ? User::factory()->create()->id : null,
        'email' => $email,
        'role' => 'member',
        'status' => $withAccount ? 'active' : 'active',
        'department_id' => $departmentId,
    ]);
}

it('grants a course to one member', function (): void {
    $org = Organization::factory()->create();
    Sanctum::actingAs(assignmentOwner($org));
    $course = Course::factory()->published()->create();
    $member = assignmentMember($org, 'one@corp.com');

    $this->postJson('/api/v1/enterprise/course-assignments', [
        'course_id' => $course->public_id,
        'target_type' => 'member',
        'target_id' => $member->public_id,
    ])
        ->assertOk()
        ->assertJsonPath('data.summary.assigned', 1)
        ->assertJsonPath('data.summary.already_assigned', 0);

    expect(Enrollment::where('user_id', $member->user_id)->where('course_id', $course->id)->exists())->toBeTrue();
});

it('grants a course to a whole department and skips members without an account', function (): void {
    $org = Organization::factory()->create();
    Sanctum::actingAs(assignmentOwner($org));
    $course = Course::factory()->published()->create();

    $department = Department::create(['organization_id' => $org->id, 'name' => 'Ops']);
    assignmentMember($org, 'ops1@corp.com', (int) $department->getKey());
    assignmentMember($org, 'ops2@corp.com', (int) $department->getKey());
    assignmentMember($org, 'pending@corp.com', (int) $department->getKey(), withAccount: false);
    assignmentMember($org, 'elsewhere@corp.com');

    $this->postJson('/api/v1/enterprise/course-assignments', [
        'course_id' => $course->public_id,
        'target_type' => 'department',
        'target_id' => $department->public_id,
    ])
        ->assertOk()
        ->assertJsonPath('data.summary.matched_members', 3)
        ->assertJsonPath('data.summary.eligible_members', 2)
        ->assertJsonPath('data.summary.assigned', 2)
        ->assertJsonPath('data.summary.skipped_without_account', 1);
});

it('grants a course to a team', function (): void {
    $org = Organization::factory()->create();
    Sanctum::actingAs(assignmentOwner($org));
    $course = Course::factory()->published()->create();

    $team = Team::create(['organization_id' => $org->id, 'name' => 'Squad']);
    $member = assignmentMember($org, 'squad@corp.com');
    $member->teams()->attach($team->getKey());
    assignmentMember($org, 'outside-team@corp.com');

    $this->postJson('/api/v1/enterprise/course-assignments', [
        'course_id' => $course->public_id,
        'target_type' => 'team',
        'target_id' => $team->public_id,
    ])
        ->assertOk()
        ->assertJsonPath('data.summary.assigned', 1);
});

it('grants a course to the whole organization and reports a repeat as already assigned', function (): void {
    $org = Organization::factory()->create();
    Sanctum::actingAs(assignmentOwner($org));
    $course = Course::factory()->published()->create();
    assignmentMember($org, 'all1@corp.com');
    assignmentMember($org, 'all2@corp.com');

    $body = ['course_id' => $course->public_id, 'target_type' => 'organization'];

    // Three members including the owner.
    $this->postJson('/api/v1/enterprise/course-assignments', $body)
        ->assertOk()
        ->assertJsonPath('data.summary.assigned', 3);

    $this->postJson('/api/v1/enterprise/course-assignments', $body)
        ->assertOk()
        ->assertJsonPath('data.summary.assigned', 0)
        ->assertJsonPath('data.summary.already_assigned', 3);
});

it('cannot reach a member of another organization', function (): void {
    $org = Organization::factory()->create();
    Sanctum::actingAs(assignmentOwner($org));
    $course = Course::factory()->published()->create();

    $outsider = assignmentMember(Organization::factory()->create(), 'rival@other.com');

    $this->postJson('/api/v1/enterprise/course-assignments', [
        'course_id' => $course->public_id,
        'target_type' => 'member',
        'target_id' => $outsider->public_id,
    ])->assertStatus(404);

    expect(Enrollment::where('user_id', $outsider->user_id)->exists())->toBeFalse();
});

it('denies a plain member', function (): void {
    $org = Organization::factory()->create();
    $course = Course::factory()->published()->create();
    $member = assignmentMember($org, 'plain@corp.com');
    $user = User::find($member->user_id);
    $user->forceFill(['organization_id' => $org->id])->save();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/enterprise/course-assignments', [
        'course_id' => $course->public_id,
        'target_type' => 'member',
        'target_id' => $member->public_id,
    ])->assertForbidden();
});
