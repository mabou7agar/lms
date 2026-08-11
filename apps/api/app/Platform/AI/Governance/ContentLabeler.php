<?php

declare(strict_types=1);

namespace App\Platform\AI\Governance;

/**
 * The single source of the "this was produced by AI" label. Every AiClient result carries it, so
 * consuming features present AI output transparently and consistently. The label text is
 * config-driven and locale-agnostic here; features localize the display copy at the edge.
 */
final class ContentLabeler
{
    public function label(): string
    {
        return (string) config('ai.content_label', 'AI-generated');
    }

    /** Wrap content with a trailing disclosure line — for surfaces that render the label inline. */
    public function decorate(string $content): string
    {
        return $content."\n\n— ".$this->label();
    }
}
