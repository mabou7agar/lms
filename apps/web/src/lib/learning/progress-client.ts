/**
 * Server-authoritative video-progress client.
 *
 * The player emits a `beat` roughly once a second, plus explicit flushes on
 * pause / seek / ended / unmount. This client THROTTLES those into at most one
 * network write per `intervalMs` (default ~10s) while forcing a flush on the
 * lifecycle events, so we never flood `POST /v1/lessons/{lesson}/video-progress`.
 *
 * It NEVER marks a lesson complete: it only sends `position_seconds`
 * (+ advisory `duration_seconds`) and reflects whatever the server returns
 * (`completed` is server-decided). Impossible client timestamps are dropped
 * before they ever hit the wire; the server remains the final arbiter.
 *
 * Framework-agnostic and fully injectable (transport + clock) so it is
 * deterministic under test with a fake transport and fake time.
 */
import type { RecordVideoProgressBody, VideoProgress } from './player-api';

export type ProgressTransport = (body: RecordVideoProgressBody) => Promise<VideoProgress>;

export type FlushReason = 'interval' | 'pause' | 'seek' | 'ended' | 'unmount' | 'manual';

export interface VideoProgressClientOptions {
  /** Sends one heartbeat to the backend and returns the server's authoritative view. */
  transport: ProgressTransport;
  /** Advisory media duration; used to reject impossible positions client-side. */
  durationSeconds?: number | null;
  /** Minimum ms between throttled interval writes. Default 10_000. */
  intervalMs?: number;
  /** Called with the server's authoritative progress after every successful write. */
  onServerProgress?: (progress: VideoProgress) => void;
  /** Called when a write rejects (network/validation). Non-fatal; the client keeps running. */
  onError?: (error: unknown, reason: FlushReason) => void;
  /** Injectable clock (ms). Defaults to Date.now. */
  now?: () => number;
}

const DEFAULT_INTERVAL_MS = 10_000;

export class VideoProgressClient {
  private readonly transport: ProgressTransport;
  private readonly intervalMs: number;
  private readonly onServerProgress?: (p: VideoProgress) => void;
  private readonly onError?: (e: unknown, r: FlushReason) => void;
  private readonly now: () => number;

  private durationSeconds: number | null;

  /** Latest integer position reported by the player, pending a possible flush. */
  private pendingPosition: number | null = null;
  /** Last position we actually sent to the server. */
  private sentPosition: number | null = null;
  /** Timestamp (ms) of the last successful/attempted send. */
  private lastSendAt = 0;
  /** True while a write is in flight — coalesces concurrent flushes. */
  private inFlight = false;
  /** Set when a flush was requested while a write was in flight. */
  private flushQueued: FlushReason | null = null;
  private disposed = false;

  constructor(options: VideoProgressClientOptions) {
    this.transport = options.transport;
    this.intervalMs = options.intervalMs ?? DEFAULT_INTERVAL_MS;
    this.onServerProgress = options.onServerProgress;
    this.onError = options.onError;
    this.now = options.now ?? (() => Date.now());
    this.durationSeconds = normalizeDuration(options.durationSeconds);
  }

  /** Update the known media duration once metadata loads. */
  setDuration(durationSeconds: number | null | undefined): void {
    this.durationSeconds = normalizeDuration(durationSeconds);
  }

  /**
   * Report the current playback position (call ~1/s). Records the position and,
   * if the throttle window has elapsed AND the position advanced, sends a beat.
   * Returns true if a network write was triggered.
   */
  beat(positionSeconds: number): boolean {
    const pos = this.sanitize(positionSeconds);
    if (pos === null) return false;

    this.pendingPosition = pos;

    const elapsed = this.now() - this.lastSendAt;
    const advanced = this.sentPosition === null || pos > this.sentPosition;
    if (elapsed >= this.intervalMs && advanced) {
      this.send('interval');
      return true;
    }
    return false;
  }

  /**
   * Force an immediate write of the latest position, bypassing the throttle
   * window (used on pause / seek / ended / unmount). No-op if nothing changed.
   */
  flush(reason: FlushReason = 'manual'): void {
    if (this.pendingPosition === null) return;
    // Skip a redundant flush when the pending position is already on the server
    // and this isn't a lifecycle event that should still land a resume point.
    if (this.pendingPosition === this.sentPosition && reason === 'interval') return;
    this.send(reason);
  }

  /** Flush the final position and stop accepting further writes. */
  dispose(): void {
    if (this.disposed) return;
    this.flush('unmount');
    this.disposed = true;
  }

  // -------------------------------------------------------------------------

  private send(reason: FlushReason): void {
    if (this.disposed && reason !== 'unmount') return;
    if (this.pendingPosition === null) return;

    if (this.inFlight) {
      // Coalesce: remember that another flush is wanted once the current settles.
      this.flushQueued = reason;
      return;
    }

    const position = this.pendingPosition;
    const body: RecordVideoProgressBody = { position_seconds: position };
    if (this.durationSeconds !== null) body.duration_seconds = this.durationSeconds;

    this.inFlight = true;
    this.lastSendAt = this.now();
    this.sentPosition = position;

    this.transport(body)
      .then((progress) => {
        this.onServerProgress?.(progress);
      })
      .catch((error) => {
        // Roll back the "sent" marker so the next beat retries this position.
        if (this.sentPosition === position) this.sentPosition = null;
        this.onError?.(error, reason);
      })
      .finally(() => {
        this.inFlight = false;
        const queued = this.flushQueued;
        this.flushQueued = null;
        if (queued && this.pendingPosition !== null && this.pendingPosition !== position) {
          this.send(queued);
        }
      });
  }

  /**
   * Client-side rejection of impossible timestamps. Returns an integer second
   * position, or null to drop the beat entirely. The server still re-validates.
   */
  private sanitize(positionSeconds: number): number | null {
    if (!Number.isFinite(positionSeconds) || positionSeconds < 0) return null;
    let pos = Math.floor(positionSeconds);
    if (this.durationSeconds !== null && pos > this.durationSeconds) {
      // Clamp to duration rather than dropping, so the resume point still lands
      // at the end of the media instead of being lost on a slight overshoot.
      pos = this.durationSeconds;
    }
    return pos;
  }
}

function normalizeDuration(value: number | null | undefined): number | null {
  if (value === null || value === undefined) return null;
  if (!Number.isFinite(value) || value <= 0) return null;
  return Math.floor(value);
}
