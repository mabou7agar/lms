"use client";

import { useSearchParams } from "next/navigation";
import { Suspense, useEffect, useMemo, useState } from "react";
import { Search } from "lucide-react";
import { useI18n } from "@/lib/i18n/i18n-context";
import { useCategories, useCourses } from "@/lib/catalog/hooks";
import { flattenCategories } from "@/lib/catalog/api";
import { PageHero } from "@/components/marketing/page-hero";
import { QueryState } from "@/components/student/query-state";
import { CourseCard } from "@/components/catalog/course-card";
import { Input } from "@/components/ui/input";
import { Checkbox } from "@/components/ui/checkbox";
import { Button } from "@/components/ui/button";
import { Pagination } from "@/components/ui/pagination";
import { EmptyState } from "@/components/states/empty-state";
import { cn } from "@/lib/utils";

const controlClass =
  "flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2";

function CoursesCatalog() {
  const { t } = useI18n();
  const params = useSearchParams();
  const categoriesQuery = useCategories();

  const [q, setQ] = useState("");
  const [debouncedQ, setDebouncedQ] = useState("");
  const [category, setCategory] = useState(params.get("category") ?? "");
  const [featured, setFeatured] = useState(params.get("featured") === "1");
  const [page, setPage] = useState(1);
  // Client-side refinements (no backend options endpoint for level/language).
  const [level, setLevel] = useState("");
  const [language, setLanguage] = useState("");

  useEffect(() => {
    const id = setTimeout(() => setDebouncedQ(q), 300);
    return () => clearTimeout(id);
  }, [q]);

  // Reset to page 1 when the search/filters change — done during render (React's documented
  // "adjust state while rendering" pattern) instead of in an effect, which avoids the extra
  // cascading render. The prevKey guard makes this run exactly once per filter change.
  const filterKey = `${debouncedQ}|${category}|${featured}`;
  const [prevFilterKey, setPrevFilterKey] = useState(filterKey);
  if (filterKey !== prevFilterKey) {
    setPrevFilterKey(filterKey);
    setPage(1);
  }

  const query = useCourses({ q: debouncedQ || undefined, category: category || undefined, featured, page, per_page: 12 });

  // Memoize on the query result so the derived level/language sets are stable across renders.
  const items = useMemo(() => query.data?.data ?? [], [query.data]);
  const levels = useMemo(() => Array.from(new Set(items.map((c) => c.level).filter(Boolean))) as string[], [items]);
  const languages = useMemo(() => Array.from(new Set(items.map((c) => c.language).filter(Boolean))) as string[], [items]);
  const refined = items.filter((c) => (!level || c.level === level) && (!language || c.language === language));

  const categoryOptions = flattenCategories(categoriesQuery.data ?? []);
  const clear = () => {
    setQ(""); setCategory(""); setFeatured(false); setLevel(""); setLanguage(""); setPage(1);
  };

  return (
    <div>
      <PageHero page="courses" />

      <div className="mb-6 grid gap-3 rounded-lg border bg-card p-4 sm:grid-cols-2 lg:grid-cols-4">
        <div className="relative sm:col-span-2 lg:col-span-1">
          <Search className="pointer-events-none absolute inset-y-0 start-3 my-auto size-4 text-muted-foreground" aria-hidden />
          <Input className="ps-9" placeholder={t("catalog.courses.search")} value={q} onChange={(e) => setQ(e.target.value)} aria-label={t("catalog.courses.search")} />
        </div>
        <select className={controlClass} value={category} onChange={(e) => setCategory(e.target.value)} aria-label={t("catalog.courses.allCategories")}>
          <option value="">{t("catalog.courses.allCategories")}</option>
          {categoryOptions.map((c) => (
            <option key={c.id} value={c.id}>{`${"— ".repeat(c.depth)}${c.name}`}</option>
          ))}
        </select>
        <select className={controlClass} value={level} onChange={(e) => setLevel(e.target.value)} aria-label={t("catalog.courses.level")}>
          <option value="">{t("catalog.courses.allLevels")}</option>
          {levels.map((l) => <option key={l} value={l}>{l}</option>)}
        </select>
        <select className={controlClass} value={language} onChange={(e) => setLanguage(e.target.value)} aria-label={t("catalog.courses.language")}>
          <option value="">{t("catalog.courses.allLanguages")}</option>
          {languages.map((l) => <option key={l} value={l}>{l}</option>)}
        </select>
        <label className="flex items-center gap-2 text-sm text-muted-foreground">
          <Checkbox checked={featured} onChange={(e) => setFeatured(e.target.checked)} /> {t("catalog.courses.featuredOnly")}
        </label>
        <div className="lg:col-span-3 lg:text-end">
          <Button variant="ghost" size="sm" onClick={clear}>{t("catalog.courses.clear")}</Button>
        </div>
      </div>

      <QueryState query={query} isEmpty={() => refined.length === 0} empty={<EmptyState title={t("catalog.courses.empty")} />}>
        {(data) => (
          <div className="space-y-6">
            <div className={cn("stagger-in grid gap-4 sm:grid-cols-2 lg:grid-cols-3")}>
              {refined.map((c) => <CourseCard key={c.id} course={c} />)}
            </div>
            <Pagination page={data.meta.current_page} lastPage={data.meta.last_page} onPageChange={setPage} />
          </div>
        )}
      </QueryState>
    </div>
  );
}

export function CoursesPageClient() {
  return (
    <Suspense>
      <CoursesCatalog />
    </Suspense>
  );
}
