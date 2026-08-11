<?php

declare(strict_types=1);

namespace App\Platform\AI\Contracts;

use App\Platform\AI\Data\EmbeddingResult;
use App\Platform\AI\Data\ModelOptions;
use App\Platform\AI\Enums\AiProvider;

/**
 * Provider-neutral text-embedding port. Returns one float vector per input text plus token usage.
 * Later features (semantic search) depend on this shape only; the FAKE implementation produces
 * deterministic hashed pseudo-vectors so the whole pipeline is exercisable without credentials.
 */
interface EmbeddingModel
{
    /**
     * @param  list<string>  $texts
     */
    public function embed(array $texts, ModelOptions $options): EmbeddingResult;

    /** Which provider backs this model (for attribution/metering). */
    public function provider(): AiProvider;
}
