import { describe, expect, it, vi } from 'vitest';

import type { RecordVideoProgressBody, VideoProgress } from '@/lib/learning/player-api';
import { VideoProgressClient } from '@/lib/learning/progress-client';

const tick = () => new Promise<void>((r) => setTimeout(r, 0));

function serverProgress(over: Partial<VideoProgress> = {}): VideoProgress {
  return {
    position_seconds: 0,
    watched_seconds: 0,
    duration_seconds: 100,
    completed: false,
    ...over,
  };
}

describe('VideoProgressClient', () => {
  it('throttles beats to at most one write per interval, not one per tick', async () => {
    let t = 0;
    const sent: RecordVideoProgressBody[] = [];
    const transport = vi.fn(async (body: RecordVideoProgressBody) => {
      sent.push(body);
      return serverProgress({ position_seconds: body.position_seconds });
    });

    const client = new VideoProgressClient({
      transport,
      durationSeconds: 100,
      intervalMs: 10_000,
      now: () => t,
    });

    // 25 one-second beats.
    for (let i = 1; i <= 25; i += 1) {
      t = i * 1000;
      client.beat(i);
      await tick();
    }

    // Far fewer writes than beats — throttled to the ~10s window.
    expect(transport.mock.calls.length).toBeLessThanOrEqual(3);
    expect(transport.mock.calls.length).toBeGreaterThanOrEqual(1);
    expect(transport.mock.calls.length).not.toBe(25);
    // Latest write carries the most recent position and the advisory duration.
    expect(sent.at(-1)).toMatchObject({ duration_seconds: 100 });
    expect(sent.at(-1)!.position_seconds).toBeGreaterThanOrEqual(20);
  });

  it('flushes immediately on pause/seek/unmount, bypassing the throttle', async () => {
    let t = 0;
    const transport = vi.fn(async (b: RecordVideoProgressBody) =>
      serverProgress({ position_seconds: b.position_seconds }),
    );
    const client = new VideoProgressClient({ transport, durationSeconds: 100, now: () => t });

    t = 2000;
    client.beat(2); // within window, no send yet
    expect(transport).not.toHaveBeenCalled();

    client.flush('pause'); // forced
    await tick();
    expect(transport).toHaveBeenCalledTimes(1);
    expect(transport.mock.calls[0][0]).toMatchObject({ position_seconds: 2 });
  });

  it('never sends completion; completion is reflected only from the server response', async () => {
    let t = 0;
    const onServerProgress = vi.fn();
    const transport = vi.fn(async (b: RecordVideoProgressBody) => {
      // Server decides completion once watched crosses its threshold.
      return serverProgress({ position_seconds: b.position_seconds, completed: b.position_seconds >= 95 });
    });
    const client = new VideoProgressClient({
      transport,
      durationSeconds: 100,
      intervalMs: 1,
      now: () => t,
      onServerProgress,
    });

    t = 10;
    client.beat(50);
    await tick();
    expect(onServerProgress).toHaveBeenLastCalledWith(expect.objectContaining({ completed: false }));

    // No `completed` field is ever present in the request bodies.
    for (const call of transport.mock.calls) {
      expect(call[0]).not.toHaveProperty('completed');
    }

    t = 20;
    client.beat(96);
    await tick();
    expect(onServerProgress).toHaveBeenLastCalledWith(expect.objectContaining({ completed: true }));
  });

  it('drops impossible timestamps client-side (negative / NaN) and clamps overshoot to duration', async () => {
    let t = 100;
    const sent: RecordVideoProgressBody[] = [];
    const transport = vi.fn(async (b: RecordVideoProgressBody) => {
      sent.push(b);
      return serverProgress({ position_seconds: b.position_seconds });
    });
    const client = new VideoProgressClient({ transport, durationSeconds: 100, intervalMs: 1, now: () => t });

    expect(client.beat(-5)).toBe(false);
    expect(client.beat(Number.NaN)).toBe(false);
    expect(transport).not.toHaveBeenCalled();

    t = 200;
    client.beat(150); // beyond duration -> clamped to 100
    await tick();
    expect(sent.at(-1)).toMatchObject({ position_seconds: 100 });
  });
});
