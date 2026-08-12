"use client";

import Link from "next/link";
import { type CSSProperties } from "react";
import { Play } from "lucide-react";
import { useI18n } from "@/lib/i18n/i18n-context";
import { pickLocale } from "@/config/theme";
import { CoverArt } from "./cover-art";
import { CarvedSurface } from "./carved-surface";
import { FacultyMedallions } from "./faculty-medallions";
import { usePointerDepth } from "./use-pointer-depth";
import { FAMILY_FIELD } from "./palette";
import { hashString, mulberry32 } from "./seed";
import type { CoverCourse } from "./types";

const ROMAN = ["", "I", "II", "III", "IV", "V", "VI", "VII", "VIII", "IX", "X", "XI", "XII"];

/**
 * HElbaron editorial course cover — a tall portrait "publication": a deep colored editorial field
 * with deterministic technical artwork, a carved cream surface, overlapping faculty medallions, and
 * restrained pointer-driven depth. The whole object is a single link to the course; an optional
 * play control (a sibling, never nested) opens a preview. Bilingual + RTL-aware; motion is fully
 * disabled under `prefers-reduced-motion`.
 */
export function CourseCover({
  course,
  index = 0,
  onPreview,
  className,
}: {
  course: CoverCourse;
  index?: number;
  onPreview?: (id: string) => void;
  className?: string;
}) {
  const { locale } = useI18n();
  const { onPointerMove, onPointerLeave } = usePointerDepth();

  const title = pickLocale(course.title, locale);
  const subtitle = course.subtitle ? pickLocale(course.subtitle, locale) : null;
  const level = course.level ? pickLocale(course.level, locale) : null;
  const school = course.school ? pickLocale(course.school, locale) : null;
  const press = course.pressCode ?? `HEL · ${course.code}`;
  const field = FAMILY_FIELD[course.family];
  const roman = ROMAN[(index % 12) + 1] ?? "";
  const folio = course.folio ?? ((hashString(course.id) % 60) + 1);
  const gridId = `hbgrid-${course.id}`;

  // Deterministic decorative coordinates for the technical header.
  const rng = mulberry32(hashString(course.id));
  const coord = `x ${rng().toFixed(3)}  y ${rng().toFixed(3)}`;

  const label = level ? `${title} — ${level}` : title;

  return (
    <article className={`hb-cover-root ${className ?? ""}`}>
      <Link href={course.href} aria-label={label} className="hb-cover group">
        <div
          onPointerMove={onPointerMove}
          onPointerLeave={onPointerLeave}
          className="hb-cover-stage"
          style={{ backgroundColor: field.to }}
        >
          {/* Z0 — editorial field */}
          <div
            className="hb-cover-layer absolute inset-0"
            style={
              {
                backgroundImage: `radial-gradient(130% 90% at 72% -6%, ${field.from} 0%, ${field.to} 60%, #0b1422 100%)`,
                "--hb-depth": "0",
              } as CSSProperties
            }
            aria-hidden="true"
          />

          {/* Z1 — fine technical grid */}
          <svg
            className="hb-cover-layer absolute inset-0 h-full w-full"
            style={{ "--hb-depth": "0.25" } as CSSProperties}
            aria-hidden="true"
            focusable="false"
          >
            <defs>
              <pattern id={gridId} width="26" height="26" patternUnits="userSpaceOnUse">
                <path d="M26 0 H0 V26" fill="none" stroke="#ffffff" strokeOpacity="0.05" strokeWidth="0.5" />
              </pattern>
            </defs>
            <rect width="100%" height="100%" fill={`url(#${gridId})`} />
          </svg>

          {/* Z1 — faint roman-numeral watermark */}
          <span
            className="hb-cover-layer hb-cover-roman"
            style={{ "--hb-depth": "0.2" } as CSSProperties}
            aria-hidden="true"
          >
            {roman}
          </span>

          {/* Z2 — generative technical artwork */}
          <CoverArt
            family={course.family}
            seed={course.id}
            className="hb-cover-layer hb-cover-art absolute inset-0 h-full w-full"
          />

          {/* Z4 — carved cream surface */}
          <CarvedSurface className="hb-cover-layer hb-cover-carve absolute inset-x-0 bottom-0" />

          {/* Z6 — pointer specular sheen (gated by --hb-active) */}
          <span className="hb-cover-sheen" aria-hidden="true" />

          {/* Content */}
          <div className="hb-cover-content">
            {/* field content */}
            <div className="hb-cover-field">
              <div className="hb-cover-meta">
                <div className="min-w-0">
                  <p className="hb-cover-code">{press}</p>
                  {level ? <p className="hb-cover-sub-meta">{level}</p> : null}
                  <p className="hb-cover-sub-meta hb-cover-coord">{coord}</p>
                </div>
                <div className="hb-cover-press">
                  <p>HELBARON · PRESS</p>
                  <p className="hb-cover-sub-meta">{`FIRST EDITION · N° ${folio}`}</p>
                </div>
              </div>

              <span className="hb-cover-crosshair" aria-hidden="true" />

              <div className="hb-cover-title-block hb-cover-layer" style={{ "--hb-depth": "0.14" } as CSSProperties}>
                <h3 className="hb-cover-title">{title}</h3>
                {subtitle ? <p className="hb-cover-subtitle">{subtitle}</p> : null}
              </div>
            </div>

            {/* cream content */}
            <div className="hb-cover-cream">
              <FacultyMedallions
                faculty={course.faculty}
                seed={course.id}
                className="hb-cover-layer hb-cover-medallions"
              />
              <div className="hb-cover-footer">
                <span className="truncate">{school ? `HELBARON · ${school}` : "HELBARON"}</span>
                <span className="whitespace-nowrap">{`MMXXVI · fol. ${folio}`}</span>
              </div>
            </div>
          </div>
        </div>
      </Link>

      {onPreview ? (
        <button
          type="button"
          onClick={() => onPreview(course.id)}
          className="hb-cover-play"
          aria-label={
            locale === "ar" ? `تشغيل معاينة ${title}` : `Play preview of ${title}`
          }
        >
          <Play className="size-5 translate-x-0.5 fill-current" aria-hidden="true" />
        </button>
      ) : null}
    </article>
  );
}
