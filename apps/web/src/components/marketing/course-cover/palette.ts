import type { CoverFamily, MedallionKey } from "./types";

/**
 * Matte faculty-medallion fills (base / top-highlight / lower-shadow) sampled from the cover
 * references. These are decorative brand values — deliberately deep and desaturated so the
 * medallions read as physical seals rather than flat chips.
 */
export const MEDALLION_FILL: Record<MedallionKey, { base: string; hi: string; lo: string }> = {
  navy: { base: "#2b3f63", hi: "#40598a", lo: "#1d2c46" },
  indigo: { base: "#4b3f7a", hi: "#63549b", lo: "#332b56" },
  teal: { base: "#2c6152", hi: "#3d806c", lo: "#1e453a" },
  plum: { base: "#6a3a63", hi: "#87507e", lo: "#4a2846" },
  copper: { base: "#6e3f2a", hi: "#8c5439", lo: "#4d2b1c" },
  olive: { base: "#6b6327", hi: "#877d34", lo: "#4a451b" },
  burgundy: { base: "#6a2f3a", hi: "#873c49", lo: "#4a2028" },
  slate: { base: "#3a4250", hi: "#4d5768", lo: "#272d37" },
};

/**
 * Deep editorial field gradients per family (top -> bottom), layered over the midnight base.
 * `accent` seeds the family artwork's brighter strokes.
 */
export const FAMILY_FIELD: Record<CoverFamily, { from: string; to: string; accent: string }> = {
  ai: { from: "#16273f", to: "#0f1b2e", accent: "#8fb3d9" },
  data: { from: "#12314c", to: "#0e2135", accent: "#78c6bf" },
  governance: { from: "#1b2138", to: "#241a31", accent: "#b58bb0" },
  leadership: { from: "#152b46", to: "#1b1734", accent: "#9aa6e0" },
};
