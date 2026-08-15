import Link from "next/link";
import { MEDALLION_FILL } from "./palette";
import type { CoverInstructor } from "./types";

/**
 * Instructor avatars that ride the carved wave. Each is a real photo (or initials fallback) inside
 * a cream rim with a fine gold ring, and is an individual link to that instructor's profile — so
 * they sit ABOVE the course link and are clicked independently (no nested anchors). Overlapping and
 * centered; more than `max` collapse to a non-interactive "+N" seal.
 */
function Avatar({ instructor }: { instructor: CoverInstructor }) {
  const fill = MEDALLION_FILL[instructor.key];
  return (
    <span className="hb-avatar-face">
      {instructor.avatarUrl ? (
        <span
          className="hb-avatar-photo"
          style={{ backgroundImage: `url("${instructor.avatarUrl}")` }}
        />
      ) : (
        <span
          className="hb-avatar-photo"
          style={{ backgroundImage: `linear-gradient(135deg, ${fill.hi}, ${fill.lo})` }}
        >
          <span className="hb-avatar-ini">{instructor.initials}</span>
        </span>
      )}
    </span>
  );
}

export function InstructorAvatars({
  instructors,
  max = 4,
  className,
}: {
  instructors: CoverInstructor[];
  max?: number;
  className?: string;
}) {
  if (instructors.length === 0) return null;
  const visible = instructors.slice(0, max);
  const overflow = instructors.length - visible.length;

  return (
    <div className={`hb-avatars ${className ?? ""}`}>
      {visible.map((ins, i) => (
        <Link
          key={`${ins.name}-${i}`}
          href={ins.href}
          className="hb-avatar"
          aria-label={ins.name}
          style={{ zIndex: instructors.length - i }}
        >
          <Avatar instructor={ins} />
        </Link>
      ))}
      {overflow > 0 ? (
        <span className="hb-avatar hb-avatar-more" aria-hidden="true" style={{ zIndex: 0 }}>
          <span className="hb-avatar-face">
            <span className="hb-avatar-photo" style={{ background: "#3a4250" }}>
              <span className="hb-avatar-ini">{`+${overflow}`}</span>
            </span>
          </span>
        </span>
      ) : null}
    </div>
  );
}
