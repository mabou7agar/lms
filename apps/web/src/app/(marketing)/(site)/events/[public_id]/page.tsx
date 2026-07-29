import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { cache } from "react";
import { getEvent, type EventDetail } from "@/lib/events/api";
import { getSeo } from "@/lib/seo/api";
import { resolveLocale } from "@/lib/seo/locale";
import { buildMetadata, seoJsonLd } from "@/lib/seo/metadata";
import { EventDetailsClient } from "./event-details-client";
import { jsonLdScript } from "@/lib/seo/json-ld";

/** Deduped server-side fetch shared by generateMetadata and the page render. */
const loadEvent = cache(async (publicId: string): Promise<EventDetail | null> => {
  try {
    return await getEvent(publicId);
  } catch {
    return null;
  }
});

type Params = { params: Promise<{ public_id: string }> };

export async function generateMetadata({ params }: Params): Promise<Metadata> {
  const { public_id } = await params;
  const event = await loadEvent(public_id);
  if (!event) return { title: "Event", description: "Event details on HElbaron." };

  // Current derived metadata is the fallback; a managed SEO override (if any) wins via the shared helper.
  const description = event.description?.slice(0, 160) ?? `Join ${event.title} — a live event at HElbaron.`;
  const fallback: Metadata = {
    title: event.title,
    description,
    alternates: { canonical: `/events/${event.id}` },
    openGraph: { title: event.title, description, url: `/events/${event.id}`, type: "website" },
  };

  const [seo, locale] = await Promise.all([getSeo("event", event.id), resolveLocale()]);
  return buildMetadata(seo, fallback, locale);
}

/** Build the schema.org Event JSON-LD from the backend-provided SEO fields. */
function eventJsonLd(event: EventDetail) {
  return {
    "@context": "https://schema.org",
    "@type": "Event",
    name: event.seo.name,
    startDate: event.seo.startDate,
    endDate: event.seo.endDate,
    eventStatus: event.seo.eventStatus,
    eventAttendanceMode: event.seo.eventAttendanceMode,
    location: { "@type": "VirtualLocation", name: event.seo.location },
    organizer: { "@type": "Organization", name: "HElbaron" },
    ...(event.description ? { description: event.description } : {}),
  };
}

export default async function EventDetailsPage({ params }: Params) {
  const { public_id } = await params;
  const event = await loadEvent(public_id);
  if (!event) notFound();

  // Prefer an admin-supplied JSON-LD override (already validated server-side); else the event schema.
  const override = seoJsonLd(await getSeo("event", event.id));
  const jsonLd = override ?? eventJsonLd(event);

  return (
    <>
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{ __html: jsonLdScript(jsonLd) }}
      />
      <EventDetailsClient event={event} />
    </>
  );
}
