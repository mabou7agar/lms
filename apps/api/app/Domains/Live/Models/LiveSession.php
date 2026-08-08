<?php

namespace App\Domains\Live\Models;

use App\Domains\Live\Database\Factories\LiveSessionFactory;
use App\Domains\Live\Enums\LiveSessionStatus;
use App\Platform\Shared\Tenancy\Concerns\BelongsToTenantNullable;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * @property string $title
 */
class LiveSession extends Model
{
    /** @use HasFactory<LiveSessionFactory> */
    use HasFactory;

    // T1 Option-N tenancy (matrix: Live sessions are SHARED-OR-OWNED/NULLABLE). Public events are global
    // (organization_id NULL); org-private cohorts get a non-null org, stamped server-side on create from
    // the resolved tenant. Registrations/attendance stay USER-OWNED (no tenant column). organization_id
    // is intentionally NOT in $fillable, so it can never be mass-assigned from a request payload.
    use BelongsToTenantNullable;

    use HasPublicId;
    use SoftDeletes;

    protected $fillable = [
        'live_course_id', 'series_id', 'title', 'description', 'status', 'timezone',
        'starts_at', 'ends_at', 'capacity', 'waiting_room', 'meeting_provider', 'meeting_external_id', 'join_url',
    ];

    protected $hidden = ['join_url']; // raw meeting URL is never serialized; use /join instead

    protected function casts(): array
    {
        return [
            'status' => LiveSessionStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'capacity' => 'integer',
            'waiting_room' => 'boolean',
        ];
    }

    public function liveCourse(): BelongsTo
    {
        return $this->belongsTo(LiveCourse::class);
    }

    /** @return HasMany<SessionTrainer> Pivot links to trainer user ids (no Identity model reference). */
    public function trainerLinks(): HasMany
    {
        return $this->hasMany(SessionTrainer::class, 'session_id');
    }

    /**
     * Flat sync of the session_trainers pivot by trainer user id (preserves the prior
     * trainers()->sync($ids) behavior without a belongsToMany(User) relation).
     *
     * @param  array<int, int|string>  $userIds
     */
    public function syncTrainers(array $userIds): void
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        $existing = DB::table('session_trainers')->where('session_id', $this->id)
            ->pluck('user_id')->map(fn ($v): int => (int) $v)->all();

        if (($detach = array_diff($existing, $userIds)) !== []) {
            DB::table('session_trainers')->where('session_id', $this->id)->whereIn('user_id', $detach)->delete();
        }
        foreach (array_diff($userIds, $existing) as $userId) {
            DB::table('session_trainers')->insert(['session_id' => $this->id, 'user_id' => $userId]);
        }
    }

    /** @return HasMany<SessionRegistration, $this> */
    public function registrations(): HasMany
    {
        return $this->hasMany(SessionRegistration::class, 'session_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(SessionAttendance::class, 'session_id');
    }

    public function recordings(): HasMany
    {
        return $this->hasMany(SessionRecording::class, 'session_id');
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(SessionReminder::class, 'session_id');
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('status', LiveSessionStatus::Scheduled->value)->where('starts_at', '>=', now());
    }

    public function isCancelled(): bool
    {
        return $this->status === LiveSessionStatus::Cancelled;
    }

    public function registeredCount(): int
    {
        return $this->registrations()->where('status', 'registered')->count();
    }

    public function isFull(): bool
    {
        return $this->capacity !== null && $this->registeredCount() >= $this->capacity;
    }

    protected static function newFactory(): LiveSessionFactory
    {
        return LiveSessionFactory::new();
    }
}
