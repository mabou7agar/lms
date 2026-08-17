"use client";

import { hasAnalyticsConsent } from "./track";

/**
 * Reporting the things only the browser can see — that a course page was looked at, that a CTA was
 * pressed, that somebody searched.
 *
 * Separate from `track.ts`, which owns the versioned conversion taxonomy and an optional external
 * collector. This one talks to OUR first-party endpoint in the shape the server's event log stores,
 * and exists because state cannot answer "how many people viewed this course and did not buy it":
 * looking leaves no row behind.
 *
 * THREE RULES, all of them about not being in the way:
 *  - consent first — nothing is sent unless the visitor has agreed, matching `track`;
 *  - never awaited and never thrown — a failed beacon must not change what the page does;
 *  - fire-and-forget via `sendBeacon` where available, so a page that is navigating away still
 *    reports what happened on it.
 *
 * The session id is a per-visit random string held in sessionStorage. It stitches a funnel together
 * within one visit and is not an identifier of a person: it is not stored against a user, does not
 * survive the tab closing, and means nothing afterwards.
 */

export type FunnelEvent =
  | "course_viewed"
  | "bundle_viewed"
  | "add_to_cart_clicked"
  | "checkout_started"
  | "search_performed"
  | "cta_clicked";

export type FunnelPayload = {
  course_id?: string;
  /** The typed search phrase. Content, not identity — and length-bounded server-side. */
  term?: string;
  /** Which control was pressed, e.g. "buy_now". Never free user text. */
  label?: string;
};

const SESSION_KEY = "hb_analytics_session";
const ENDPOINT = "/api/backend/analytics/events";

function sessionId(): string | undefined {
  if (typeof window === "undefined") return undefined;

  try {
    const existing = window.sessionStorage.getItem(SESSION_KEY);
    if (existing) return existing;

    const fresh = Math.random().toString(36).slice(2) + Date.now().toString(36);
    window.sessionStorage.setItem(SESSION_KEY, fresh);
    return fresh;
  } catch {
    // Private browsing or a blocked storage API. A funnel without stitching is still worth having.
    return undefined;
  }
}

/** UTM parameters off the current URL, so attribution follows the link that brought somebody here. */
function attribution(): Record<string, string> {
  if (typeof window === "undefined") return {};

  try {
    const params = new URLSearchParams(window.location.search);
    return Object.fromEntries(
      (["utm_source", "utm_medium", "utm_campaign"] as const)
        .map((key) => [key, params.get(key) ?? ""] as const)
        .filter(([, value]) => value !== ""),
    );
  } catch {
    return {};
  }
}

/**
 * Report one event. Returns nothing and cannot fail — callers are expected to call it inline, in the
 * middle of doing something else, without a `try` or an `await`.
 */
export function trackFunnel(name: FunnelEvent, payload: FunnelPayload = {}): void {
  if (typeof window === "undefined" || !hasAnalyticsConsent()) return;

  const body = JSON.stringify({
    events: [{ name, ...payload, session_id: sessionId(), ...attribution() }],
  });

  try {
    if (typeof navigator !== "undefined" && typeof navigator.sendBeacon === "function") {
      // Survives the page navigating away, which is exactly when a "clicked buy" event fires.
      navigator.sendBeacon(ENDPOINT, new Blob([body], { type: "application/json" }));
      return;
    }

    void fetch(ENDPOINT, {
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
