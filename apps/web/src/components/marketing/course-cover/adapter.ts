import type { DemoCourse } from "@/config/demo";
import type { Localized, Swatch } from "@/config/theme";
import { hashString } from "./seed";
import type { CoverCourse, CoverFamily, CoverFaculty, MedallionKey } from "./types";

/** Cyclable medallion palette for deriving faculty seals when explicit ones are absent. */
const KEY_CYCLE: MedallionKey[] = ["navy", "indigo", "teal", "copper", "plum", "olive", "burgundy"];

const SWATCH_KEY: Record<Swatch, MedallionKey> = {
  teal: "teal",
  gold: "olive",
  copper: "copper",
  red: "burgundy",
};

/** Map a discipline code onto an artwork family. Explicit `family` on the course wins. */
export function deriveFamily(code: string): CoverFamily {
  const c = code.toUpperCase();
  if (["AI", "ML", "DS", "NS"].includes(c)) return "ai";
  if (["IT", "FN", "DA", "AN", "SF"].includes(c)) return "data";
  if (["GV", "PB", "RGC", "RAA", "ING", "DPT"].includes(c)) return "governance";
  return "leadership";
}

/** "Yara Adel" -> "YA". Falls back to the first two letters of a single token. */
export function deriveInitials(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean);
  if (parts.length === 0) return "··";
  if (parts.length === 1) {
    const only = parts[0] ?? "";
    return only.slice(0, 2).toUpperCase();
  }
  const first = parts[0] ?? "";
  const last = parts[parts.length - 1] ?? "";
  return `${first.charAt(0)}${last.charAt(0)}`.toUpperCase();
}

/**
 * Editorial fields a course may optionally carry for the cover (not part of the real API contract —
 * these belong to the marketing/demo layer, synthesized deterministically when absent).
 */
type CoverExtras = {
  family?: CoverFamily;
  subtitle?: Localized;
  school?: Localized;
  faculty?: CoverFaculty[];
  folio?: number;
  href?: string;
};

/** Adapt a demo course (+ optional editorial extras) onto the CourseCover view-model. */
export function demoCourseToCover(course: DemoCourse & CoverExtras): CoverCourse {
  const family = course.family ?? deriveFamily(course.code);
  const faculty: CoverFaculty[] =
    course.faculty && course.faculty.length > 0
      ? course.faculty
      : [{ initials: deriveInitials(course.trainer), key: SWATCH_KEY[course.color] }];

  return {
    id: course.id,
    code: course.code,
    pressCode: `HEL · ${course.code} · ${(hashString(course.id) % 900) + 100}`,
    title: course.title,
    subtitle: course.subtitle,
    family,
    level: course.level,
    school: course.school,
    faculty,
    href: course.href ?? "/courses",
    folio: course.folio,
  };
}

/** Deterministically pick a medallion key from an arbitrary seed (for synthesized faculty lists). */
export function keyFromSeed(seed: string): MedallionKey {
  const idx = hashString(seed) % KEY_CYCLE.length;
  return KEY_CYCLE[idx] ?? "navy";
}
