<?php

namespace App\Domains\Crm\Services;

use App\Platform\Shared\Services\BaseService;
use Illuminate\Support\Str;

/**
 * Deterministic, config-driven lead scoring. Given the marketing/qualification signals captured on
 * a lead (request type, company size, acquisition channel, paid-click id and email domain) it
 * returns an integer score in [0, max]. Pure function of its input + config — no persistence,
 * no external calls — so it is trivially testable and safe to re-run on every create/update.
 */
class LeadScoringService extends BaseService
{
    /** @param array<string, mixed> $signals */
    public function score(array $signals): int
    {
        /** @var array<string, mixed> $rules */
        $rules = (array) config('crm.scoring', []);

        $score = (int) ($rules['base'] ?? 0);
        $score += $this->points($rules, 'request_type', $this->str($signals['request_type'] ?? null));
        $score += $this->points($rules, 'company_size', $this->str($signals['company_size'] ?? null));

        $medium = $this->str($signals['utm_medium'] ?? null);
        $score += $this->points($rules, 'utm_medium', $medium !== null ? Str::lower($medium) : null);

        if ($this->str($signals['gclid'] ?? null) !== null) {
            $score += (int) ($rules['has_gclid'] ?? 0);
        }

        /** @var array<int, string> $freeDomains */
        $freeDomains = (array) ($rules['free_email_domains'] ?? []);
        if ($this->isBusinessEmail($this->str($signals['email'] ?? null), $freeDomains)) {
            $score += (int) ($rules['business_email'] ?? 0);
        }

        $max = (int) ($rules['max'] ?? 100);

        return max(0, min($max, $score));
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    private function points(array $rules, string $key, ?string $value): int
    {
        if ($value === null) {
            return 0;
        }

        /** @var array<string, mixed> $table */
        $table = (array) ($rules[$key] ?? []);

        return (int) ($table[$value] ?? 0);
    }

    private function str(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /** @param array<int, string> $freeDomains */
    private function isBusinessEmail(?string $email, array $freeDomains): bool
    {
        if ($email === null || ! str_contains($email, '@')) {
            return false;
        }

        $domain = Str::lower(Str::afterLast($email, '@'));

        return $domain !== '' && ! in_array($domain, array_map('strtolower', $freeDomains), true);
    }
}
