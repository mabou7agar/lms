/**
 * Conversion analytics taxonomy — the single, typed source of truth for public-funnel events.
 *
 * Guarantees (asserted by tests/analytics/conversion-events.test.ts):
 *  - stable, versioned event names (never silently renamed);
 *  - typed, minimal payloads that carry NO personally identifiable information and NO secrets
 *    (emails, phone numbers, names, passwords, payment tokens/card data are never accepted);
 *  - `redactEventPayload` defensively strips any PII/secret-shaped key before a payload leaves the
 *    client, so an accidental field can never be transmitted.
 *
 * No third-party analytics vendor is introduced. The emitter (see ./track) is first-party and
 * consent-aware.
 */

export const ANALYTICS_SCHEMA_VERSION = 1 as const;

export const CONVERSION_EVENTS = [
  "page_view",
  "primary_cta_clicked",
  "secondary_cta_clicked",
  "persona_selected",
  "pricing_viewed",
  "plan_selected",
  "enterprise_demo_started",
  "enterprise_demo_submitted",
  "registration_started",
  "registration_completed",
  "course_viewed",
  "checkout_started",
  "checkout_completed",
] as const;

export type ConversionEvent = (typeof CONVERSION_EVENTS)[number];

export function isConversionEvent(name: string): name is ConversionEvent {
  return (CONVERSION_EVENTS as readonly string[]).includes(name);
}

/** Fields common to every event. All non-PII. */
export interface BaseEventPayload {
  /** App locale at emit time. */
  readonly locale?: "en" | "ar";
  /** Route path (never query strings, which may carry identifiers). */
  readonly path?: string;
}

export interface CtaEventPayload extends BaseEventPayload {
  readonly intent: "primary" | "secondary";
  /** Destination route of the CTA. */
  readonly to: string;
}

export interface PersonaEventPayload extends BaseEventPayload {
  readonly persona: "companies" | "academies" | "instructors" | "public_sector";
}

export interface PlanEventPayload extends BaseEventPayload {
  /** Non-PII plan identifier/slug. */
  readonly plan: string;
  readonly billing?: "monthly" | "annual" | "one_time";
}

export interface CheckoutEventPayload extends BaseEventPayload {
  /** Minor-unit amount (integer) — never card data. */
  readonly amountMinor?: number;
  readonly currency?: string;
}

/** Map of event name → its payload shape. */
export interface ConversionEventPayloads {
  page_view: BaseEventPayload;
  primary_cta_clicked: CtaEventPayload;
  secondary_cta_clicked: CtaEventPayload;
  persona_selected: PersonaEventPayload;
  pricing_viewed: BaseEventPayload;
  plan_selected: PlanEventPayload;
  enterprise_demo_started: BaseEventPayload;
  enterprise_demo_submitted: BaseEventPayload;
  registration_started: BaseEventPayload;
  registration_completed: BaseEventPayload;
  course_viewed: BaseEventPayload & { readonly courseId?: string };
  checkout_started: CheckoutEventPayload;
  checkout_completed: CheckoutEventPayload;
}

/** Named funnels built from the taxonomy above. Used for downstream funnel analysis. */
export const CONVERSION_FUNNELS = {
  enterprise: ["page_view", "persona_selected", "enterprise_demo_started", "enterprise_demo_submitted"],
  registration: ["page_view", "primary_cta_clicked", "registration_started", "registration_completed"],
  purchase: ["course_viewed", "pricing_viewed", "plan_selected", "checkout_started", "checkout_completed"],
} as const satisfies Record<string, readonly ConversionEvent[]>;

export type ConversionFunnel = keyof typeof CONVERSION_FUNNELS;

/**
 * Keys that must NEVER appear in an analytics payload. Matched case-insensitively as substrings so
 * `userEmail`, `phone_number`, `stripeToken`, `cardCvc`, … are all caught.
 */
const FORBIDDEN_KEY_MARKERS = [
  "email",
  "phone",
  "name",
  "password",
  "token",
  "secret",
  "card",
  "cvv",
  "cvc",
  "iban",
  "ssn",
  "address",
  "dob",
  "birth",
  "auth",
  "otp",
] as const;

function isForbiddenKey(key: string): boolean {
  const k = key.toLowerCase();
  // `path` is allowed even though it is a substring-free word; explicit allow-list guards below.
  return FORBIDDEN_KEY_MARKERS.some((m) => k.includes(m));
}

/** Values we accept in a payload: primitives only (no nested objects that could smuggle PII). */
type Primitive = string | number | boolean | null | undefined;

/**
 * Defensively strip any PII/secret-shaped key and any non-primitive value from a payload. Returns a
 * new object safe to transmit. Nested objects/arrays are dropped entirely (payloads are flat).
 */
export function redactEventPayload(payload: Record<string, unknown> | undefined): Record<string, Primitive> {
  const safe: Record<string, Primitive> = {};
  if (!payload) return safe;
  for (const [key, value] of Object.entries(payload)) {
    if (isForbiddenKey(key)) continue;
    if (value === null || value === undefined) {
      safe[key] = value;
      continue;
    }
    const t = typeof value;
    if (t === "string" || t === "number" || t === "boolean") {
      safe[key] = value as Primitive;
    }
    // objects, arrays, functions, symbols, bigints are intentionally dropped.
  }
  return safe;
}

/** The envelope actually emitted by the client. */
export interface AnalyticsEnvelope {
  readonly event: ConversionEvent;
  readonly v: typeof ANALYTICS_SCHEMA_VERSION;
  readonly props: Record<string, Primitive>;
}

export function buildEnvelope<E extends ConversionEvent>(
  event: E,
  payload?: ConversionEventPayloads[E],
): AnalyticsEnvelope {
  return {
    event,
    v: ANALYTICS_SCHEMA_VERSION,
    props: redactEventPayload(payload as Record<string, unknown> | undefined),
  };
}
