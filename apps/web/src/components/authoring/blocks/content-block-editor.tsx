"use client";

import dynamic from "next/dynamic";
import { FormField } from "@/components/ui/form-field";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { isSafeUrl } from "@/lib/authoring/block-content";
import { fieldsFor, type BlockFormValues } from "@/lib/authoring/content-blocks/registry";
import { useAuthoringI18n } from "@/lib/authoring/authoring-i18n";
import type { BlockKind } from "@/lib/authoring/types";
import { BilingualField } from "../editors/bilingual-field";
import { VideoSourceEditor } from "./video-source-editor";

const RichTextEditor = dynamic(() => import("../editors/rich-text-editor").then((m) => m.RichTextEditor), {
  ssr: false,
  loading: () => <div className="min-h-[12rem] animate-pulse rounded-md border bg-muted/40" />,
});

/**
 * Typed editor for a supported content block. Every field is derived from the shared field registry
 * (which mirrors the backend `BlockPayloadRules`), so the editor can never author a key the server
 * rejects. Localized fields edit both EN and AR through the shared BilingualField (Arabic RTL);
 * "shared" reference fields (URLs, storage keys, ids) use one control mirrored across locales. There
 * is no raw-JSON surface.
 */
export function ContentBlockEditor({
  kind,
  values,
  onChange,
}: {
  kind: BlockKind;
  values: BlockFormValues;
  onChange: (next: BlockFormValues) => void;
}) {
  const { t } = useAuthoringI18n();
  const fields = fieldsFor(kind);

  const setLocalized = (fieldKey: string, lang: "en" | "ar", text: string) =>
    onChange({ ...values, [fieldKey]: { ...values[fieldKey], [lang]: text } });
  // A shared reference is stored in every locale; mirror one input into both en and ar.
  const setShared = (fieldKey: string, text: string) => onChange({ ...values, [fieldKey]: { en: text, ar: text } });

  if (fields.length === 0) {
    return <p className="text-sm text-muted-foreground">{t("cblock.noFields")}</p>;
  }

  // Video / audio: an explicit provider selector over the genuinely-supported media sources
  // (Mux / direct URL / stored object) rather than three always-visible raw reference fields.
  if (kind === "video" || kind === "audio") {
    return <VideoSourceEditor values={values} onChange={onChange} />;
  }

  return (
    <div className="space-y-5">
      {fields.map((f) => {
        const value = values[f.key] ?? { en: "", ar: "" };
        const label = t(f.labelKey);
        const hint = f.hintKey ? t(f.hintKey) : undefined;

        if (f.control === "richtext") {
          // Rich text is inherently localized: an EN document and an AR (RTL) document.
          return (
            <div key={f.key} className="space-y-3">
              <div className="space-y-1.5">
                <p className="text-sm font-medium">
                  {label} · {t("i18n.english")}
                  {f.required ? <span aria-hidden className="ms-0.5 text-destructive">*</span> : null}
                </p>
                <RichTextEditor value={value.en} onChange={(html) => setLocalized(f.key, "en", html)} ariaLabel={`${label} · ${t("i18n.english")}`} />
              </div>
              <div className="space-y-1.5">
                <p className="text-sm font-medium">
                  {label} · {t("i18n.arabic")}
                </p>
                <RichTextEditor value={value.ar} onChange={(html) => setLocalized(f.key, "ar", html)} ariaLabel={`${label} · ${t("i18n.arabic")}`} />
                <p className="text-xs text-muted-foreground">{t("i18n.fallbackToEn")}</p>
              </div>
            </div>
          );
        }

        if (f.localized) {
          return (
            <BilingualField
              key={f.key}
              label={label}
              hint={hint}
              required={f.required}
              multiline={f.control === "textarea"}
              value={value}
              onChange={(lang, text) => setLocalized(f.key, lang, text)}
              error={f.required && value.en.trim() === "" ? t("cblock.required") : undefined}
            />
          );
        }

        // Shared (non-localized) reference field — one control, mirrored to both locales.
        const unsafeUrl = f.control === "url" && value.en.trim() !== "" && !isSafeUrl(value.en);
        return (
          <FormField
            key={f.key}
            label={label}
            hint={hint}
            required={f.required}
            error={
              f.required && value.en.trim() === ""
                ? t("cblock.required")
                : unsafeUrl
                  ? t("link.unsafe")
                  : undefined
            }
          >
            {f.control === "textarea" ? (
              <Textarea rows={4} dir="ltr" value={value.en} onChange={(e) => setShared(f.key, e.target.value)} />
            ) : (
              <Input
                type={f.control === "url" ? "url" : "text"}
                inputMode={f.control === "url" ? "url" : undefined}
                dir="ltr"
                value={value.en}
                onChange={(e) => setShared(f.key, e.target.value)}
                placeholder={f.control === "url" ? "https://" : undefined}
              />
            )}
          </FormField>
        );
      })}
    </div>
  );
}
