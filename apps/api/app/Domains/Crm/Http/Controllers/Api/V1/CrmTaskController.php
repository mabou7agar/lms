<?php

namespace App\Domains\Crm\Http\Controllers\Api\V1;

use App\Domains\Crm\Actions\Task\CompleteTaskAction;
use App\Domains\Crm\Actions\Task\CreateTaskAction;
use App\Domains\Crm\Http\Requests\StoreTaskRequest;
use App\Domains\Crm\Http\Resources\CrmTaskResource;
use App\Domains\Crm\Models\CrmTask;
use App\Domains\Crm\Models\Lead;
use App\Domains\Crm\Models\Opportunity;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class CrmTaskController extends Controller
{
    /** @var array<string, class-string<Model>> */
    private const SUBJECTS = [
        'lead' => Lead::class,
        'opportunity' => Opportunity::class,
    ];

    public function store(StoreTaskRequest $request, CreateTaskAction $action): JsonResponse
    {
        Gate::authorize('create', CrmTask::class);

        $data = $request->validated();

        /** @var class-string<Model> $subjectClass */
        $subjectClass = self::SUBJECTS[(string) $data['subject_type']] ?? Lead::class;
        $subject = $subjectClass::query()->where('public_id', $data['subject'])->firstOrFail();

        $task = $action->execute($subject, $data);

        return ApiResponse::created(new CrmTaskResource($task), 'Task created.');
    }

    public function complete(CrmTask $task, CompleteTaskAction $action): JsonResponse
    {
        Gate::authorize('update', $task);

        $task = $action->execute($task);

        return ApiResponse::updated(new CrmTaskResource($task), 'Task completed.');
    }
}
