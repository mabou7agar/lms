"use client";

import type { ReactNode } from "react";
import { ErrorBoundary } from "@/components/states/error-boundary";
import { Card, CardContent } from "@/components/ui/card";

export interface DashboardSectionProps {
  /** Rendered as the section's accessible name. */
  title: string;
  id: string;
  action?: ReactNode;
  children: ReactNode;
}

/**
 * One dashboard section, isolated so its failure is contained.
 *
 * The dashboard fans out to four independent endpoints. Without isolation, one of them throwing
 * during render takes the whole page down and an instructor loses the three sections that loaded
 * perfectly well. Each section is its own error boundary AND its own landmark, so the page
 * degrades section by section.
 *
 * Query failures are handled separately by QueryState inside each section — this boundary catches
 * the render-time errors QueryState cannot, such as a malformed payload reaching a formatter.
 */
export function DashboardSection({ title, id, action, children }: DashboardSectionProps) {
  const headingId = `${id}-heading`;

  return (
    <section aria-labelledby={headingId} className="space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 id={headingId} className="text-lg font-semibold">
          {title}
        </h2>
        {action}
      </div>

      <ErrorBoundary
        fallback={
          <Card>
            <CardContent className="p-6 text-sm text-muted-foreground" role="alert">
              {/* Deliberately terse and non-blocking: the rest of the dashboard is still usable. */}
              {title}
            </CardContent>
          </Card>
        }
      >
        {children}
      </ErrorBoundary>
    </section>
  );
}
