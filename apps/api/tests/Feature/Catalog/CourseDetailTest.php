<?php

use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Course;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows a published course by public_id with related courses', function () {
    $cat = Category::factory()->create();
    $course = Course::factory()->published()->create(['title' => 'Main Course']);
    $course->categories()->sync([$cat->id]);

    $related = Course::factory()->published()->create(['title' => 'Related Course']);
    $related->categories()->sync([$cat->id]);

    $res = $this->getJson('/api/v1/courses/'.$course->public_id)->assertOk();

    expect($res->json('data.id'))->toBe($course->public_id)
        ->and($res->json('data.title'))->toBe('Main Course')
        ->and(collect($res->json('data.related'))->pluck('title'))->toContain('Related Course');
});

it('shows the same published course by slug', function () {
    $course = Course::factory()->published()->create([
        'title' => 'Enterprise Leadership',
        'slug' => 'enterprise-leadership',
    ]);

    $this->getJson('/api/v1/courses/enterprise-leadership')
        ->assertOk()
        ->assertJsonPath('data.id', $course->public_id)
        ->assertJsonPath('data.slug', 'enterprise-leadership');
});

it('returns 404 for a draft or unknown course', function () {
    $draft = Course::factory()->create();

    $this->getJson('/api/v1/courses/'.$draft->public_id)->assertStatus(404);
    $this->getJson('/api/v1/courses/does-not-exist')->assertStatus(404);
});
