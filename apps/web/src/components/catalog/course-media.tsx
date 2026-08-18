import { CoverArt } from "@/components/marketing/course-cover/cover-art";
import { familyFromTitle } from "@/components/marketing/course-cover/adapter";
import { FAMILY_FIELD } from "@/components/marketing/course-cover/palette";
import { proxyMediaUrl } from "@/lib/media/proxy";
import { cn } from "@/lib/utils";

/**
 * Catalogue cover art. When a real image is supplied (a published MediaAsset resolved server-side
 * by PublicAssetUrlResolver, or a legacy URL) it renders that image.
 *
 * Otherwise it draws the same editorial field the HElbaron course covers use: a deep family
 * gradient, the deterministic technical artwork seeded from the title, and the gold hairline that
 * marks the brand. The earlier fallback set a single huge initial across the frame, which read as
 * a placeholder waiting for a real picture — twelve of them in a grid looked like an unfinished
 * catalogue rather than a designed one. Nothing here is invented data: the artwork is decorative
 * and stable per title, so a card is distinctive without claiming anything about the course.
 *
 * Purely decorative, so the SVG is aria-hidden and the surrounding card supplies the name.
 */
export function CatalogMedia({
  title,
  src,
  seed,
  className,
}: {
  title: string;
  src?: string | null;
  /** Overrides the title as the artwork seed — used where two items share a name. */
  seed?: string;
  className?: string;
}) {
  const resolvedSrc = proxyMediaUrl(src);
  if (resolvedSrc) {
    return (
      // eslint-disable-next-line @next/next/no-img-element -- resolved public/CDN URL; next/image adds no value on a decorative 16:9 cover
      <img
        src={resolvedSrc}
        alt=""
        width={400}
        height={225}
        className={cn("aspect-video w-full object-cover", className)}
        loading="lazy"
        decoding="async"
      />
    );
  }

  const family = familyFromTitle(title);
  const field = FAMILY_FIELD[family];

  return (
    <div
      className={cn("relative aspect-video w-full overflow-hidden", className)}
      style={{
        background: `linear-gradient(160deg, ${field.from} 0%, ${field.to} 100%)`,
      }}
      aria-hidden
    >
      <CoverArt
        family={family}
        seed={seed ?? title}
        className="absolute inset-0 size-full"
      />
      {/* Depth: light gathers at the top corner, the base settles into shadow. */}
      <span className="absolute inset-0 bg-[radial-gradient(120%_90%_at_82%_-10%,rgba(255,255,255,0.16),transparent_58%)]" />
      <span className="absolute inset-x-0 bottom-0 h-2/5 bg-gradient-to-t from-black/35 to-transparent" />
      {/* The gold rule is the brand signature every HElbaron cover carries. */}
      <span className="absolute bottom-4 start-5 h-[3px] w-10 rounded-full bg-[var(--gold)] opacity-90" />
    </div>
  );
}

/** Historical name — courses were the only thing with a cover when this was written. */
export const CourseMedia = CatalogMedia;
