<?php

namespace App\Domains\Crm\Actions\Consulting;

use App\Domains\Crm\Enums\ConsultingRequestStatus;
use App\Domains\Crm\Events\ConsultingRequestCreated;
use App\Domains\Crm\Models\ConsultingRequest;
use App\Domains\Crm\Services\ConsultingSlaService;
use App\Platform\Shared\Actions\BaseAction;
use App\Platform\Shared\Tenancy\TenantContext;

class CreateConsultingRequestAction extends BaseAction
{
    public function __construct(private readonly ConsultingSlaService $sla) {}

    /** @param array<string, mixed> $data subject, description, organization_id? */
    public function execute(array $data, int|string|null $requestedBy = null): ConsultingRequest
    {
        // Forged-tenant defense: when a tenant is resolved (an org member), the owning org comes from
        // the server-side TenantContext (users.organization_id) and a request-supplied organization_id
        // is ignored. Only a no-tenant context (platform/admin) may still pass one through.
        $resolvedTenantId = app(TenantContext::class)->id()?->value;

        $request = $this->transaction(function () use ($data, $requestedBy, $resolvedTenantId): ConsultingRequest {
            return ConsultingRequest::create([
                'organization_id' => $resolvedTenantId ?? ($data['organization_id'] ?? null),
                'contact_id' => $data['contact_id'] ?? null,
                'requested_by' => $requestedBy,
                'subject' => $data['subject'],
                'description' => $data['description'] ?? null,
                'status' => ConsultingRequestStatus::New->value,
                'sla_due_at' => $this->sla->dueAt(),
            ]);
        });

        ConsultingRequestCreated::dispatch($request);

        return $request;
    }
}
