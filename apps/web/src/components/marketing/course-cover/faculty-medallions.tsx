import { MEDALLION_FILL } from "./palette";
import type { CoverFaculty, MedallionKey } from "./types";

/**
 * Faculty medallions — a signature brand element. Each is a carved seal: warm cream rim, inner
 * bevel ring, matte faculty fill with a top-left specular highlight, and initials. They overlap
 * along the inline axis (leftmost/start on top) exactly as in the cover references, and gracefully
 * overflow to a "+N" seal past the visible cap. Purely decorative -> the group is aria-hidden.
 */

function Medallion({ initials, colorKey, uid }: { initials: string; colorKey: MedallionKey; uid: string }) {
  const fill = MEDALLION_FILL[colorKey];
  const gradId = `hbm-${uid}`;
  return (
    <svg viewBox="0 0 100 100" className="hb-medallion" role="presentation">
      <defs>
        <radialGradient id={gradId} cx="0.36" cy="0.3" r="0.9">
          <stop offset="0" stopColor={fill.hi} />
          <stop offset="0.55" stopColor={fill.base} />
          <stop offset="1" stopColor={fill.lo} />
        </radialGradient>
      </defs>
      {/* cream rim */}
      <circle cx="50" cy="50" r="48" fill="var(--card, #fffdf7)" />
      {/* matte faculty fill */}
      <circle cx="50" cy="50" r="43" fill={`url(#${gradId})`} />
      {/* inner bevel ring */}
      <circle cx="50" cy="50" r="43" fill="none" stroke="#000000" strokeOpacity="0.28" strokeWidth="1" />
      <circle cx="50" cy="50" r="40" fill="none" stroke="#ffffff" strokeOpacity="0.14" strokeWidth="1" />
      <text
        x="50"
        y="50"
        textAnchor="middle"
        dominantBaseline="central"
        fontFamily="var(--font-mono, ui-monospace, monospace)"
        fontSize="24"
        letterSpacing="1.5"
        fill="#f4ece0"
        fillOpacity="0.92"
      >
        {initials}
      </text>
    </svg>
  );
}

export function FacultyMedallions({
  faculty,
  seed,
  max = 4,
  className,
}: {
  faculty: CoverFaculty[];
  seed: string;
  max?: number;
  className?: string;
}) {
  if (faculty.length === 0) return null;
  const visible = faculty.slice(0, max);
  const overflow = faculty.length - visible.length;

  return (
    <div className={`hb-medallions ${className ?? ""}`} aria-hidden="true">
      {visible.map((f, i) => (
        <span
          key={`${f.initials}-${i}`}
          className="hb-medallion-slot"
          style={{ zIndex: faculty.length - i }}
        >
          <Medallion initials={f.initials} colorKey={f.key} uid={`${seed}-${i}`} />
        </span>
      ))}
      {overflow > 0 ? (
        <span className="hb-medallion-slot" style={{ zIndex: 0 }}>
          <Medallion initials={`+${overflow}`} colorKey="slate" uid={`${seed}-more`} />
        </span>
      ) : null}
    </div>
  );
}
