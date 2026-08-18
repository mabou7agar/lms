import Link from "next/link";
import type { Trainer } from "@/lib/catalog/api";
import { Card, CardContent } from "@/components/ui/card";
import { Avatar, AvatarFallback, AvatarImage } from "@/components/ui/avatar";
import { proxyMediaUrl } from "@/lib/media/proxy";
import { MEDALLION_FILL } from "@/components/marketing/course-cover/palette";
import { hashString } from "@/components/marketing/course-cover/seed";
import type { MedallionKey } from "@/components/marketing/course-cover/types";

function initials(name: string) {
  return name.split(" ").map((p) => p[0]).slice(0, 2).join("").toUpperCase();
}

const MEDALLION_CYCLE: MedallionKey[] = ["navy", "copper", "teal", "plum", "olive", "burgundy", "indigo", "slate"];

/**
 * The faculty medallion a trainer without an uploaded photo wears — the same matte seal the course
 * covers use, picked deterministically from the name so a trainer keeps the same one everywhere.
 * A flat grey chip of initials reads as missing data; a seal reads as a house style.
 */
function medallion(name: string) {
  return MEDALLION_FILL[MEDALLION_CYCLE[hashString(name) % MEDALLION_CYCLE.length]!];
}

export function TrainerCard({ trainer }: { trainer: Trainer }) {
  return (
    <Link
      href={`/trainers/${trainer.id}`}
      className="block rounded-xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
    >
      <Card className="card-hover h-full hover:border-primary/30 hover:elevation-3">
        <CardContent className="flex items-center gap-4 p-5">
          <Avatar className="size-14 ring-1 ring-[var(--gold)]/40">
            {trainer.avatar_path ? <AvatarImage src={proxyMediaUrl(trainer.avatar_path)} alt={trainer.name} /> : null}
            <AvatarFallback
              className="text-base font-semibold tracking-wide text-white"
              style={{ backgroundImage: `linear-gradient(135deg, ${medallion(trainer.name).hi}, ${medallion(trainer.name).lo})` }}
            >
              {initials(trainer.name)}
            </AvatarFallback>
          </Avatar>
          <div className="min-w-0">
            <p className="truncate font-serif font-semibold">{trainer.name}</p>
            {trainer.headline ? <p className="line-clamp-2 text-sm text-muted-foreground">{trainer.headline}</p> : null}
          </div>
        </CardContent>
      </Card>
    </Link>
  );
}
