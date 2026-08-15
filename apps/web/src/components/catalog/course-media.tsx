import { proxyMediaUrl } from "@/lib/media/proxy";
import { cn } from "@/lib/utils";

/**
 * Course cover. When a real thumbnail URL is supplied (a published MediaAsset resolved server-side by
 * PublicAssetUrlResolver, or a legacy URL), it renders that image. Otherwise it falls back to a
 * designed, brand-palette SVG placeholder — deterministic per title — so a card is never blank.
 */
const PALETTES: [string, string][] = [
  ["var(--primary)", "oklch(0.27 0.04 190)"],
  ["var(--copper)", "var(--primary)"],
  ["var(--gold)", "var(--copper)"],
  ["oklch(0.30 0.045 190)", "var(--primary)"],
];

export function CourseMedia({ title, src, className }: { title: string; src?: string | null; className?: string }) {
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

  const hash = Array.from(title).reduce((a, c) => a + c.charCodeAt(0), 0);
  const [c1, c2] = PALETTES[hash % PALETTES.length];
  const initial = title.trim().charAt(0).toUpperCase() || "H";
  const gid = `cm-${hash % PALETTES.length}`;

  return (
    <svg
      viewBox="0 0 400 225"
      className={cn("aspect-video w-full", className)}
      preserveAspectRatio="xMidYMid slice"
      role="img"
      aria-hidden
    >
      <defs>
        <linearGradient id={gid} x1="0" y1="0" x2="1" y2="1">
          <stop offset="0" stopColor={c1} />
          <stop offset="1" stopColor={c2} />
        </linearGradient>
      </defs>
      <rect width="400" height="225" fill={`url(#${gid})`} />
      <circle cx="335" cy="42" r="72" fill="#fff" opacity="0.08" />
      <circle cx="58" cy="196" r="94" fill="#000" opacity="0.06" />
      <g stroke="#fff" strokeOpacity="0.14" strokeWidth="1.5" fill="none">
        <path d="M0 165 C 90 135, 150 185, 240 145 S 360 118, 400 148" />
        <path d="M0 192 C 90 162, 150 212, 240 172 S 360 142, 400 172" />
      </g>
      <text x="30" y="152" fontFamily="var(--font-serif, Georgia, serif)" fontSize="118" fontWeight="700" fill="#fff" fillOpacity="0.92">{initial}</text>
      <rect x="30" y="176" width="48" height="6" rx="3" fill="var(--gold)" />
    </svg>
  );
}
