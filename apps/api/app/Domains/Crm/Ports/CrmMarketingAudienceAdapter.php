<?php

declare(strict_types=1);

namespace App\Domains\Crm\Ports;

use App\Domains\Crm\Models\CrmTag;
use App\Domains\Crm\Models\Lead;
use App\Platform\Shared\Marketing\Contracts\MarketingAudiencePort;
use App\Platform\Shared\Marketing\Data\MarketingRecipient;
use Illuminate\Support\Str;

/**
 * CRM's implementation of the Shared {@see MarketingAudiencePort}. This is the ONLY seam through which
 * the Notifications marketing engine reaches lead data — it hands back boundary-safe
 * {@see MarketingRecipient} DTOs and answers consent live from the lead's own marketing_consent flag,
 * so Notifications never imports a CRM model.
 *
 * Leads are the operator's own sales funnel (platform-level, no org column), so segment resolution is
 * not org-scoped here; the campaign/enrollment layer that consumes these recipients IS tenant-scoped.
 */
final class CrmMarketingAudienceAdapter implements MarketingAudiencePort
{
    /** Allow-listed lead attributes a segment filter may constrain — nothing else reaches the query. */
    private const FILTERABLE = ['status', 'source', 'utm_source', 'utm_campaign', 'utm_medium', 'country', 'request_type'];

    public function resolveSegment(int|string|null $tenantId, string $audienceType, array $filter): iterable
    {
        if ($audienceType !== 'lead') {
            return;
        }

        $query = Lead::query()->whereNotNull('email')->where('email', '!=', '');

        foreach ($filter as $key => $value) {
            if (in_array($key, self::FILTERABLE, true) && is_scalar($value)) {
                $query->where($key, $value);
            }
        }

        foreach ($query->cursor() as $lead) {
            yield new MarketingRecipient(
                recipientType: 'lead',
                recipientId: (int) $lead->id,
                email: (string) $lead->email,
                timezone: null,
                locale: null,
                hasConsent: (bool) $lead->marketing_consent,
            );
        }
    }

    public function hasMarketingConsent(string $recipientType, int $recipientId): bool
    {
        if ($recipientType !== 'lead') {
            return false;
        }

        return (bool) (Lead::query()->whereKey($recipientId)->value('marketing_consent'));
    }

    public function tagRecipient(string $recipientType, int $recipientId, string $tag): void
    {
        if ($recipientType !== 'lead') {
            return;
        }

        $lead = Lead::query()->find($recipientId);

        if ($lead === null) {
            return;
        }

        /** @var CrmTag $model */
        $model = CrmTag::query()->firstOrCreate(
            ['slug' => Str::slug($tag)],
            ['name' => $tag],
        );

        $lead->tags()->syncWithoutDetaching([$model->id]);
    }
}
