'use client';

import { Button } from '@/components/ui';
import type { LessonBlock } from '@/lib/learning/player-api';
import { useLearningPlayerI18n } from '@/lib/learning/player-i18n';

/**
 * Document block. Links to the signed document URL (JIT-issued). Opens in a new
 * tab with rel="noopener" and offers a download affordance.
 */
export function DocumentBlock({ block }: { block: LessonBlock }): React.ReactElement {
  const { t } = useLearningPlayerI18n();
  const label = block.label ?? t('player.document.open');

  if (!block.url) {
    return (
      <p className="text-sm text-neutral-500" data-testid="document-unavailable">
        {label}
      </p>
    );
  }

  return (
    <div className="flex items-center gap-3" data-testid="document-block">
      <span className="min-w-0 flex-1 truncate text-sm">{label}</span>
      <Button asChild variant="secondary">
        <a href={block.url} target="_blank" rel="noopener noreferrer">
          {t('player.document.open')}
        </a>
      </Button>
      <Button asChild variant="ghost">
        <a href={block.url} download>
          {t('player.document.download')}
        </a>
      </Button>
    </div>
  );
}
