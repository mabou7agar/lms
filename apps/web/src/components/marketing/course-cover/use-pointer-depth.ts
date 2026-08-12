"use client";

import { useCallback, useRef, type PointerEvent } from "react";
import { usePrefersReducedMotion } from "@/hooks/use-prefers-reduced-motion";

/**
 * Restrained pointer-driven depth for a course cover. The handlers write CSS custom properties
 * (`--hb-rx/--hb-ry` tilt, `--hb-px/--hb-py` parallax, `--hb-mx/--hb-my` + `--hb-active` specular)
 * directly onto the event's own element — which inherits to the `.hb-cover-*` layers — so per-frame
 * work is a single style write, never React state, and layout is never touched (transform/opacity
 * only). No ref is exposed: the target is always `event.currentTarget`.
 *
 * Fine-pointer (mouse) only; touch and `prefers-reduced-motion` get an inert, resting card.
 */
export function usePointerDepth({ maxTilt = 4.5, maxParallax = 10 }: { maxTilt?: number; maxParallax?: number } = {}) {
  const frame = useRef<number | null>(null);
  const reduced = usePrefersReducedMotion();

  const onPointerMove = useCallback(
    (event: PointerEvent<HTMLDivElement>) => {
      if (reduced || event.pointerType !== "mouse") return;
      const el = event.currentTarget;
      const rect = el.getBoundingClientRect();
      if (rect.width === 0 || rect.height === 0) return;
      const nx = (event.clientX - rect.left) / rect.width - 0.5; // -0.5 .. 0.5
      const ny = (event.clientY - rect.top) / rect.height - 0.5;
      if (frame.current != null) cancelAnimationFrame(frame.current);
      frame.current = requestAnimationFrame(() => {
        el.style.setProperty("--hb-ry", `${(nx * maxTilt * 2).toFixed(2)}deg`);
        el.style.setProperty("--hb-rx", `${(-ny * maxTilt * 2).toFixed(2)}deg`);
        el.style.setProperty("--hb-px", `${(-nx * maxParallax * 2).toFixed(2)}px`);
        el.style.setProperty("--hb-py", `${(-ny * maxParallax * 2).toFixed(2)}px`);
        el.style.setProperty("--hb-mx", `${((nx + 0.5) * 100).toFixed(1)}%`);
        el.style.setProperty("--hb-my", `${((ny + 0.5) * 100).toFixed(1)}%`);
        el.style.setProperty("--hb-active", "1");
      });
    },
    [reduced, maxTilt, maxParallax],
  );

  const onPointerLeave = useCallback((event: PointerEvent<HTMLDivElement>) => {
    const el = event.currentTarget;
    if (frame.current != null) cancelAnimationFrame(frame.current);
    el.style.setProperty("--hb-ry", "0deg");
    el.style.setProperty("--hb-rx", "0deg");
    el.style.setProperty("--hb-px", "0px");
    el.style.setProperty("--hb-py", "0px");
    el.style.setProperty("--hb-active", "0");
  }, []);

  return { onPointerMove, onPointerLeave };
}
