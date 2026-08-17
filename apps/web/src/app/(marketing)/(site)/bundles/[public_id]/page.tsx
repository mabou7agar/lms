import { cache } from "react";
import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { getProduct } from "@/lib/commerce/api";
import { BundleDetailsClient } from "./bundle-details-client";

export const metadata: Metadata = {
  title: "Bundle — HElbaron",
  description: "Several HElbaron courses in one purchase, with the access and certificate terms shown up front.",
};

/** See the course page for why only a 404 from the API counts as "not found". */
const loadBundle = cache(async (publicId: string) => {
  try {
    await getProduct(publicId);
    return true;
  } catch (error) {
    return (error as { status?: number } | null)?.status !== 404;
  }
});

export default async function BundleDetailPage({ params }: { params: Promise<{ public_id: string }> }) {
  const { public_id } = await params;
  if (!(await loadBundle(public_id))) notFound();

  return <BundleDetailsClient />;
}
