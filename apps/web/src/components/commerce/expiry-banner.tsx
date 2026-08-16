"use client";

import { AlertTriangle, CalendarClock } from "lucide-react";
import { cn } from "@/lib/utils";

/**
 * The one banner used for everything that runs out: a company's purchased training, an employee's
 * seat access, a learner's certificate.
 *
 * NOT DISMISSIBLE, on purpose. A dismissal has to be remembered somewhere or it comes straight back
 * on the next render, and there is no per-user notification preference covering "I have seen this
 * banner". Rather than fake it with local state that resets on reload, the banner stays until the
 * thing it is warning about is no longer expiring — at which point it disappears on its own.
 *
 * Two tones: `warning` for something closing in, `expired` for something already gone. RTL comes
 * free from logical properties; nothing here is side-specific.
 */
export function ExpiryBanner({
  tone = "warning",
  title,
  detail,
  action,
  className,
}: {
  tone?: "warning" | "expired";
  title: string;
  detail?: string;
  action?: React.ReactNode;
  className?: string;
}) {
  const expired = tone === "expired";
  const Icon = expired ? AlertTriangle : CalendarClock;

  return (
    <div
      role="status"
      className={cn(
        "flex flex-wrap items-start gap-3 rounded-lg border p-4",
        expired
          ? "border-destructive/40 bg-destructive/5 text-destructive"
          : "border-copper/40 bg-copper/5 text-copper-foreground",
        className,
      )}
    >
      <Icon className={cn("mt-0.5 size-5 shrink-0", expired ? "text-destructive" : "text-copper")} aria-hidden />
      <div className="min-w-0 flex-1">
        <p className={cn("text-sm font-medium", expired ? "text-destructive" : "text-foreground")}>{title}</p>
        {detail ? <p className="mt-0.5 text-sm text-muted-foreground">{detail}</p> : null}
      </div>
      {action ? <div className="shrink-0">{action}</div> : null}
    </div>
  );
}
