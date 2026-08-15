"use client";

import { proxyMediaUrl } from "@/lib/media/proxy";

/**
 * Universal course promo/trailer player. Accepts EITHER an uploaded/local media URL (served by the API
 * media origin, or a direct .mp4/.webm/.ogg/.m3u8 file) OR an external share URL from a supported
 * platform (YouTube, Vimeo, Wistia, Loom, Dailymotion), and renders the right element:
 *   - a native <video> for direct media files / API public-media URLs (played same-origin via proxyMediaUrl in dev)
 *   - a responsive provider <iframe> for platform URLs
 *   - a best-effort raw <iframe> for an unknown-but-valid http(s) URL
 * Returns null for empty/unsafe input. All embed origins are allowlisted in next.config.ts `frame-src`.
 */

type Embed = { src: string; kind: "iframe" | "video" };

/** Only http/https URLs are ever embedded; anything else (javascript:, data:, relative-only, empty) is rejected. */
function safeUrl(raw?: string | null): URL | null {
  if (!raw) return null;
  try {
    // Support same-origin relative media paths (e.g. "/media/public/…") by resolving against a base.
    const u = new URL(raw, "http://_local_");
    if (u.protocol === "http:" || u.protocol === "https:") return u;
  } catch {
    /* fall through */
  }
  return null;
}

/** True when the URL points at a directly-playable media file we should render with a <video> tag. */
function isDirectMedia(u: URL): boolean {
  const path = u.pathname.toLowerCase();
  return (
    /\.(mp4|webm|ogg|m3u8)$/.test(path) ||
    // API public-media (uploaded MediaAsset) — absolute API-origin OR the same-origin proxy path.
    path.includes("/media/public/")
  );
}

/** Extract the last non-empty path segment (handy for /video/ID, /share/ID, /embed/ID, etc.). */
function lastSegment(u: URL): string {
  const parts = u.pathname.split("/").filter(Boolean);
  return parts[parts.length - 1] ?? "";
}

/**
 * Parse a supported provider share/watch/embed URL into its canonical EMBED iframe src.
 * Returns null when the host is not a recognised video provider.
 */
function providerEmbedSrc(u: URL): string | null {
  const host = u.hostname.replace(/^www\./, "").toLowerCase();
  const segs = u.pathname.split("/").filter(Boolean);

  // YouTube — watch?v=ID | youtu.be/ID | /embed/ID | /shorts/ID
  if (host === "youtube.com" || host === "m.youtube.com" || host === "youtube-nocookie.com") {
    const id =
      u.searchParams.get("v") ||
      (segs[0] === "embed" || segs[0] === "shorts" ? segs[1] : "") ||
      "";
    if (id) return `https://www.youtube-nocookie.com/embed/${id}`;
  }
  if (host === "youtu.be") {
    const id = segs[0] ?? "";
    if (id) return `https://www.youtube-nocookie.com/embed/${id}`;
  }

  // Vimeo — vimeo.com/ID (optional /HASH or ?h=HASH) | player.vimeo.com/video/ID
  if (host === "vimeo.com" || host === "player.vimeo.com") {
    const idIdx = segs[0] === "video" ? 1 : 0;
    const id = segs[idIdx] ?? "";
    if (/^\d+$/.test(id)) {
      const hash = u.searchParams.get("h") || (segs[idIdx + 1] ?? "");
      return `https://player.vimeo.com/video/${id}${hash ? `?h=${hash}` : ""}`;
    }
  }

  // Wistia — wistia.com/medias/ID | *.wistia.com/… | wi.st/… (last path segment is the media id)
  if (host === "wistia.com" || host.endsWith(".wistia.com") || host === "wi.st" || host.endsWith(".wi.st")) {
    const mediasIdx = segs.indexOf("medias");
    const id = mediasIdx >= 0 ? segs[mediasIdx + 1] : lastSegment(u);
    if (id) return `https://fast.wistia.net/embed/iframe/${id}`;
  }

  // Loom — loom.com/share/ID (or /embed/ID)
  if (host === "loom.com") {
    const shareIdx = segs.indexOf("share");
    const embedIdx = segs.indexOf("embed");
    const id = shareIdx >= 0 ? segs[shareIdx + 1] : embedIdx >= 0 ? segs[embedIdx + 1] : lastSegment(u);
    if (id) return `https://www.loom.com/embed/${id}`;
  }

  // Dailymotion — dailymotion.com/video/ID | dai.ly/ID
  if (host === "dailymotion.com" || host === "dai.ly") {
    const videoIdx = segs.indexOf("video");
    const id = videoIdx >= 0 ? segs[videoIdx + 1] : segs[0] ?? "";
    if (id) return `https://geo.dailymotion.com/player.html?video=${id}`;
  }

  return null;
}

/** Resolve a raw url to an embed descriptor (provider iframe, native video, or raw-iframe fallback), or null. */
function resolveEmbed(raw?: string | null): Embed | null {
  const u = safeUrl(raw);
  if (!u) return null;

  const provider = providerEmbedSrc(u);
  if (provider) return { src: provider, kind: "iframe" };

  if (isDirectMedia(u)) return { src: raw as string, kind: "video" };

  // Unknown but valid http(s) URL — best-effort raw iframe (only reached for absolute URLs).
  if (/^https?:$/.test(u.protocol) && u.hostname !== "_local_") {
    return { src: raw as string, kind: "iframe" };
  }
  return null;
}

/** Whether the given url can be rendered by <VideoEmbed> (any provider, direct file, or valid http(s) url). */
export function hasEmbeddableVideo(url?: string | null): boolean {
  return resolveEmbed(url) !== null;
}

export function VideoEmbed({
  url,
  title,
  className,
  poster,
}: {
  url: string;
  title?: string;
  className?: string;
  poster?: string;
}) {
  const embed = resolveEmbed(url);
  if (!embed) return null;

  if (embed.kind === "video") {
    return (
      <div className={`relative aspect-video w-full ${className ?? ""}`}>
        {/* Uploaded/local media: proxied to same-origin in dev, identity in prod. Native HLS handles .m3u8. */}
        <video
          src={proxyMediaUrl(embed.src)}
          poster={poster}
          controls
          playsInline
          preload="metadata"
          className="absolute inset-0 h-full w-full object-cover"
          title={title || "Video"}
        />
      </div>
    );
  }

  return (
    <div className={`relative aspect-video w-full ${className ?? ""}`}>
      <iframe
        src={embed.src}
        title={title || "Video"}
        className="absolute inset-0 h-full w-full"
        loading="lazy"
        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
        allowFullScreen
      />
    </div>
  );
}
