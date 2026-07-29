"use client";

import { useEffect, useRef, useState, type ElementType, type ReactNode } from "react";
import { cn } from "@/lib/utils";
import { useHydrated } from "@/hooks/use-hydrated";

/**
 * Scroll-reveal wrapper. Adds `is-visible` once the element enters the viewport (once),
 * driving the CSS `.reveal` transition. `delay` staggers grid items. Honors reduced-motion
 * (the CSS neutralizes the transition automatically).
 */
export function Reveal({
  children,
  className,
  as: Tag = "div",
  delay = 0,
}: {
  children: ReactNode;
  className?: string;
  as?: ElementType;
  delay?: number;
}) {
  const ref = useRef<HTMLElement | null>(null);
  const [shown, setShown] = useState(false);
  const hydrated = useHydrated();

  useEffect(() => {
    const el = ref.current;
    if (!el || typeof IntersectionObserver === "undefined") return;
    const io = new IntersectionObserver(
      (entries) => {
        if (entries[0]?.isIntersecting) {
          setShown(true);
          io.disconnect();
        }
      },
      { threshold: 0.15, rootMargin: "0px 0px -8% 0px" },
    );
    io.observe(el);
    return () => io.disconnect();
  }, []);

  // Without IntersectionObserver (e.g. jsdom) reveal immediately so content is never left hidden —
  // matching the previous synchronous fallback, but derived instead of set inside the effect.
  const visible = shown || (hydrated && typeof IntersectionObserver === "undefined");

  return (
    <Tag
      ref={ref}
      className={cn("reveal", visible && "is-visible", className)}
      style={{ transitionDelay: `${delay}ms` }}
    >
      {children}
    </Tag>
  );
}
