<?php

namespace App\Domains\Assessment\Database\Factories;

use App\Domains\Assessment\Models\AssignmentSubmission;
use App\Domains\Assessment\Models\SubmissionFile;
use App\Platform\Shared\Helpers\Uuid;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SubmissionFile> */
class SubmissionFileFactory extends Factory
{
    protected $model = SubmissionFile::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'submission_id' => AssignmentSubmission::factory(),
            'media_public_id' => Uuid::v7(),
            'original_filename' => fake()->word().'.pdf',
        ];
    }
}
