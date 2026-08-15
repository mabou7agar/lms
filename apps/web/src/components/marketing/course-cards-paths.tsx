"use client";

import Link from "next/link";
import { ArrowRight } from "lucide-react";
import { useI18n } from "@/lib/i18n/i18n-context";
import { pickLocale, type Localized } from "@/config/theme";
import { useCourses } from "@/lib/catalog/hooks";
import type { CourseListItem } from "@/lib/catalog/api";
import { proxyMediaUrl } from "@/lib/media/proxy";
import { Reveal } from "@/components/landing/reveal";
import {
  CoverArt,
  InstructorAvatars,
  courseFamily,
  deriveInitials,
} from "@/components/marketing/course-cover";
import type { CoverFamily, CoverInstructor, MedallionKey } from "@/components/marketing/course-cover";
import { FeaturedShell } from "./featured-shell";

/** Bilingual track name per academy family — shared with the cinematic hero kicker. */
export const FAMILY_LABEL: Record<CoverFamily, Localized> = {
  ai: { en: "Artificial Intelligence", ar: "الذكاء الاصطناعي" },
  data: { en: "Data & Finance", ar: "البيانات والمالية" },
  governance: { en: "Governance & Strategy", ar: "الحوكمة والاستراتيجية" },
  leadership: { en: "Leadership", ar: "القيادة" },
};

/** Short outcome line per track. */
const FAMILY_OUTCOME: Record<CoverFamily, Localized> = {
  ai: { en: "Apply AI to real business decisions.", ar: "طبّق الذكاء الاصطناعي في قرارات الأعمال." },
  data: { en: "Turn data and markets into decisions.", ar: "حوّل البيانات والأسواق إلى قرارات." },
  governance: { en: "Lead with strategy and sound governance.", ar: "قُد بالاستراتيجية والحوكمة الرشيدة." },
  leadership: { en: "Lead teams and grow as an operator.", ar: "قُد الفرق وتطوّر كقائد تنفيذي." },
};

/** Fixed track order so the grid is stable regardless of catalog order. */
const TRACK_ORDER: CoverFamily[] = ["ai", "data", "governance", "leadership"];

const MEDALLION_CYCLE: MedallionKey[] = ["navy", "copper", "teal", "plum", "olive", "burgundy", "indigo", "slate"];

type Track = {
  family: CoverFamily;
  courses: CourseListItem[];
  thumbnail: string | null;
  faculty: CoverInstructor[];
  highlight: CourseListItem;
};

/** Dedupe instructors across a track's courses (by id), capped downstream by <InstructorAvatars>. */
function trackFaculty(courses: CourseListItem[]): CoverInstructor[] {
  const seen = new Set<string>();
  const out: CoverInstructor[] = [];
  for (const course of courses) {
    for (const t of course.trainers ?? []) {
      if (seen.has(t.id)) continue;
      seen.add(t.id);
      out.push({
        name: t.name,
        initials: deriveInitials(t.name),
        key: MEDALLION_CYCLE[out.length % MEDALLION_CYCLE.length]!,
        avatarUrl: proxyMediaUrl(t.avatar_path) ?? null,
        href: `/trainers/${t.id}`,
      });
    }
  }
  return out;
}

function buildTracks(courses: CourseListItem[]): Track[] {
  const byFamily = new Map<CoverFamily, CourseListItem[]>();
  for (const course of courses) {
    const family = courseFamily(course);
    const bucket = byFamily.get(family) ?? [];
    bucket.push(course);
    byFamily.set(family, bucket);
  }

  const tracks: Track[] = [];
  for (const family of TRACK_ORDER) {
    const list = byFamily.get(family);
    if (!list || list.length === 0) continue;
    // Prefer a featured course as the highlight + representative image; else the first course.
    const highlight = list.find((c) => c.is_featured) ?? list[0]!;
    const withThumb = list.find((c) => c.thumbnail_path);
    tracks.push({
      family,
      courses: list,
      thumbnail: proxyMediaUrl(withThumb?.thumbnail_path) ?? null,
      faculty: trackFaculty(list),
      highlight,
    });
  }
  return tracks;
}

/**
 * Program-path variant: academy TRACK cards derived from real catalog data (all published courses),
 * bucketed into the four cover families. Each card shows the track name, an outcome line, the real
 * course count, a representative thumbnail (or the family generative field), deduped faculty
 * medallions, and a featured-course highlight linking to the catalog. Tracks with 0 courses are
 * skipped; an empty catalog renders nothing (no empty section).
 */
export function CourseCardsPaths() {
  const query = useCourses({ per_page: 50 });
  const courses = query.data?.data ?? [];
  const tracks = buildTracks(courses);

  if (tracks.length === 0) return null;

  return (
    <FeaturedShell>
      <div className="stagger-in grid gap-6 sm:grid-cols-2">
        {tracks.map((track) => (
          <TrackCard key={track.family} track={track} />
        ))}
      </div>
    </FeaturedShell>
  );
}

function TrackCard({ track }: { track: Track }) {
  const { locale } = useI18n();
  const name = pickLocale(FAMILY_LABEL[track.family], locale);
  const outcome = pickLocale(FAMILY_OUTCOME[track.family], locale);
  const count = track.courses.length;
  const countLabel = locale === "ar" ? `${count} دورة` : `${count} course${count === 1 ? "" : "s"}`;
  const highlightTitle = track.highlight.title || track.highlight.slug;
  const highlightKicker = locale === "ar" ? "ابدأ بـ" : "Start with";

  return (
    <Reveal>
      <article className="hb-track-card">
        <div className="hb-track-media">
          {track.thumbnail ? (
            <span
              className="hb-track-photo"
              style={{ backgroundImage: `url("${track.thumbnail}")` }}
              aria-hidden="true"
            />
          ) : (
            <>
              <span
                className="hb-track-photo"
                style={{ background: "radial-gradient(120% 120% at 74% -10%, #16273f 0%, #0f1b2e 60%, #0b1422 100%)" }}
                aria-hidden="true"
              />
              <CoverArt family={track.family} seed={track.family} className="absolute inset-0 h-full w-full opacity-90" />
            </>
          )}
          <span className="hb-track-scrim" aria-hidden="true" />
          <span className="hb-track-count">{countLabel}</span>
          <h3 className="hb-track-name">{name}</h3>
          <Link href="/courses" aria-label={name} className="hb-track-media-hit" />
        </div>

        <div className="hb-track-body">
          <p className="hb-track-outcome line-clamp-2">{outcome}</p>
          {track.faculty.length > 0 ? (
            <div className="hb-track-faculty">
              <InstructorAvatars instructors={track.faculty} />
            </div>
          ) : null}
          <Link href="/courses" className="hb-track-highlight">
            <span className="hb-track-highlight-kicker">{highlightKicker}</span>
            <span className="hb-track-highlight-title line-clamp-1">{highlightTitle}</span>
            <ArrowRight className="hb-track-highlight-arrow size-4 rtl:rotate-180" aria-hidden />
          </Link>
        </div>
      </article>
    </Reveal>
  );
}
