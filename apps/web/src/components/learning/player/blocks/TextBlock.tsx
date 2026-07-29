'use client';

import type { LessonBlock } from '@/lib/learning/player-api';

/**
 * Text block. Renders the block body as plain text by default. If the backend
 * ever returns trusted, pre-sanitized HTML, the integrator can swap this for a
 * sanitized-HTML renderer — we deliberately do NOT dangerouslySetInnerHTML here.
 */
export function TextBlock({ block }: { block: LessonBlock }): React.ReactElement {
  return (
    <div className="prose prose-neutral max-w-none whitespace-pre-wrap" data-testid="text-block">
      {block.body ?? ''}
    </div>
  );
}
