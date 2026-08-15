import type { DemoCourse } from "@/config/demo";
import type { Swatch } from "@/config/theme";
import type { CourseListItem } from "@/lib/catalog/api";
import { proxyMediaUrl } from "@/lib/media/proxy";
import { hashString } from "./seed";
import type { CoverCourse, CoverFamily, CoverInstructor, MedallionKey } from "./types";

/** Words too generic to carry the press sigil. */
const PRESS_STOPWORDS = new Set([
  "the", "a", "an", "of", "and", "or", "to", "for", "in", "on", "with", "at", "by", "from",
]);

/**
 * Stable HElbaron press code for a course — `HEL · XXX · NNN`. `XXX` is a 3-letter sigil built from
 * the initials of up to three significant title words (padded with the title's consonants, then `X`);
 * `NNN` is a deterministic 700–899 catalogue number hashed from the course id. Pure + locale-stable
 * (derive from a fixed title string so the code never shifts between EN/AR).
 */
export function derivePressCode(title: string, id: string): string {
  const words = title
    .toLowerCase()
    .replace(/[^a-z0-9\s]/g, " ")
    .split(/\s+/)
    .filter((w) => w && !PRESS_STOPWORDS.has(w));
  let sigil = words.map((w) => w.charAt(0)).join("").toUpperCase();
  if (sigil.length < 3) {
    const consonants = title.toUpperCase().replace(/[^A-Z]/g, "").replace(/[AEIOU]/g, "");
    sigil = (sigil + consonants).slice(0, 3);
  }
  sigil = (sigil + "XXX").slice(0, 3);
  const num = 700 + (hashString(id) % 200);
  return `HEL · ${sigil} · ${num}`;
}

/**
 * Editorial tier line from a course level: BEGINNER → `FOUNDATION · L6`, INTERMEDIATE →
 * `GRADUATE · L7`, ADVANCED → `EXECUTIVE · L8`. Falls back to `EXECUTIVE · L8` when the level is
 * absent or unrecognised.
 */
export function deriveTier(level?: string | null): string {
  const l = (level ?? "").toLowerCase();
  if (/beginner|foundation|basic|intro/.test(l)) return "FOUNDATION · L6";
  if (/intermediate|graduate/.test(l)) return "GRADUATE · L7";
  return "EXECUTIVE · L8";
}

/** Roman numeral for a 1-based position (supports 1–20; clamps out-of-range values). */
export function toRoman(n: number): string {
  const table: Array<[number, string]> = [
    [10, "X"], [9, "IX"], [5, "V"], [4, "IV"], [1, "I"],
  ];
  let value = Math.max(1, Math.min(20, Math.floor(n)));
  let out = "";
  for (const [num, sym] of table) {
    while (value >= num) {
      out += sym;
      value -= num;
    }
  }
  return out;
}

const SWATCH_KEY: Record<Swatch, MedallionKey> = {
  teal: "teal",
  gold: "olive",
  copper: "copper",
  red: "burgundy",
};

/** Per-instructor profile page is not built yet; link to the trainers index for now. */
const INSTRUCTOR_HREF = "/trainers";

/** Map a discipline code onto an artwork family. Explicit `family` on the course wins. */
export function deriveFamily(code: string): CoverFamily {
  const c = code.toUpperCase();
  if (["AI", "ML", "DS", "NS"].includes(c)) return "ai";
  if (["IT", "FN", "DA", "AN", "SF"].includes(c)) return "data";
  if (["GV", "PB", "RGC", "RAA", "ING", "DPT"].includes(c)) return "governance";
  return "leadership";
}

/** "Yara Adel" -> "YA". */
export function deriveInitials(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean);
  if (parts.length === 0) return "··";
  if (parts.length === 1) return (parts[0] ?? "").slice(0, 2).toUpperCase();
  const first = parts[0] ?? "";
  const last = parts[parts.length - 1] ?? "";
  return `${first.charAt(0)}${last.charAt(0)}`.toUpperCase();
}

/** Best-effort artwork family from a real course title (only affects the fallback art / press code). */
function familyFromTitle(title: string): CoverFamily {
  const t = title.toLowerCase();
  if (/\bai\b|artificial|machine learning|\bml\b/.test(t)) return "ai";
  if (/data|analyt|finance|financ|invest|trading|analysis|account/.test(t)) return "data";
  if (/leader|manage|team|sales|people/.test(t)) return "leadership";
  return "governance";
}

/**
 * Classify a REAL catalog course into one of the four academy tracks (CoverFamily), title-based and
 * locale-stable — used to bucket the catalog into program-path cards.
 */
export function courseFamily(course: CourseListItem): CoverFamily {
  return familyFromTitle(course.title || course.slug);
}

/**
 * Adapt a REAL catalog course (list item) onto the CourseCover view-model — same designed frame
 * (carved wave + thumbnail + title), fed by live data. The list endpoint carries no trainers, so
 * instructors is empty (the frame renders cleanly without avatars); the thumbnail is proxied so the
 * uploaded image resolves same-origin in dev.
 */
const MEDALLION_CYCLE: MedallionKey[] = ["navy", "copper", "teal", "plum", "olive", "burgundy", "indigo", "slate"];

export function courseListItemToCover(course: CourseListItem): CoverCourse {
  const title = course.title || course.slug;

  const instructors: CoverInstructor[] = (course.trainers ?? []).map((t, i) => ({
    name: t.name,
    initials: deriveInitials(t.name),
    key: MEDALLION_CYCLE[i % MEDALLION_CYCLE.length]!,
    avatarUrl: proxyMediaUrl(t.avatar_path) ?? null,
    href: `/trainers/${t.id}`,
  }));

  return {
    id: course.id,
    code: "",
    title: { en: title, ar: title },
    subtitle: course.subtitle ? { en: course.subtitle, ar: course.subtitle } : undefined,
    family: familyFromTitle(title),
    level: course.level ? { en: course.level, ar: course.level } : undefined,
    thumbnail: proxyMediaUrl(course.thumbnail_path) ?? null,
    instructors,
    href: `/courses/${course.id}`,
  };
}

/** Adapt a demo course onto the CourseCover view-model. */
export function demoCourseToCover(course: DemoCourse): CoverCourse {
  const family = course.family ?? deriveFamily(course.code);
  const raw =
    course.instructors && course.instructors.length > 0
      ? course.instructors
      : [{ name: course.trainer, initials: deriveInitials(course.trainer), key: SWATCH_KEY[course.color] }];

  const instructors: CoverInstructor[] = raw.map((x) => ({
    name: x.name,
    initials: x.initials,
    key: x.key,
    avatarUrl: x.avatarUrl ?? null,
    href: INSTRUCTOR_HREF,
  }));

  return {
    id: course.id,
    code: course.code,
    title: course.title,
    subtitle: course.subtitle,
    family,
    level: course.level,
    school: course.school,
    thumbnail: null,
    instructors,
    href: "/courses",
  };
}
