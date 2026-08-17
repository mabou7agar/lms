"use client";

import type { UseQueryResult } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { errorMessage, isAccessExpired, isAuthorizationError, isCourseAccessError } from "@/lib/api/errors";
import { useI18n } from "@/lib/i18n/i18n-context";
import { EmptyState } from "@/components/states/empty-state";
import { ErrorState } from "@/components/states/error-state";
import { LoadingState } from "@/components/states/loading-state";

export interface QueryStateProps<T> {
  query: UseQueryResult<T>;
  children: (data: T) => ReactNode;
  isEmpty?: (data: T) => boolean;
  empty?: ReactNode;
  loading?: ReactNode;
}

/** Renders loading/error/empty/content for a TanStack query with consistent, i18n'd states. */
export function QueryState<T>({ query, children, isEmpty, empty, loading }: QueryStateProps<T>) {
  const { t } = useI18n();

  if (query.isPending) return <>{loading ?? <LoadingState />}</>;
  if (query.isError) {
    // A refusal is not a failure, and retrying it forever will not help. Branching on the code
    // rather than the status matters: it says whether the learner's course access ran out, never
    // existed, or whether they simply are not permitted here — and none of those offer a retry.
    if (isAuthorizationError(query.error)) {
      const title = isAccessExpired(query.error)
        ? "common.accessEnded"
        : isCourseAccessError(query.error)
          ? "common.noAccess"
          : "common.notPermitted";

      return <ErrorState title={t(title)} message={errorMessage(query.error, t("common.error"))} />;
    }

    return <ErrorState message={errorMessage(query.error, t("common.error"))} onRetry={() => query.refetch()} />;
  }
  if (isEmpty?.(query.data)) return <>{empty ?? <EmptyState />}</>;
  return <>{children(query.data)}</>;
}
