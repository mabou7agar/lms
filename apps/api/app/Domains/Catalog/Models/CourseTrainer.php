<?php

namespace App\Domains\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Read/write model over the `course_trainer` pivot. Carries the trainer's user_id plus assignment
 * facets — ordering `position`, free-text `role`, and the `is_primary` flag — WITHOUT a relation to
 * the Identity User model (trainer display is resolved from ids through the IdentityContracts
 * UserLookupPort). Bulk pivot writes go through Course::syncTrainers(); richer, authorized mutations
 * go through CourseInstructorService.
 *
 * INVARIANT (at most one primary per course): centralized here so it holds regardless of the write
 * path (service, Filament relation manager, or a direct model save). Whenever a row is saved with
 * is_primary = true, every sibling row of the same course is demoted via a raw DB update — which
 * bypasses model events, so there is no recursion.
 *
 * @property int $course_id
 * @property int $user_id
 * @property string|null $role
 * @property int $position
 * @property bool $is_primary
 */
class CourseTrainer extends Model
{
    protected $table = 'course_trainer';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'course_id' => 'integer',
            'user_id' => 'integer',
            'position' => 'integer',
            'is_primary' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (CourseTrainer $link): void {
            if ($link->is_primary) {
                DB::table('course_trainer')
                    ->where('course_id', $link->course_id)
                    ->where('user_id', '!=', $link->user_id)
                    ->update(['is_primary' => false]);
            }
        });
    }
}
