<?php

declare(strict_types=1);

namespace App\Platform\AI\Exceptions;

/**
 * A configured usage limit would be exceeded by the pending call. Thrown BEFORE the provider is
 * hit, so an over-quota request costs nothing. `scope` names which limit tripped
 * (request | user_daily | org_monthly | global_monthly).
 */
final class AiQuotaExceededException extends AiException
{
    public function __construct(
        public readonly string $scope,
        public readonly int $limit,
        public readonly int $attempted,
    ) {
        parent::__construct("AI quota exceeded for scope [{$scope}]: attempted {$attempted} tokens against a limit of {$limit}.");
    }
}
