<?php

namespace App\Platform\Identity\Actions\Sso;

use App\Platform\Identity\Enums\SsoDomainMode;
use App\Platform\Identity\Exceptions\SsoDomainTakenException;
use App\Platform\Identity\Models\SsoDomainMapping;
use App\Platform\Shared\Actions\BaseAction;
use Illuminate\Database\QueryException;

/**
 * Claims an email domain for an organization. A domain is GLOBALLY unique (one org per domain): the
 * pre-check returns a friendly 422, and the DB unique index is the authoritative guard against a
 * concurrent double-claim (also surfaced as the same 422 rather than a raw 500).
 */
class CreateSsoDomainMappingAction extends BaseAction
{
    public function execute(int $organizationId, int $createdBy, string $domain, SsoDomainMode $mode): SsoDomainMapping
    {
        return $this->transaction(function () use ($organizationId, $createdBy, $domain, $mode): SsoDomainMapping {
            if (SsoDomainMapping::query()->where('domain', $domain)->exists()) {
                throw new SsoDomainTakenException(details: ['domain' => $domain]);
            }

            try {
                return SsoDomainMapping::create([
                    'organization_id' => $organizationId,
                    'domain' => $domain,
                    'mode' => $mode->value,
                    'created_by' => $createdBy,
                ]);
            } catch (QueryException) {
                // Unique-constraint race: another org claimed it between the check and the insert.
                throw new SsoDomainTakenException(details: ['domain' => $domain]);
            }
        });
    }
}
