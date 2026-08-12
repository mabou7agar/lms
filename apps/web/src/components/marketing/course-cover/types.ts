import type { Localized } from "@/config/theme";

/**
 * Artwork family — drives which deterministic technical illustration fills the cover field.
 * ai = neural constellations; data = vector fields / signals; governance = institutional
 * architecture / archival grids; leadership = decision architecture / thresholds.
 */
export type CoverFamily = "ai" | "data" | "governance" | "leadership";

/** Editorial faculty-medallion palette derived from the HElbaron cover references. */
export type MedallionKey =
  | "navy"
  | "indigo"
  | "teal"
  | "plum"
  | "copper"
  | "olive"
  | "burgundy"
  | "slate";

export type CoverFaculty = { initials: string; key: MedallionKey };

/**
 * View-model consumed by <CourseCover>. Intentionally decoupled from any specific API/demo shape;
 * adapters map real (or demo) course data onto this. All human-readable strings are bilingual.
 */
export type CoverCourse = {
  id: string;
  /** Short discipline code shown at the top-start (e.g. "AI", "SLD"). */
  code: string;
  /** Full press mark shown as the technical header (e.g. "HEL / AIE / 502"). Falls back to code. */
  pressCode?: string;
  title: Localized;
  subtitle?: Localized;
  family: CoverFamily;
  /** Academic level label (e.g. "Graduate · L7"). */
  level?: Localized;
  /** School / faculty line for the press footer (e.g. "School of Computation"). */
  school?: Localized;
  faculty: CoverFaculty[];
  href: string;
  /** Folio number for the press footer (decorative). */
  folio?: number;
};
