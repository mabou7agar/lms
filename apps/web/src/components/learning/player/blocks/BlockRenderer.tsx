'use client';

import { Button } from '@/components/ui';
import type { LessonBlock, VideoProgress } from '@/lib/learning/player-api';
import { useLearningPlayerI18n } from '@/lib/learning/player-i18n';
import { AudioBlock } from './AudioBlock';
import { DocumentBlock } from './DocumentBlock';
import { SignedVideoPlayer } from './SignedVideoPlayer';
import { TextBlock } from './TextBlock';

export interface BlockRendererProps {
  lessonId: string;
  block: LessonBlock;
  /** Resume position for the primary video block, in seconds. */
  initialPositionSeconds?: number | null;
  durationSeconds?: number | null;
  onServerVideoProgress?: (progress: VideoProgress) => void;
  onVideoCompleted?: () => void;
  /** Marks a required non-media block done (server-authoritative). */
  onCompleteBlock?: (blockRef: string) => void;
  isCompletingBlock?: boolean;
}

/**
 * Dispatches a lesson block to its renderer. Video goes through the signed,
 * JIT player. Required text/document blocks expose a "mark done" control that
 * calls the block-completion endpoint (the server records completion).
 */
export function BlockRenderer({
  lessonId,
  block,
  initialPositionSeconds,
  durationSeconds,
  onServerVideoProgress,
  onVideoCompleted,
  onCompleteBlock,
  isCompletingBlock,
}: BlockRendererProps): React.ReactElement {
  const { t } = useLearningPlayerI18n();

  const body = (() => {
    switch (block.kind) {
      case 'video':
        return (
          <SignedVideoPlayer
            lessonId={lessonId}
            initialPositionSeconds={initialPositionSeconds}
            durationSeconds={durationSeconds}
            onServerProgress={onServerVideoProgress}
            onVideoCompleted={onVideoCompleted}
          />
        );
      case 'audio':
        return <AudioBlock block={block} />;
      case 'document':
        return <DocumentBlock block={block} />;
      case 'text':
      default:
        return <TextBlock block={block} />;
    }
  })();

  const showManualComplete =
    block.required && !block.completed && block.kind !== 'video' && Boolean(onCompleteBlock);

  return (
    <section className="space-y-2" data-testid={`block-${block.id}`} data-block-kind={block.kind}>
      {body}
      {block.required && block.completed ? (
        <p className="text-xs text-primary" data-testid={`block-done-${block.id}`}>
          {t('player.blockCompleted')}
        </p>
      ) : null}
      {showManualComplete ? (
        <Button
          variant="secondary"
          onClick={() => onCompleteBlock?.(block.id)}
          disabled={isCompletingBlock}
          data-testid={`block-complete-${block.id}`}
        >
          {t('player.blockComplete')}
        </Button>
      ) : null}
    </section>
  );
}
