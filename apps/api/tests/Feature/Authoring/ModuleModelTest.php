<?php

use App\Domains\Authoring\Exceptions\CrossCourseReferenceException;
use App\Domains\Authoring\Exceptions\InvalidCurriculumException;
use App\Domains\Authoring\Models\Module;
use App\Domains\Catalog\Models\Course;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('nests children ordered by position within a course', function () {
    $course = Course::factory()->create();
    $root = Module::factory()->create(['course_id' => $course->id]);
    $second = Module::factory()->childOf($root)->create(['position' => 2]);
    $first = Module::factory()->childOf($root)->create(['position' => 1]);

    expect($root->children()->pluck('id')->all())->toBe([$first->id, $second->id])
        ->and($first->parent->is($root))->toBeTrue();
});

it('rejects a parent in a different course', function () {
    $courseA = Course::factory()->create();
    $courseB = Course::factory()->create();
    $parent = Module::factory()->create(['course_id' => $courseA->id]);

    expect(fn () => Module::factory()->create([
        'course_id' => $courseB->id,
        'parent_id' => $parent->id,
    ]))->toThrow(CrossCourseReferenceException::class);
});

it('rejects an ancestor cycle', function () {
    $course = Course::factory()->create();
    $a = Module::factory()->create(['course_id' => $course->id]);
    $b = Module::factory()->childOf($a)->create();

    $a->parent_id = $b->id; // would create a -> b -> a

    expect(fn () => $a->save())->toThrow(InvalidCurriculumException::class);
});

it('soft deletes and scopePublished filters', function () {
    $course = Course::factory()->create();
    Module::factory()->create(['course_id' => $course->id]);                 // draft
    Module::factory()->published()->create(['course_id' => $course->id]);

    expect(Module::published()->count())->toBe(1);

    Module::first()->delete();
    expect(Module::withTrashed()->count())->toBe(2)
        ->and(Module::count())->toBe(1);
});
