"use client";

import { useEffect, useRef, useState } from "react";
import type { LocaleCode, LocalizedText } from "@/lib/authoring/types";

/**
 * Local text state that autosaves (debounced) and on blur, without ever calling setState inside an
 * effect. `commit` should be idempotent for unchanged values (the store actions early-return).
 */
export function useFieldAutosave(initial: string, commit: (value: string) => void | Promise<void>, delay = 600) {
  const [value, setValue] = useState(initial);
  const committed = useRef(initial);

  useEffect(() => {
    if (value === committed.current) return;
    const id = setTimeout(() => {
      committed.current = value;
      void commit(value);
    }, delay);
    return () => clearTimeout(id);
  }, [value, commit, delay]);

  const flush = () => {
    if (value !== committed.current) {
      committed.current = value;
      void commit(value);
    }
  };

  return { value, setValue, flush };
}

/**
 * Bilingual sibling of {@link useFieldAutosave}: debounced autosave for a `{ en, ar }` field, with a
 * per-language setter. Commits the whole map so a single mutation carries both languages, and only
 * fires when a value actually changed (the store actions early-return on no-ops).
 */
export function useLocalizedAutosave(
  initial: LocalizedText,
  commit: (value: LocalizedText) => void | Promise<void>,
  delay = 600,
) {
  const [value, setValue] = useState<LocalizedText>(initial);
  const committed = useRef(initial);

  useEffect(() => {
    if (value.en === committed.current.en && value.ar === committed.current.ar) return;
    const id = setTimeout(() => {
      committed.current = value;
      void commit(value);
    }, delay);
    return () => clearTimeout(id);
  }, [value, commit, delay]);

  const setLang = (lang: LocaleCode, text: string) => setValue((prev) => ({ ...prev, [lang]: text }));

  const flush = () => {
    if (value.en !== committed.current.en || value.ar !== committed.current.ar) {
      committed.current = value;
      void commit(value);
    }
  };

  return { value, setLang, flush };
}
