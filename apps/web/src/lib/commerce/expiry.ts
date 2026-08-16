/**
 * Reading expiry dates the way a person does.
 *
 * Every expiring thing in the product — a company's purchased training, an employee's seat access, a
 * learner's certificate — is just an ISO date on a payload the UI already fetches. There is no
 * separate "what is expiring" endpoint and there does not need to be one: the seat portal already
 * returns `access_ends_at`, My Learning already returns `expires_at`, and the certificate list does
 * too. These helpers turn those dates into the two questions a banner asks.
 *
 * `SOON_DAYS` is the UI's threshold for "worth interrupting someone about", deliberately independent
 * of the admin's reminder offsets: those decide when to SEND a notice, this decides when a screen
 * someone is already looking at should mention it.
 */

export const SOON_DAYS = 30;

/** Whole days until the date. Negative once it has passed; null when there is no date. */
export function daysUntil(iso: string | null | undefined, now: Date = new Date()): number | null {
  if (!iso) return null;

  const target = new Date(iso);
  if (Number.isNaN(target.getTime())) return null;

  return Math.ceil((target.getTime() - now.getTime()) / 86_400_000);
}

/** Has this already lapsed? */
export function hasExpired(iso: string | null | undefined, now: Date = new Date()): boolean {
  const days = daysUntil(iso, now);
  return days !== null && days < 0;
}

/** Still valid, but close enough that someone should be told. */
export function isExpiringSoon(
  iso: string | null | undefined,
  withinDays: number = SOON_DAYS,
  now: Date = new Date(),
): boolean {
  const days = daysUntil(iso, now);
  return days !== null && days >= 0 && days <= withinDays;
}

/** A date rendered for the reader's locale, or an em dash when there is none. */
export function formatExpiry(iso: string | null | undefined, locale: string): string {
  if (!iso) return "—";

  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;

  return d.toLocaleDateString(locale === "ar" ? "ar" : "en", { dateStyle: "medium" });
}
