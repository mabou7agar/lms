"use client";

import {
  ANALYTICS_SCHEMA_VERSION,
  buildEnvelope,
  type AnalyticsEnvelope,
  type ConversionEvent,
  type ConversionEventPayloads,
} from "./events";

/**
 * First-party, consent-aware conversion-event emitter.
 *
 * Behaviour:
 *  - No third-party vendor and no network dependency: events are POSTed (via `navigator.sendBeacon`,
 *    falling back to a keepalive `fetch`) to a FIRST-PARTY collector only when
 *    `NEXT_PUBLIC_ANALYTICS_URL` is configured. When it is not set, `track` is a safe no-op — the
 *    taxonomy is still available for wiring and tests, and nothing is transmitted.
 *  - Consent-aware: nothing is sent unless consent has been granted for the session
 *    (`setAnalyticsConsent(true)`). Default is NO consent.
 *  - Payloads are redacted by {@link buildEnvelope} before they ever leave the client, so no PII or
 *    secret-shaped field can be transmitted even if passed by mistake.
 *  - SSR-safe (guards `typeof window`).
 */

const COLLECTOR_URL = process.env.NEXT_PUBLIC_ANALYTICS_URL;

let consentGranted = false;

export function setAnalyticsConsent(granted: boolean): void {
  consentGranted = granted;
}

export function hasAnalyticsConsent(): boolean {
  return consentGranted;
}

function send(envelope: AnalyticsEnvelope): void {
  if (!COLLECTOR_URL) return; // no configured first-party sink → safe no-op
  const body = JSON.stringify(envelope);
  try {
    if (typeof navigator !== "undefined" && typeof navigator.sendBeacon === "function") {
      navigator.sendBeacon(COLLECTOR_URL, new Blob([body], { type: "application/json" }));
      return;
    }
    void fetch(COLLECTOR_URL, {
      method: "POST",
      headers: { "content-type": "application/json" },
      body,
      keepalive: true,
      credentials: "same-origin",
    }).catch(() => {
      /* analytics must never break the UX */
    });
  } catch {
    /* analytics must never break the UX */
  }
}

/**
 * Emit a conversion event. Returns the (redacted) envelope that would be sent, so callers/tests can
 * assert on it. Transmission only occurs with consent AND a configured collector.
 */
export function track<E extends ConversionEvent>(
  event: E,
  payload?: ConversionEventPayloads[E],
): AnalyticsEnvelope {
  const envelope = buildEnvelope(event, payload);
  if (typeof window !== "undefined" && consentGranted) {
    send(envelope);
  }
  return envelope;
}

export { ANALYTICS_SCHEMA_VERSION };
