<?php

namespace App\Domains\Catalog\Http\Requests;

use App\Domains\Catalog\Analytics\CoursePerformanceService;
use App\Domains\Catalog\Enums\CourseStatus;
use App\Platform\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters for the instructor dashboard.
 *
 * `course` is validated for FORMAT only. Whether the caller may see that course is an
 * authorization question answered by InstructorScope against the caller's own scope — putting an
 * `exists:courses,public_id` rule here would turn the validator into an existence oracle for
 * courses the caller cannot access, answering 422 for a fake id and 404 for a real one belonging
 * to someone else.
 *
 * `sort` and `direction` are validated against the same whitelist the service enforces, so an
 * unknown column is REFUSED rather than silently falling back — a caller who sorts by a column
 * that does not exist should be told, not handed the default order and left to believe it worked.
 * The service keeps its own whitelist regardless: this class is one caller, not a guarantee, and
 * the SQL-facing check has to hold for any future one.
 */
class InstructorDashboardRequest extends BaseFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'course' => ['sometimes', 'string', 'max:64'],
            'date_from' => ['sometimes', 'date'],
            // A window that ends before it starts is a client bug, not an empty result set.
            'date_to' => ['sometimes', 'date', 'after_or_equal:date_from'],

            'search' => ['sometimes', 'string', 'max:128'],
            'status' => ['sometimes', 'string', Rule::in(CourseStatus::values())],
            'sort' => ['sometimes', 'string', Rule::in(CoursePerformanceService::SORTABLE)],
            'direction' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
            // No max: the service caps at MAX_PER_PAGE. Refusing an over-large page size would
            // break a client that simply asked for "everything"; capping gives it a usable answer.
            'per_page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /**
     * Performance-table filters, narrowed to scalars so the service receives a typed shape rather
     * than raw query input of unknown type.
     *
     * @return array{search:?string, status:?string, sort:?string, direction:?string, per_page:?int, date_from:?string, date_to:?string}
     */
    public function performanceFilters(): array
    {
        return [
            'search' => $this->queryString('search'),
            'status' => $this->queryString('status'),
            'sort' => $this->queryString('sort'),
            'direction' => $this->queryString('direction'),
            'per_page' => $this->has('per_page') ? $this->integer('per_page') : null,
            'date_from' => $this->dateFrom(),
            'date_to' => $this->dateTo(),
        ];
    }

    /** A query parameter as a non-empty string, or null — never an array. */
    private function queryString(string $key): ?string
    {
        $value = $this->query($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function courseFilter(): ?string
    {
        $value = $this->query('course');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function dateFrom(): ?string
    {
        $value = $this->query('date_from');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function dateTo(): ?string
    {
        $value = $this->query('date_to');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
