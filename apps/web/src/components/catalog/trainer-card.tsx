import Link from "next/link";
import type { Trainer } from "@/lib/catalog/api";
import { Card, CardContent } from "@/components/ui/card";
import { Avatar, AvatarFallback, AvatarImage } from "@/components/ui/avatar";
import { proxyMediaUrl } from "@/lib/media/proxy";

function initials(name: string) {
  return name.split(" ").map((p) => p[0]).slice(0, 2).join("").toUpperCase();
}

export function TrainerCard({ trainer }: { trainer: Trainer }) {
  return (
    <Link
      href={`/trainers/${trainer.id}`}
      className="block rounded-xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
    >
      <Card className="card-hover h-full hover:border-primary/30 hover:elevation-3">
        <CardContent className="flex items-center gap-4 p-5">
          <Avatar className="size-14">
            {trainer.avatar_path ? <AvatarImage src={proxyMediaUrl(trainer.avatar_path)} alt={trainer.name} /> : null}
            <AvatarFallback className="text-base">{initials(trainer.name)}</AvatarFallback>
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
