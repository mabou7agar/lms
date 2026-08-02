'use client';

import type { LessonBlock } from '@/lib/learning/player-api';
import { useLearningPlayerI18n } from '@/lib/learning/player-i18n';

/**
 * Audio block. Plays the signed URL provided on the block (JIT-issued by the
 * lesson-content endpoint). Never exposes a raw storage id. For high-frequency
 * position persistence, wire the same VideoProgressClient the video player uses.
 */
export function AudioBlock({ block }: { block: LessonBlock }): React.ReactElement {
  const { t } = useLearningPlayerI18n();
  if (!block.url) {
    return (
      <p className="text-sm text-muted-foreground" data-testid="audio-unavailable">
        {t('player.audio.unavailable')}
      </p>
    );
  }
  return (
    <audio className="w-full" controls preload="metadata" data-testid="audio-block">
      <source src={block.url} type={block.mime_type ?? undefined} />
    </audio>
  );
}
