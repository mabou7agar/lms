<?php

declare(strict_types=1);

namespace App\Domains\Qna\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Admin-controlled Q&A service level, as a single row (the CertificateSetting pattern).
 *
 * `response_sla_hours` is CALENDAR hours, not business hours. Business-day arithmetic needs a
 * working-week and a holiday calendar per region, and inventing one would make "overdue" mean
 * something different for a Riyadh instructor than a London one without either of them being told.
 * Calendar hours is a promise everyone reads the same way; if business hours are wanted later they
 * belong with a real calendar, not a weekday check.
 *
 * @property int $response_sla_hours
 * @property bool $notify_instructor_on_overdue
 */
class QnaSetting extends Model
{
    protected $table = 'qna_settings';

    protected $fillable = ['response_sla_hours', 'notify_instructor_on_overdue'];

    protected function casts(): array
    {
        return [
            'response_sla_hours' => 'integer',
            'notify_instructor_on_overdue' => 'boolean',
        ];
    }

    public static function current(): self
    {
        return static::firstOrCreate([], [
            'response_sla_hours' => (int) config('qna.response_sla_hours', 48),
        ]);
    }

    /** The instant a question asked at `$askedAt` breaches the promise. */
    public function dueAt(Carbon $askedAt): Carbon
    {
        return $askedAt->copy()->addHours(max(1, $this->response_sla_hours));
    }
}
