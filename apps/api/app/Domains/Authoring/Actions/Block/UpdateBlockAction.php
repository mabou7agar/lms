<?php

namespace App\Domains\Authoring\Actions\Block;

use App\Domains\Authoring\Actions\Block\Concerns\NormalizesBlockContent;
use App\Domains\Authoring\Actions\Concerns\GuardsLockVersion;
use App\Domains\Authoring\Models\Block;
use App\Platform\Shared\Actions\BaseAction;
use App\Platform\Shared\Html\HtmlSanitizer;

/**
 * C5 - Edit a block's type, localized content and/or config under the C3 optimistic-lock contract.
 *
 * The block itself is the lock unit (its own `lock_version`), locked for the short compare-and-write
 * window. A supplied-but-stale `expectedVersion` throws StaleCurriculumWriteException, rendered as
 * the exact 409 { error:"stale_write", current_version } body already used for sections/lessons. A
 * null version keeps existing callers backward compatible (last-write-wins).
 */
class UpdateBlockAction extends BaseAction
{
    use GuardsLockVersion;
    use NormalizesBlockContent;

    public function __construct(private readonly HtmlSanitizer $htmlSanitizer) {}

    protected function sanitizer(): HtmlSanitizer
    {
        return $this->htmlSanitizer;
    }

    /**
     * @param  array<string, mixed>  $data  validated UpdateBlockRequest payload
     */
    public function execute(Block $block, array $data, ?int $expectedVersion = null): Block
    {
        $hasContent = array_key_exists('content_i18n', $data);
        $content = $hasContent ? $this->sanitizeContent($data['content_i18n']) : null;

        return $this->transaction(function () use ($block, $data, $hasContent, $content, $expectedVersion): Block {
            $locked = Block::query()->whereKey($block->getKey())->lockForUpdate()->firstOrFail();

            $this->assertLockVersion($locked, $expectedVersion);

            if (array_key_exists('type', $data)) {
                $locked->type = $data['type']; // saving hook re-derives family
            }
            if ($hasContent) {
                $locked->content_i18n = $content;
                // Re-sync the legacy default-locale mirror alongside the localized surface.
                $locked->payload = $this->defaultLocaleContent($content);
            }
            if (array_key_exists('config', $data)) {
                $locked->config = $data['config'];
            }

            $this->advanceLockVersion($locked);
            $locked->save();

            return $locked;
        });
    }
}
