import type { LucideIcon } from "lucide-react";
import { Card, CardContent } from "@/components/ui/card";

export function StatCard({ label, value, icon: Icon }: { label: string; value: string | number; icon?: LucideIcon }) {
  return (
    <Card className="group relative h-full overflow-hidden border-border/70 transition-all duration-300 hover:-translate-y-0.5 hover:border-copper/30 hover:shadow-lg">
      <span className="pointer-events-none absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-copper/30 to-transparent opacity-0 transition-opacity duration-300 group-hover:opacity-100" aria-hidden />
      <CardContent className="flex h-full items-center gap-4 p-5">
        {Icon ? (
          <div className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-copper/10 text-copper transition-transform duration-300 group-hover:scale-105">
            <Icon className="size-5" aria-hidden />
          </div>
        ) : null}
        <div className="min-w-0">
          <div className="font-serif text-3xl font-bold tabular-nums leading-none">{value}</div>
          <div className="mt-1.5 truncate text-sm text-muted-foreground">{label}</div>
        </div>
      </CardContent>
    </Card>
  );
}
