"use client";

import { useEffect, useRef, useState } from "react";
import { useHydrated } from "@/hooks/use-hydrated";
import { usePrefersReducedMotion } from "@/hooks/use-prefers-reduced-motion";

/** Counts from 0 → `to` once scrolled into view. Wraps with prefix/suffix. */
export function CountUp({ to, prefix = "", suffix = "", duration = 1400 }: { to: number; prefix?: string; suffix?: string; duration?: number }) {
  const ref = useRef<HTMLSpanElement | null>(null);
  const [value, setValue] = useState(0);
  const reduce = usePrefersReducedMotion();
  const hydrated = useHydrated();

  useEffect(() => {
    if (reduce) return;
    const el = ref.current;
    if (!el || typeof IntersectionObserver === "undefined") return;
    const io = new IntersectionObserver((entries) => {
      if (!entries[0]?.isIntersecting) return;
      io.disconnect();
      const start = performance.now();
      const tick = (now: number) => {
        const p = Math.min(1, (now - start) / duration);
        const eased = 1 - Math.pow(1 - p, 3);
        setValue(Math.round(eased * to));
        if (p < 1) requestAnimationFrame(tick);
      };
      requestAnimationFrame(tick);
    }, { threshold: 0.4 });
    io.observe(el);
    return () => io.disconnect();
  }, [to, duration, reduce]);

  // Reduced motion — or environments without IntersectionObserver (e.g. jsdom) — show the final
  // value immediately, matching the previous synchronous fallback but without a setState-in-effect.
  const noObserver = hydrated && typeof IntersectionObserver === "undefined";
  const display = reduce || noObserver ? to : value;

  return (
    <span ref={ref}>
      {prefix}
      {display}
      {suffix}
    </span>
  );
}
