import type { Metadata } from "next";

/**
 * Metadata for a public page whose subject does not exist.
 *
 * Ideally these pages would answer HTTP 404 and this would be unnecessary. They cannot, and the
 * reason is worth writing down because it looks like a bug otherwise: `(marketing)/loading.tsx`
 * puts a Suspense boundary above every marketing route, so Next flushes the shell — committing a
 * 200 — the moment anything suspends. Establishing that a course exists requires an API call, and
 * any await before `notFound()` happens after that flush. A synchronous `notFound()` still returns
 * 404; one that had to look something up first cannot.
 *
 * `noindex` is the standard mitigation, and it addresses what the status was for: a crawler told
 * not to index a page does not index it, whatever the status said. The visitor still sees the 404
 * page, because the route also calls notFound() for the body.
 *
 * Deleting the loading boundaries would restore the status, at the cost of the marketing area's
 * loading UI. That is a product decision, not a cleanup.
 */
export function notFoundMetadata(title = "Not found"): Metadata {
  return {
    title,
    robots: { index: false, follow: false },
  };
}
