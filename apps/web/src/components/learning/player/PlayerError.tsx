'use client';

import { Button } from '@/components/ui';
import { useLearningPlayerI18n } from '@/lib/learning/player-i18n';

/**
 * Generic error + recovery panel. `onRetry` typically wires a React Query
 * `refetch`. Keyboard-accessible: the retry button is a real <button>.
 */
export function PlayerError({
  message,
  onRetry,
  isRetrying = false,
}: {
  message?: string;
  onRetry?: () => void;
  isRetrying?: boolean;
}): React.ReactElement {
  const { t } = useLearningPlayerI18n();
  return (
    <div
      role="alert"
      className="flex flex-col items-start gap-3 rounded-lg border border-red-200 bg-red-50 p-6 text-sm text-red-900"
      data-testid="player-error"
    >
      <div>
        <p className="font-semibold">{t('player.error.title')}</p>
        <p className="mt-1">{message ?? t('player.error.curriculum')}</p>
      </div>
      {onRetry ? (
        <Button variant="secondary" onClick={onRetry} disabled={isRetrying}>
          {t('player.error.retry')}
        </Button>
      ) : null}
    </div>
  );
}
