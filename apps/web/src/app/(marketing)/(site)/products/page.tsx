import { permanentRedirect } from "next/navigation";

/**
 * "Products" was never buyer-facing language, and the page listed courses and bundles together
 * under one generic heading. Courses are browsed at /courses and bundles at /bundles, so this
 * redirects rather than keeping a third, vaguer catalogue alive.
 */
export default function ProductsPage() {
  permanentRedirect("/bundles");
}
