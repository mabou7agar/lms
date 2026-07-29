import type { Metadata } from "next";
import { MediaLibraryPanel } from "@/components/media/media-library-panel";

export const metadata: Metadata = {
  title: "Media library",
};

/**
 * Instructor media library page (P2/W04). The panel is a client component (React Query + upload
 * state); this route is a thin server wrapper. Manage actions inside the panel are permission-gated
 * via `useAuth`, mirroring the backend MediaAssetPolicy (owner / course-manager).
 */
export default function TeachMediaPage() {
  return (
    <div className="mx-auto w-full max-w-6xl px-4 py-6">
      <MediaLibraryPanel />
    </div>
  );
}
