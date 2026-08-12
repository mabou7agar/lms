/**
 * The carved cream surface at the foot of the cover. Not a flat rectangle: a shallow sculpted lip
 * rises across the width (as in the V3 references), with a fine bright "carve" highlight and a soft
 * inner shadow just beneath it so the cream reads as a physical, milled surface. Decorative only.
 */
export function CarvedSurface({ className }: { className?: string }) {
  return (
    <svg
      className={className}
      viewBox="0 0 300 130"
      preserveAspectRatio="none"
      aria-hidden="true"
      focusable="false"
    >
      {/* soft shadow cast by the field onto the lip */}
      <path
        d="M0 30 C 70 12 150 34 220 22 S 300 12 300 16 L300 130 L0 130 Z"
        fill="#000000"
        fillOpacity="0.16"
      />
      {/* the cream body */}
      <path
        d="M0 33 C 70 15 150 37 220 25 S 300 15 300 19 L300 130 L0 130 Z"
        fill="var(--card, #fffdf7)"
      />
      {/* bright carved edge */}
      <path
        d="M0 33 C 70 15 150 37 220 25 S 300 15 300 19"
        fill="none"
        stroke="#ffffff"
        strokeOpacity="0.7"
        strokeWidth="1.1"
      />
      {/* thin inner shadow beneath the edge for depth */}
      <path
        d="M0 35 C 70 17 150 39 220 27 S 300 17 300 21"
        fill="none"
        stroke="#8a7a5a"
        strokeOpacity="0.18"
        strokeWidth="1"
      />
    </svg>
  );
}
