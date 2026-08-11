<?php

namespace App\Domains\Crm\Actions\Task;

use App\Domains\Crm\Enums\ActivityType;
use App\Domains\Crm\Enums\CrmTaskType;
use App\Domains\Crm\Enums\TaskStatus;
use App\Domains\Crm\Models\CrmTask;
use App\Domains\Crm\Services\ActivityLogger;
use App\Platform\Shared\Actions\BaseAction;
use Illuminate\Database\Eloquent\Model;

/**
 * Creates a task against a CRM subject (lead/opportunity) and records it on the subject timeline.
 * The subject is resolved and passed by the caller so this Action never trusts a raw class string.
 */
class CreateTaskAction extends BaseAction
{
    public function __construct(private readonly ActivityLogger $log) {}

    /**
     * @param  Model  $subject  a model using the HasTasks concern (Lead/Opportunity)
     * @param  array<string, mixed>  $data
     */
    public function execute(Model $subject, array $data): CrmTask
    {
        return $this->transaction(function () use ($subject, $data): CrmTask {
            /** @var CrmTask $task */
            $task = $subject->morphMany(CrmTask::class, 'taskable')->create([
                'title' => $data['title'],
                'type' => ($data['type'] ?? CrmTaskType::Other->value),
                'status' => TaskStatus::Open->value,
                'priority' => $data['priority'] ?? null,
                'due_at' => $data['due_at'] ?? null,
                'assigned_to' => $data['assigned_to'] ?? null,
            ]);

            $this->log->log($subject, ActivityType::System, "Task created: {$task->title}", $task->assigned_to);

            return $task;
        });
    }
}
