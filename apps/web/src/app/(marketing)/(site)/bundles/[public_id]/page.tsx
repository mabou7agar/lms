import { cache } from "react";
import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { getProduct } from "@/lib/commerce/api";
import { notFoundMetadata } from "@/lib/seo/not-found";
import { BundleDetailsClient } from "./bundle-details-client";

type Params = { params: Promise<{ public_id: string }> };

/**
 * Only a genuine 404 from the API counts as "not there". An outage or a timeout must not be
 * laundered into a permanent-looking not-found that a crawler would act on.
 */
const loadBundle = cache(async (publicId: string) => {
  try {
    await getProduct(publicId);
    return true;
  } catch (error) {
    return (error as { status?: number } | null)?.status !== 404;
  }
});

export async function generateMetadata({ params }: Params): Promise<Metadata> {
  const { public_id } = await params;
  if (!(await loadBundle(public_id))) return notFoundMetadata("Bundle not found");

  return {
    title: "Bundle — HElbaron",
    description:
      "Several HElbaron courses in one purchase, with the access and certificate terms shown up front.",
  };
}

/** A missing bundle renders the site's 404 page rather than the buy box for something unbuyable. */
export default async function BundleDetailPage({ params }: Params) {
  const { public_id } = await params;
  if (!(await loadBundle(public_id))) notFound();

  return <BundleDetailsClient />;
}
