<?php

namespace App\Domains\Crm\Actions\Task;

use App\Domains\Crm\Enums\ActivityType;
use App\Domains\Crm\Enums\TaskStatus;
use App\Domains\Crm\Models\CrmTask;
use App\Domains\Crm\Services\ActivityLogger;
use App\Platform\Shared\Actions\BaseAction;

/**
 * Marks a task done (idempotent: re-completing keeps the original completed_at) and appends a
 * timeline entry on the owning subject.
 */
class CompleteTaskAction extends BaseAction
{
    public function __construct(private readonly ActivityLogger $log) {}

    public function execute(CrmTask $task): CrmTask
    {
        if ($task->isComplete()) {
            return $task;
        }

        return $this->transaction(function () use ($task): CrmTask {
            $task->forceFill([
                'status' => TaskStatus::Done->value,
                'completed_at' => now(),
            ])->save();

            $subject = $task->taskable;
            if ($subject !== null) {
                $this->log->log($subject, ActivityType::System, "Task completed: {$task->title}", $task->assigned_to);
            }

            return $task;
        });
    }
}
