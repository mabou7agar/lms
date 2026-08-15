import type { CoverWave } from "./types";

/**
 * The carved cream wave that cuts up into the course thumbnail. The instructor avatars ride this
 * wave. Two shapes: `cradle` rises into a central bump that hugs the avatars (homepage);
 * `flow` is the asymmetric editorial carve from the cover references (courses page). Decorative.
 */
const TOP: Record<CoverWave, string> = {
  cradle: "M0 72 C 60 72 96 34 150 34 C 204 34 240 72 300 72",
  flow: "M0 44 C 90 84 175 20 300 52",
};

export function CarvedSurface({ wave, className }: { wave: CoverWave; className?: string }) {
  const top = TOP[wave];
  return (
    <svg
      className={className}
      viewBox="0 0 300 180"
      preserveAspectRatio="none"
      aria-hidden="true"
      focusable="false"
    >
      <path d={`${top} L300 180 L0 180 Z`} fill="#000000" opacity="0.14" transform="translate(0,-4)" />
      <path d={`${top} L300 180 L0 180 Z`} fill="var(--card, #fffdf7)" />
      <path d={top} fill="none" stroke="#ffffff" strokeOpacity="0.7" strokeWidth="1.4" />
      <path d={top} fill="none" stroke="#8a7a5a" strokeOpacity="0.16" strokeWidth="1" transform="translate(0,1.6)" />
    </svg>
  );
}
