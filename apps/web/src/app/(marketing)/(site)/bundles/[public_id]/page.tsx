import type { Metadata } from "next";
import { BundleDetailsClient } from "./bundle-details-client";

export const metadata: Metadata = {
  title: "Bundle — HElbaron",
  description: "Several HElbaron courses in one purchase, with the access and certificate terms shown up front.",
};

export default function BundleDetailPage() {
  return <BundleDetailsClient />;
}
