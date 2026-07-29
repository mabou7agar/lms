/**
 * Course Builder — versioning display helpers (P2/W03). Pure + deterministic (UTC), so history rows
 * render identically regardless of the viewer's timezone.
 */

/** First 8 hex chars of a checksum, for a compact fingerprint. */
export function shortChecksum(checksum: string): string {
  return typeof checksum === "string" ? checksum.slice(0, 8) : "";
}

/** Deterministic "YYYY-MM-DD HH:mm UTC" (or an em dash for a missing/invalid date). */
export function formatVersionDate(iso: string | null): string {
  if (!iso) return "—";
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return "—";
  return `${date.toISOString().replace("T", " ").slice(0, 16)} UTC`;
}
