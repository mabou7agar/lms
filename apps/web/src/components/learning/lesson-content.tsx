"use client";

import DOMPurify from "isomorphic-dompurify";
import { Download, ExternalLink, FileText } from "lucide-react";
import type { LessonPayload } from "@/lib/learning/api";
import { useI18n } from "@/lib/i18n/i18n-context";
import { Button } from "@/components/ui/button";
import { EmptyState } from "@/components/states/empty-state";
import { QuizPlayer } from "@/components/learning/quiz-player";

/**
 * Sanitize API-provided article HTML before injection. Allows standard formatting,
 * links and images; strips script/style/iframe, event handlers and unsafe URIs
 * (DOMPurify's default ALLOWED_URI_REGEXP applies).
 */
function sanitizeArticleHtml(dirty: string): string {
  return DOMPurify.sanitize(dirty, {
    ALLOWED_TAGS: [
      "a", "abbr", "b", "blockquote", "br", "caption", "code", "div", "em", "figcaption", "figure",
      "h1", "h2", "h3", "h4", "h5", "h6", "hr", "i", "img", "li", "mark", "ol", "p", "pre", "s",
      "small", "span", "strong", "sub", "sup", "table", "tbody", "td", "tfoot", "th", "thead", "tr", "u", "ul",
    ],
    ALLOWED_ATTR: ["href", "src", "alt", "title", "target", "rel", "class", "dir", "lang", "colspan", "rowspan", "width", "height", "start", "type"],
    FORBID_TAGS: ["script", "style", "iframe", "object", "embed", "form"],
    // Event handlers (on*) are stripped because they are not in ALLOWED_ATTR.
  });
}

function articleText(content: Record<string, unknown> | null): { html?: string; text?: string } {
  if (!content) return {};
  if (typeof content.html === "string") return { html: content.html };
  if (typeof content.body === "string") return { text: content.body };
  if (typeof content.text === "string") return { text: content.text };
  return { text: JSON.stringify(content, null, 2) };
}

/** Renders lesson media/content by type. Media is only ever the signed playback URL. */
export function LessonContent({
  lesson,
  videoRef,
  onVideoPause,
  onVideoLoaded,
}: {
  lesson: LessonPayload;
  videoRef?: React.RefObject<HTMLVideoElement | null>;
  onVideoPause?: (seconds: number) => void;
  onVideoLoaded?: (el: HTMLVideoElement) => void;
}) {
  const { t } = useI18n();
  const url = lesson.playback?.url;

  if (lesson.type === "video") {
    return (
      <div className="space-y-2">
        <div className="rounded-2xl border border-border/70 bg-black p-1.5 shadow-xl shadow-primary/10">
          <div className="aspect-video w-full overflow-hidden rounded-xl bg-black">
            {url ? (
              <video
                ref={videoRef}
                src={url}
                controls
                className="size-full"
                onLoadedMetadata={(e) => onVideoLoaded?.(e.currentTarget)}
                onPause={(e) => onVideoPause?.(Math.floor(e.currentTarget.currentTime))}
              />
            ) : (
              <div className="flex size-full items-center justify-center text-sm text-white/70">{t("learn.lesson.noContent")}</div>
            )}
          </div>
        </div>
        <p className="text-xs text-muted-foreground">{t("learn.lesson.videoNote")}</p>
      </div>
    );
  }

  if (lesson.type === "audio") {
    const transcript = typeof lesson.content?.transcript === "string" ? lesson.content.transcript : null;
    return (
      <div className="space-y-3">
        {url ? (
          <audio controls src={url} className="w-full" aria-label={lesson.title} />
        ) : (
          <EmptyState title={t("learn.lesson.noContent")} />
        )}
        {transcript ? (
          <details className="rounded-lg border p-3">
            <summary className="cursor-pointer text-sm font-medium">{t("learn.lesson.transcript")}</summary>
            <div className="prose mt-2 max-w-none whitespace-pre-line dark:prose-invert">{transcript}</div>
          </details>
        ) : null}
      </div>
    );
  }

  if (lesson.type === "pdf") {
    return url ? (
      <div className="space-y-3">
        <iframe title={lesson.title} src={url} className="h-[70vh] w-full rounded-lg border" />
        <Button asChild variant="outline">
          <a href={url} target="_blank" rel="noopener noreferrer">
            <FileText className="size-4" aria-hidden /> {t("learn.lesson.openPdf")}
          </a>
        </Button>
      </div>
    ) : (
      <EmptyState title={t("learn.lesson.noContent")} />
    );
  }

  if (lesson.type === "download") {
    return url ? (
      <Button asChild>
        <a href={url} target="_blank" rel="noopener noreferrer">
          <Download className="size-4" aria-hidden /> {t("learn.lesson.downloadFile")}
        </a>
      </Button>
    ) : (
      <EmptyState title={t("learn.lesson.noContent")} />
    );
  }

  if (lesson.type === "external_link") {
    const href = typeof lesson.content?.url === "string" ? lesson.content.url : url;
    return href ? (
      <Button asChild>
        <a href={href} target="_blank" rel="noopener noreferrer">
          <ExternalLink className="size-4" aria-hidden /> {t("learn.lesson.openLink")}
        </a>
      </Button>
    ) : (
      <EmptyState title={t("learn.lesson.noContent")} />
    );
  }

  if (lesson.type === "quiz_placeholder") {
    return <EmptyState title={t("learn.lesson.quizSoon")} />;
  }

  if (lesson.type === "quiz") {
    // `assessment` is null when nothing is attached OR when the attached assessment is still a
    // draft — the backend publish-gates it, so both cases look identical here by design and the
    // learner simply sees "not available" rather than a broken player.
    //
    // Everything past this point is the player's own concern: it owns loading, start/resume,
    // exhausted attempts and API failures, and surfaces the server's message verbatim. Lesson
    // completion is NOT triggered here — mounting a quiz is not finishing it, and progress is
    // recorded by the existing lesson flow, not by this branch.
    return lesson.assessment ? (
      <QuizPlayer assessmentId={lesson.assessment.id} />
    ) : (
      <EmptyState title={t("learn.lesson.quizUnavailable")} />
    );
  }

  // article
  const { html, text } = articleText(lesson.content);
  if (html) {
    // API-provided HTML is sanitized client-side before injection (defense in depth).
    return <div className="prose max-w-none dark:prose-invert" dangerouslySetInnerHTML={{ __html: sanitizeArticleHtml(html) }} />;
  }
  return text ? (
    <div className="whitespace-pre-line leading-relaxed text-foreground">{text}</div>
  ) : (
    <EmptyState title={t("learn.lesson.noContent")} />
  );
}
