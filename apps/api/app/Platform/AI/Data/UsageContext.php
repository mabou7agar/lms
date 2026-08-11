<?php

declare(strict_types=1);

namespace App\Platform\AI\Data;

use App\Platform\AI\Enums\AiFeature;
use App\Platform\AI\Enums\AiProvider;

/**
 * The immutable attribution of one AI call — who ran it (org/user), what for (feature), against
 * which provider/model, and CRUCIALLY the exact prompt key + version used. Everything a usage row
 * captures is decided here before the call, so metering never has to guess after the fact.
 */
final class UsageContext
{
    public function __construct(
        public readonly AiFeature $feature,
        public readonly AiProvider $provider,
        public readonly string $model,
        public readonly ?int $organizationId,
        public readonly ?int $userId,
        public readonly string $requestId,
        public readonly ?string $promptKey = null,
        public readonly ?int $promptVersion = null,
    ) {}
}
