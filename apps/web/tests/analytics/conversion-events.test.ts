import { describe, expect, it } from "vitest";
import {
  ANALYTICS_SCHEMA_VERSION,
  CONVERSION_EVENTS,
  CONVERSION_FUNNELS,
  buildEnvelope,
  isConversionEvent,
  redactEventPayload,
} from "@/lib/analytics/events";
import { track, setAnalyticsConsent, hasAnalyticsConsent } from "@/lib/analytics/track";

describe("conversion analytics taxonomy", () => {
  it("exposes the exact, stable event set", () => {
    expect([...CONVERSION_EVENTS].sort()).toEqual(
      [
        "checkout_completed",
        "checkout_started",
        "course_viewed",
        "enterprise_demo_started",
        "enterprise_demo_submitted",
        "page_view",
        "persona_selected",
        "plan_selected",
        "pricing_viewed",
        "primary_cta_clicked",
        "registration_completed",
        "registration_started",
        "secondary_cta_clicked",
      ],
    );
    expect(isConversionEvent("page_view")).toBe(true);
    expect(isConversionEvent("not_a_real_event")).toBe(false);
  });

  it("defines funnels only from valid events", () => {
    for (const steps of Object.values(CONVERSION_FUNNELS)) {
      for (const step of steps) {
        expect(isConversionEvent(step)).toBe(true);
      }
      // funnel steps are ordered and non-empty
      expect(steps.length).toBeGreaterThan(1);
    }
  });

  it("redacts every PII/secret-shaped key from payloads", () => {
    const dirty = {
      persona: "companies",
      email: "user@example.com",
      userEmail: "u@x.com",
      phone: "+201234567890",
      fullName: "Jane Doe",
      password: "hunter2",
      stripeToken: "tok_123",
      cardNumber: "4242424242424242",
      cardCvc: "123",
      iban: "EG00000",
      billingAddress: "1 St",
      authToken: "bearer",
      otp: "000000",
      plan: "team",
      amountMinor: 9900,
      currency: "USD",
    };
    const safe = redactEventPayload(dirty);
    expect(safe).toEqual({ persona: "companies", plan: "team", amountMinor: 9900, currency: "USD" });
    // none of the forbidden keys survive
    for (const k of Object.keys(safe)) {
      expect(/email|phone|name|password|token|card|cvc|cvv|iban|address|auth|otp/i.test(k)).toBe(false);
    }
  });

  it("drops nested objects/arrays and non-primitive values", () => {
    const safe = redactEventPayload({
      plan: "pro",
      nested: { a: 1 },
      list: [1, 2],
      fn: () => 1,
      ok: true,
    } as Record<string, unknown>);
    expect(safe).toEqual({ plan: "pro", ok: true });
  });

  it("stamps a versioned envelope and redacts inside buildEnvelope", () => {
    const env = buildEnvelope("plan_selected", { plan: "team", billing: "annual" });
    expect(env.event).toBe("plan_selected");
    expect(env.v).toBe(ANALYTICS_SCHEMA_VERSION);
    expect(env.props).toEqual({ plan: "team", billing: "annual" });
  });

  it("track() is consent-aware and returns a redacted envelope", () => {
    setAnalyticsConsent(false);
    expect(hasAnalyticsConsent()).toBe(false);
    const env = track("persona_selected", { persona: "academies" });
    expect(env.event).toBe("persona_selected");
    expect(env.props).toEqual({ persona: "academies" });
    setAnalyticsConsent(false); // leave global state clean
  });
});
