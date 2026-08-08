"use client";

import DOMPurify from "isomorphic-dompurify";
import { Download, ExternalLink, FileText, ListChecks, Music, Video } from "lucide-react";
import { isSafeUrl } from "@/lib/authoring/block-content";
import { useAuthoringI18n } from "@/lib/authoring/authoring-i18n";
import type { BlockKind, LocaleCode } from "@/lib/authoring/types";
import type { BlockContentI18n, BlockLocalePayload } from "@/lib/authoring/content-blocks/types";

/**
 * Read-only preview of a content block. Mirrors how the LEARNER runtime renders each type:
 *   • article → sanitized rich HTML (identical DOMPurify allowlist to `learning/lesson-content.tsx`)
 *   • external_link → a safe (http/https only) outbound link
 *   • pdf / download → the referenced file (filename + link)
 *   • video / audio → the referenced media source (authoring cannot sign a playback URL, so the
 *     reference is shown rather than a live player)
 *   • quiz_placeholder → the author note
 *   • quiz → the linked assessment id
 *
 * Content is resolved for the active locale with Arabic falling back to English, exactly as the
 * learner sees it — the map itself is never shown.
 */

/** Same allowlist the learner runtime (`learning/lesson-content.tsx`) applies to article HTML. */
function sanitizeArticleHtml(dirty: string): string {
  return DOMPurify.sanitize(dirty, {
    ALLOWED_TAGS: [
      "a", "abbr", "b", "blockquote", "br", "caption", "code", "div", "em", "figcaption", "figure",
      "h1", "h2", "h3", "h4", "h5", "h6", "hr", "i", "img", "li", "mark", "ol", "p", "pre", "s",
      "small", "span", "strong", "sub", "sup", "table", "tbody", "td", "tfoot", "th", "thead", "tr", "u", "ul",
    ],
    ALLOWED_ATTR: ["href", "src", "alt", "title", "target", "rel", "class", "dir", "lang", "colspan", "rowspan", "width", "height", "start", "type"],
    FORBID_TAGS: ["script", "style", "iframe", "object", "embed", "form"],
  });
}

function str(payload: BlockLocalePayload | undefined, key: string): string {
  const value = payload?.[key];
  return typeof value === "string" ? value : "";
}

/** Resolve a single field for the active locale, falling back to the other language when empty. */
function field(content: BlockContentI18n, key: string, locale: LocaleCode): string {
  const primary = str(content[locale], key);
  if (primary.trim() !== "") return primary;
  const other: LocaleCode = locale === "ar" ? "en" : "ar";
  return str(content[other], key);
}

function ReferenceRow({ icon, label, value }: { icon: React.ReactNode; label: string; value: string }) {
  return (
    <div className="flex items-center gap-2 rounded-md border border-border/60 bg-muted/30 px-3 py-2 text-sm">
      <span className="text-muted-foreground" aria-hidden>
        {icon}
      </span>
      <span className="font-medium">{label}:</span>
      <span dir="ltr" className="min-w-0 flex-1 truncate font-mono text-xs text-muted-foreground">
        {value}
      </span>
    </div>
  );
}

export function ContentBlockPreview({ kind, contentI18n }: { kind: BlockKind; contentI18n: BlockContentI18n }) {
  const { t, locale } = useAuthoringI18n();
  const loc: LocaleCode = locale === "ar" ? "ar" : "en";
  const isArabic = loc === "ar";

  const get = (key: string) => field(contentI18n, key, loc);

  if (kind === "article") {
    const html = get("html");
    if (html.trim() === "") return <Empty />;
    return (
      <div
        className="prose prose-sm max-w-none dark:prose-invert"
        dir={isArabic ? "rtl" : "ltr"}
        lang={loc}
        // Sanitized with the learner runtime's exact allowlist above.
        dangerouslySetInnerHTML={{ __html: sanitizeArticleHtml(html) }}
      />
    );
  }

  if (kind === "external_link") {
    const url = get("url");
    const label = get("label") || url;
    if (url.trim() === "") return <Empty />;
    if (!isSafeUrl(url)) return <p className="text-sm text-destructive">{t("link.unsafe")}</p>;
    return (
      <a
        href={url}
        target="_blank"
        rel="noopener noreferrer"
        className="inline-flex items-center gap-2 text-sm font-medium text-primary underline underline-offset-4"
      >
        <ExternalLink className="size-4" aria-hidden />
        {label}
      </a>
    );
  }

  if (kind === "pdf" || kind === "download") {
    const url = get("url");
    const filename = get("filename") || url || str(contentI18n[loc], "s3_key") || field(contentI18n, "s3_key", loc);
    if (url.trim() === "" && filename.trim() === "") return <Empty />;
    const icon = kind === "pdf" ? <FileText className="size-4" aria-hidden /> : <Download className="size-4" aria-hidden />;
    if (url.trim() !== "" && isSafeUrl(url)) {
      return (
        <a
          href={url}
          target="_blank"
          rel="noopener noreferrer"
          className="inline-flex items-center gap-2 text-sm font-medium text-primary underline underline-offset-4"
        >
          {icon}
          {filename || t("cblock.preview.download")}
        </a>
      );
    }
    return <ReferenceRow icon={icon} label={t("cblock.preview.download")} value={filename} />;
  }

  if (kind === "video" || kind === "audio") {
    const ref = get("mux_playback_id") || get("url") || get("s3_key");
    if (ref.trim() === "") return <Empty />;
    const icon = kind === "video" ? <Video className="size-4" aria-hidden /> : <Music className="size-4" aria-hidden />;
    return <ReferenceRow icon={icon} label={t("cblock.preview.mediaRef")} value={ref} />;
  }

  if (kind === "quiz_placeholder") {
    const note = get("note");
    if (note.trim() === "") return <Empty />;
    return (
      <p className="whitespace-pre-wrap text-sm text-muted-foreground" dir={isArabic ? "rtl" : "ltr"} lang={loc}>
        {note}
      </p>
    );
  }

  if (kind === "quiz") {
    const id = get("assessment_public_id");
    if (id.trim() === "") return <Empty />;
    return <ReferenceRow icon={<ListChecks className="size-4" aria-hidden />} label={t("cblock.preview.assessmentRef")} value={id} />;
  }

  return <Empty />;
}

function Empty() {
  const { t } = useAuthoringI18n();
  return <p className="text-sm text-muted-foreground">{t("cblock.preview.empty")}</p>;
}
