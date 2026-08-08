<?php

namespace App\Contexts\Learning\Services;

use App\Contexts\Learning\Models\CourseCompletionPolicy;
use App\Contexts\Learning\Support\CompletionPolicy;
use App\Platform\Shared\Services\BaseService;

/**
 * Resolves the effective {@see CompletionPolicy} for a course: the stored row's value object, or the
 * platform default when no row exists. This single fallback point is what guarantees default
 * preservation — every course that has never been configured resolves to
 * {@see CompletionPolicy::default()}, which encodes the pre-policy-engine behaviour exactly.
 */
class CourseCompletionPolicyResolver extends BaseService
{
    public function resolve(int $courseId): CompletionPolicy
    {
        $policy = CourseCompletionPolicy::query()->find($courseId);

        return $policy === null
            ? CompletionPolicy::default()
            : $policy->toValueObject();
    }
}
