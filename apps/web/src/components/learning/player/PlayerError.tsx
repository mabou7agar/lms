'use client';

import { AlertTriangle } from 'lucide-react';
import { Button } from '@/components/ui';
import { useLearningPlayerI18n } from '@/lib/learning/player-i18n';

/**
 * Generic error + recovery panel. `onRetry` typically wires a React Query
 * `refetch`. Keyboard-accessible: the retry button is a real <button>.
 */
export function PlayerError({
  title,
  message,
  onRetry,
  isRetrying = false,
}: {
  /** Overrides the "something went wrong" heading — an expected refusal is not a fault. */
  title?: string;
  message?: string;
  onRetry?: () => void;
  isRetrying?: boolean;
}): React.ReactElement {
  const { t } = useLearningPlayerI18n();
  return (
    <div
      role="alert"
      className="flex flex-col items-start gap-3 rounded-2xl border border-destructive/30 bg-destructive/[0.06] p-6 text-sm text-foreground"
      data-testid="player-error"
    >
      <div className="flex items-start gap-3">
        <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-destructive/10 text-destructive">
          <AlertTriangle aria-hidden className="size-5" />
        </span>
        <div>
          <p className="font-serif text-base font-semibold">{title ?? t('player.error.title')}</p>
          <p className="mt-1 text-muted-foreground">{message ?? t('player.error.curriculum')}</p>
        </div>
      </div>
      {onRetry ? (
        <Button variant="secondary" onClick={onRetry} disabled={isRetrying}>
          {t('player.error.retry')}
        </Button>
      ) : null}
    </div>
  );
}
