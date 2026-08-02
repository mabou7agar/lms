'use client';

import { useCallback, useEffect, useMemo, useRef } from 'react';

import { Spinner } from '@/components/ui';
import type { RecordVideoProgressBody, VideoProgress } from '@/lib/learning/player-api';
import { useLessonPlayback, useRecordVideoProgress } from '@/lib/learning/player-hooks';
import { useLearningPlayerI18n } from '@/lib/learning/player-i18n';
import { VideoProgressClient } from '@/lib/learning/progress-client';
import { PlayerError } from '../PlayerError';

export interface SignedVideoPlayerProps {
  lessonId: string;
  /** Server resume point in seconds (from prior video-progress). */
  initialPositionSeconds?: number | null;
  /** Authoritative media duration if already known (advisory to the client). */
  durationSeconds?: number | null;
  /** Fired with the server's authoritative progress after each throttled write. */
  onServerProgress?: (progress: VideoProgress) => void;
  /** Convenience: fired once when the server reports the video completed. */
  onVideoCompleted?: () => void;
  className?: string;
}

/**
 * Signed video player.
 *
 * - Fetches the signed URL JUST-IN-TIME (useLessonPlayback) and re-fetches on
 *   expiry (the <video> emitting an error, typically a 403 on the storage URL).
 * - Seeks to the server resume point once metadata is available.
 * - Reports progress through VideoProgressClient, which THROTTLES writes
 *   (~10s + flush on pause/seek/ended/unmount) — never per timeupdate tick.
 * - Completion is SERVER-decided: we surface `progress.completed` from the
 *   response and never infer it locally.
 * - Never exposes a raw storage id — only the signed URL from the backend.
 */
export function SignedVideoPlayer({
  lessonId,
  initialPositionSeconds,
  durationSeconds,
  onServerProgress,
  onVideoCompleted,
  className,
}: SignedVideoPlayerProps): React.ReactElement {
  const { t } = useLearningPlayerI18n();
  const playback = useLessonPlayback(lessonId);
  const record = useRecordVideoProgress(lessonId);

  const videoRef = useRef<HTMLVideoElement | null>(null);
  const clientRef = useRef<VideoProgressClient | null>(null);
  const didSeekRef = useRef(false);
  const completedRef = useRef(false);

  // Stable transport bound to the mutation. useRef so the client isn't recreated.
  const recordAsync = record.mutateAsync;
  const transport = useCallback(
    (body: RecordVideoProgressBody) => recordAsync(body),
    [recordAsync],
  );

  // Keep the latest handlers in a ref so the long-lived client always calls the
  // current callbacks without being recreated on every parent render.
  const handlersRef = useRef({ onServerProgress, onVideoCompleted });
  useEffect(() => {
    handlersRef.current = { onServerProgress, onVideoCompleted };
  });

  const handleServerProgress = useCallback((progress: VideoProgress) => {
    handlersRef.current.onServerProgress?.(progress);
    if (progress.completed && !completedRef.current) {
      completedRef.current = true;
      handlersRef.current.onVideoCompleted?.();
    }
  }, []);

  // Create the progress client once per lesson; dispose (final flush) on unmount.
  useEffect(() => {
    const client = new VideoProgressClient({
      transport,
      durationSeconds,
      onServerProgress: handleServerProgress,
    });
    clientRef.current = client;
    return () => {
      client.dispose();
      clientRef.current = null;
    };
    // Recreate only when the lesson changes; transport/handlers are stable refs.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [lessonId]);

  // Keep duration in sync if it arrives after mount.
  useEffect(() => {
    clientRef.current?.setDuration(durationSeconds ?? playback.data?.duration_seconds ?? null);
  }, [durationSeconds, playback.data?.duration_seconds]);

  const onLoadedMetadata = useCallback(() => {
    const el = videoRef.current;
    if (!el) return;
    clientRef.current?.setDuration(durationSeconds ?? (Number.isFinite(el.duration) ? el.duration : null));
    if (!didSeekRef.current && initialPositionSeconds && initialPositionSeconds > 0) {
      didSeekRef.current = true;
      try {
        el.currentTime = initialPositionSeconds;
      } catch {
        /* seeking may be unsupported before load; ignored */
      }
    }
  }, [durationSeconds, initialPositionSeconds]);

  const onTimeUpdate = useCallback(() => {
    const el = videoRef.current;
    if (el) clientRef.current?.beat(el.currentTime);
  }, []);

  const flushOn = useCallback((reason: 'pause' | 'seek' | 'ended') => {
    const el = videoRef.current;
    if (el) clientRef.current?.beat(el.currentTime);
    clientRef.current?.flush(reason);
  }, []);

  // Re-sign on playback URL error (expiry / 403).
  const onVideoError = useCallback(() => {
    void playback.refetch();
  }, [playback]);

  const src = playback.data?.url;
  const poster = playback.data?.poster_url ?? undefined;
  const captions = useMemo(() => playback.data?.captions ?? [], [playback.data?.captions]);

  if (playback.isError) {
    return (
      <PlayerError
        message={t('player.video.unavailable')}
        onRetry={() => void playback.refetch()}
        isRetrying={playback.isFetching}
      />
    );
  }

  return (
    <div className={className} data-testid="signed-video-player">
      {!src || playback.isLoading ? (
        <div
          className="flex aspect-video w-full items-center justify-center rounded-lg bg-black/80"
          data-testid="video-loading"
        >
          <Spinner aria-label={t('player.loading')} />
        </div>
      ) : (
        <video
          ref={videoRef}
          key={src}
          className="aspect-video w-full rounded-lg bg-black"
          controls
          playsInline
          poster={poster}
          onLoadedMetadata={onLoadedMetadata}
          onTimeUpdate={onTimeUpdate}
          onPause={() => flushOn('pause')}
          onSeeked={() => flushOn('seek')}
          onEnded={() => flushOn('ended')}
          onError={onVideoError}
          data-testid="video-element"
        >
          <source src={src} />
          {captions.map((c) => (
            <track key={c.lang} kind="captions" src={c.src} srcLang={c.lang} label={c.label} />
          ))}
        </video>
      )}
    </div>
  );
}
