"use client";

import { useEffect, useRef } from "react";
import { useI18n } from "@/lib/i18n/i18n-context";
import { track } from "@/lib/analytics/track";

/** Fires a single homepage `page_view` after hydration (ref-guarded against StrictMode/re-render). */
export function HomeAnalytics() {
  const { locale } = useI18n();
  const fired = useRef(false);
  useEffect(() => {
    if (fired.current) return;
    fired.current = true;
    track("page_view", { locale, path: "/" });
  }, [locale]);
  return null;
}
