import type { Localized } from "@/config/theme";

/**
 * Artwork family — drives which deterministic technical illustration fills the cover image field
 * when no real course thumbnail is supplied.
 * ai = neural constellations; data = vector fields / signals; governance = institutional
 * architecture; leadership = decision architecture.
 */
export type CoverFamily = "ai" | "data" | "governance" | "leadership";

/** Wave shape carved between the thumbnail and the cream footer. */
export type CoverWave = "cradle" | "flow";

/** Fallback fill palette for an instructor avatar when no photo is available. */
export type MedallionKey =
  | "navy"
  | "indigo"
  | "teal"
  | "plum"
  | "copper"
  | "olive"
  | "burgundy"
  | "slate";

/**
 * An instructor shown as a clickable avatar on the cover. `avatarUrl` renders the real photo;
 * absent, the initials + `key` fill are used. `href` links to the instructor's profile.
 */
export type CoverInstructor = {
  initials: string;
  name: string;
  href: string;
  avatarUrl?: string | null;
  key: MedallionKey;
};

/**
 * View-model consumed by <CourseCover>. Decoupled from any API/demo shape; adapters map real (or
 * demo) course data onto this. All human-readable strings are bilingual.
 */
export type CoverCourse = {
  id: string;
  code: string;
  title: Localized;
  subtitle?: Localized;
  family: CoverFamily;
  level?: Localized;
  school?: Localized;
  /** Real course thumbnail image URL. When absent, a branded generative field is drawn instead. */
  thumbnail?: string | null;
  instructors: CoverInstructor[];
  href: string;
  folio?: number;
  /**
   * The course price, already formatted for the active locale. Present only when an active product
   * sells the course; a cover with no price simply omits the line rather than implying it is free.
   */
  price?: string | null;
};
