<?php

namespace App\Platform\Identity\Actions\Privacy;

use App\Platform\Identity\Enums\DataRequestStatus;
use App\Platform\Identity\Enums\DataRequestType;
use App\Platform\Identity\Models\DataSubjectRequest;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Actions\BaseAction;

/**
 * Records a data-subject request. It is logged as `pending` for the audit trail even when the same
 * right can also be exercised immediately (self-service export), so every exercise of a right leaves
 * a durable, reviewable record.
 */
class SubmitDataRequestAction extends BaseAction
{
    public function execute(User $user, DataRequestType $type, ?string $note = null): DataSubjectRequest
    {
        return $this->transaction(fn (): DataSubjectRequest => DataSubjectRequest::create([
            'user_id' => $user->id,
            'type' => $type->value,
            'status' => DataRequestStatus::Pending->value,
            'note' => $note,
            'requested_at' => now(),
        ]));
    }
}
