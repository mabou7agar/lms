<?php

namespace App\Domains\Assessment\Http\Controllers\Api\V1\Admin;

use App\Domains\Assessment\Http\Requests\GradebookQueryRequest;
use App\Domains\Assessment\Http\Resources\GradebookRowResource;
use App\Domains\Assessment\Services\GradebookService;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Identity\Contracts\CourseAccessPort;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Course gradebook (paginated) and its CSV export. Both are instructor-only, scoped to a course
 * resolved through CourseAccessPort — a course the actor may not manage 404s indistinguishably from
 * one that does not exist.
 */
class GradebookController
{
    public function __construct(
        private readonly CourseAccessPort $courses,
        private readonly GradebookService $gradebook,
    ) {}

    public function show(GradebookQueryRequest $request, string $course): JsonResponse
    {
        $courseId = $this->manageableCourse($request, $course);
        $data = $request->validated();

        $page = $this->gradebook->page(
            $courseId,
            ['only' => $data['only'] ?? null],
            (int) ($data['per_page'] ?? 25),
            (int) ($data['page'] ?? 1),
        );

        return ApiResponse::paginated($page, GradebookRowResource::class);
    }

    public function export(Request $request, string $course): StreamedResponse
    {
        $courseId = $this->manageableCourse($request, $course);

        // Stream the CSV line-by-line in bounded roster chunks rather than building the entire file
        // in memory first — a large-enrollment course would otherwise spike memory on export.
        return response()->streamDownload(function () use ($courseId): void {
            $out = fopen('php://output', 'wb');
            foreach ($this->gradebook->streamCsv($courseId) as $line) {
                fwrite($out, $line);
            }
            fclose($out);
        }, "gradebook-course-{$courseId}.csv", ['Content-Type' => 'text/csv']);
    }

    private function manageableCourse(Request $request, string $coursePublicId): int
    {
        $actor = $request->user();

        if (! $actor instanceof Actor) {
            throw new NotFoundHttpException('Not found.');
        }

        $courseId = $this->courses->manageableCourseId($actor, $coursePublicId);

        if ($courseId === null) {
            throw new NotFoundHttpException('Course not found.');
        }

        return $courseId;
    }
}
