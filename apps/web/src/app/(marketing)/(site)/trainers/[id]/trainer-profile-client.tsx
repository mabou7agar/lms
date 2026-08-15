"use client";

import Link from "next/link";
import { ArrowLeft, Globe, GraduationCap } from "lucide-react";
import { useTrainer } from "@/lib/catalog/hooks";
import { proxyMediaUrl } from "@/lib/media/proxy";
import { QueryState } from "@/components/student/query-state";
import { CourseCard } from "@/components/catalog/course-card";
import { Avatar, AvatarFallback, AvatarImage } from "@/components/ui/avatar";
import { Badge } from "@/components/ui/badge";
import { EmptyState } from "@/components/states/empty-state";

function initials(name: string) {
  return name.split(" ").map((p) => p[0]).slice(0, 2).join("").toUpperCase();
}

export function TrainerProfileClient({ id }: { id: string }) {
  const query = useTrainer(id);

  return (
    <QueryState query={query}>
      {({ profile, courses }) => {
        const avatar = proxyMediaUrl(profile.profile_photo ?? profile.avatar_path);
        const cover = proxyMediaUrl(profile.cover_photo);
        return (
          <div className="space-y-10">
            <Link
              href="/trainers"
              className="inline-flex items-center gap-1.5 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
            >
              <ArrowLeft className="size-4 rtl:rotate-180" aria-hidden /> Trainers
            </Link>

            <header className="overflow-hidden rounded-3xl border border-border bg-card">
              <div className="relative h-40 w-full bg-gradient-to-br from-primary/15 via-copper/10 to-transparent sm:h-52">
                {cover ? (
                  // eslint-disable-next-line @next/next/no-img-element
                  <img src={cover} alt="" className="h-full w-full object-cover" loading="lazy" decoding="async" />
                ) : null}
              </div>
              <div className="flex flex-col gap-4 p-6 sm:flex-row sm:items-end sm:gap-6">
                <Avatar className="-mt-16 size-28 border-4 border-card shadow-lg sm:-mt-20 sm:size-32">
                  {avatar ? <AvatarImage src={avatar} alt={profile.name} className="object-cover" /> : null}
                  <AvatarFallback className="text-3xl">{initials(profile.name)}</AvatarFallback>
                </Avatar>
                <div className="min-w-0 flex-1">
                  <h1 className="font-serif text-2xl font-bold sm:text-3xl">{profile.name}</h1>
                  {profile.headline ? (
                    <p className="mt-1 text-base text-muted-foreground">{profile.headline}</p>
                  ) : null}
                  {profile.website ? (
                    <a
                      href={profile.website}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="mt-2 inline-flex items-center gap-1.5 text-sm font-medium text-primary hover:underline"
                    >
                      <Globe className="size-4" aria-hidden /> Website
                    </a>
                  ) : null}
                </div>
              </div>
            </header>

            {profile.bio ? (
              <section className="max-w-3xl">
                <h2 className="mb-3 font-serif text-lg font-semibold">About</h2>
                <p className="whitespace-pre-line leading-relaxed text-muted-foreground">{profile.bio}</p>
              </section>
            ) : null}

            {profile.specialties.length > 0 ? (
              <section>
                <h2 className="mb-3 font-serif text-lg font-semibold">Specialties</h2>
                <div className="flex flex-wrap gap-2">
                  {profile.specialties.map((s) => (
                    <Badge key={s} variant="secondary">{s}</Badge>
                  ))}
                </div>
              </section>
            ) : null}

            <section>
              <h2 className="mb-4 flex items-center gap-2 font-serif text-lg font-semibold">
                <GraduationCap className="size-5 text-copper" aria-hidden />
                Courses by {profile.name}
              </h2>
              {courses.length > 0 ? (
                <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                  {courses.map((course) => (
                    <CourseCard key={course.id} course={course} />
                  ))}
                </div>
              ) : (
                <EmptyState icon={<GraduationCap className="size-8" />} title="No published courses yet" />
              )}
            </section>
          </div>
        );
      }}
    </QueryState>
  );
}
