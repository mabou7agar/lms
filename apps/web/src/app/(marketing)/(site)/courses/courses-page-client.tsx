"use client";

import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { Suspense, useEffect, useMemo, useState } from "react";
import { Search, SlidersHorizontal, X, ArrowUpDown, Check } from "lucide-react";
import { useI18n } from "@/lib/i18n/i18n-context";
import { useCategories, useCourses } from "@/lib/catalog/hooks";
import { flattenCategories } from "@/lib/catalog/api";
import { cn } from "@/lib/utils";
import { QueryState } from "@/components/student/query-state";
import { CourseCard } from "@/components/catalog/course-card";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Pagination } from "@/components/ui/pagination";
import { EmptyState } from "@/components/states/empty-state";
import { Drawer, DrawerContent, DrawerTitle, DrawerDescription } from "@/components/ui/drawer";

const selectClass =
  "h-10 w-full rounded-lg border border-input bg-background px-3 text-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring";

type Sort = "relevance" | "title-asc" | "title-desc" | "featured";

function CourseCardSkeleton() {
  return (
    <div className="overflow-hidden rounded-2xl border border-border bg-card">
      <div className="aspect-video w-full animate-pulse bg-muted" />
      <div className="space-y-3 p-5">
        <div className="h-4 w-16 animate-pulse rounded bg-muted" />
        <div className="h-5 w-4/5 animate-pulse rounded bg-muted" />
        <div className="h-4 w-full animate-pulse rounded bg-muted" />
      </div>
    </div>
  );
}

function CoursesCatalog() {
  const { t, locale } = useI18n();
  const params = useSearchParams();
  const router = useRouter();
  const pathname = usePathname();
  const categoriesQuery = useCategories();

  const [q, setQ] = useState(params.get("q") ?? "");
  const [debouncedQ, setDebouncedQ] = useState(params.get("q") ?? "");
  const [category, setCategory] = useState(params.get("category") ?? "");
  const [featured, setFeatured] = useState(params.get("featured") === "1");
  const [level, setLevel] = useState(params.get("level") ?? "");
  const [language, setLanguage] = useState(params.get("language") ?? "");
  const [sort, setSort] = useState<Sort>((params.get("sort") as Sort) ?? "relevance");
  const [page, setPage] = useState(1);
  const [drawer, setDrawer] = useState(false);

  useEffect(() => {
    const id = setTimeout(() => setDebouncedQ(q), 300);
    return () => clearTimeout(id);
  }, [q]);

  // Reset to page 1 when search/filters change (adjust-state-while-rendering, guarded to run once).
  const filterKey = `${debouncedQ}|${category}|${featured}`;
  const [prevFilterKey, setPrevFilterKey] = useState(filterKey);
  if (filterKey !== prevFilterKey) {
    setPrevFilterKey(filterKey);
    setPage(1);
  }

  // Keep the URL in sync so filtered views are shareable/bookmarkable (real query params only).
  useEffect(() => {
    const sp = new URLSearchParams();
    if (debouncedQ) sp.set("q", debouncedQ);
    if (category) sp.set("category", category);
    if (featured) sp.set("featured", "1");
    if (level) sp.set("level", level);
    if (language) sp.set("language", language);
    if (sort !== "relevance") sp.set("sort", sort);
    const qs = sp.toString();
    router.replace(qs ? `${pathname}?${qs}` : pathname, { scroll: false });
  }, [debouncedQ, category, featured, level, language, sort, pathname, router]);

  const query = useCourses({ q: debouncedQ || undefined, category: category || undefined, featured, page, per_page: 12 });

  const items = useMemo(() => query.data?.data ?? [], [query.data]);
  const levels = useMemo(() => Array.from(new Set(items.map((c) => c.level).filter(Boolean))) as string[], [items]);
  const languages = useMemo(() => Array.from(new Set(items.map((c) => c.language).filter(Boolean))) as string[], [items]);

  const refined = useMemo(() => {
    const r = items.filter((c) => (!level || c.level === level) && (!language || c.language === language));
    const s = [...r];
    if (sort === "title-asc") s.sort((a, b) => a.title.localeCompare(b.title, locale));
    else if (sort === "title-desc") s.sort((a, b) => b.title.localeCompare(a.title, locale));
    else if (sort === "featured") s.sort((a, b) => Number(Boolean(b.is_featured)) - Number(Boolean(a.is_featured)));
    return s;
  }, [items, level, language, sort, locale]);

  const categoryOptions = flattenCategories(categoriesQuery.data ?? []);
  const categoryName = categoryOptions.find((c) => c.id === category)?.name;

  const activeChips: { key: string; label: string; onClear: () => void }[] = [];
  if (debouncedQ) activeChips.push({ key: "q", label: `"${debouncedQ}"`, onClear: () => { setQ(""); setDebouncedQ(""); } });
  if (categoryName) activeChips.push({ key: "cat", label: categoryName, onClear: () => setCategory("") });
  if (level) activeChips.push({ key: "lvl", label: level, onClear: () => setLevel("") });
  if (language) activeChips.push({ key: "lang", label: language, onClear: () => setLanguage("") });
  if (featured) activeChips.push({ key: "feat", label: t("catalog.courses.featuredOnly"), onClear: () => setFeatured(false) });

  const clearAll = () => {
    setQ(""); setDebouncedQ(""); setCategory(""); setFeatured(false); setLevel(""); setLanguage(""); setSort("relevance"); setPage(1);
  };
  const count = refined.length;

  const filterControls = (
    <>
      <label className="block">
        <span className="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-muted-foreground">{t("catalog.courses.allCategories")}</span>
        <select className={selectClass} value={category} onChange={(e) => setCategory(e.target.value)} aria-label={t("catalog.courses.allCategories")}>
          <option value="">{t("catalog.courses.allCategories")}</option>
          {categoryOptions.map((c) => <option key={c.id} value={c.id}>{`${"— ".repeat(c.depth)}${c.name}`}</option>)}
        </select>
      </label>
      <label className="block">
        <span className="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-muted-foreground">{t("catalog.courses.level")}</span>
        <select className={selectClass} value={level} onChange={(e) => setLevel(e.target.value)} aria-label={t("catalog.courses.level")}>
          <option value="">{t("catalog.courses.allLevels")}</option>
          {levels.map((l) => <option key={l} value={l}>{l}</option>)}
        </select>
      </label>
      <label className="block">
        <span className="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-muted-foreground">{t("catalog.courses.language")}</span>
        <select className={selectClass} value={language} onChange={(e) => setLanguage(e.target.value)} aria-label={t("catalog.courses.language")}>
          <option value="">{t("catalog.courses.allLanguages")}</option>
          {languages.map((l) => <option key={l} value={l}>{l}</option>)}
        </select>
      </label>
      <button
        type="button"
        onClick={() => setFeatured((v) => !v)}
        aria-pressed={featured}
        className={cn(
          "flex w-full items-center justify-between rounded-lg border px-3 py-2.5 text-sm font-medium transition-colors",
          featured ? "border-copper/40 bg-copper/10 text-copper" : "border-input text-muted-foreground hover:bg-muted/60",
        )}
      >
        {t("catalog.courses.featuredOnly")}
        <span className={cn("grid size-4 place-items-center rounded border", featured ? "border-copper bg-copper text-copper-foreground" : "border-input")}>
          {featured ? <Check className="size-3" aria-hidden /> : null}
        </span>
      </button>
    </>
  );

  return (
    <div>
      {/* Premium catalog hero */}
      <section className="relative overflow-hidden border-b border-border/60">
        <div className="pointer-events-none absolute inset-0 -z-10 bg-[radial-gradient(80%_80%_at_50%_-10%,oklch(0.42_0.05_185/0.10)_0%,transparent_55%)]" aria-hidden />
        <div className="pointer-events-none absolute inset-0 -z-10 opacity-40 [background-image:radial-gradient(var(--border)_1px,transparent_1px)] [background-size:22px_22px] [mask-image:radial-gradient(70%_60%_at_50%_0%,#000_0%,transparent_75%)]" aria-hidden />
        <div className="mx-auto max-w-6xl px-4 py-14 text-center sm:py-16">
          <div className="mb-4 flex justify-center">
            <span className="inline-flex items-center gap-3 text-xs font-semibold uppercase tracking-[0.22em] text-copper">
              <span className="h-px w-8 bg-copper/50" aria-hidden />{t("catalog.nav.courses")}
              <span className="h-px w-8 bg-copper/50" aria-hidden />
            </span>
          </div>
          <h1 className="text-h1 mx-auto max-w-2xl font-serif">{t("catalog.courses.title")}</h1>
          <p className="mx-auto mt-3 max-w-xl text-muted-foreground">{t("catalog.courses.subtitle")}</p>
          <div className="relative mx-auto mt-7 max-w-xl">
            <Search className="pointer-events-none absolute inset-y-0 start-4 my-auto size-5 text-muted-foreground" aria-hidden />
            <Input
              className="ps-12 text-base shadow-sm"
              style={{ height: "3.25rem" }}
              placeholder={t("catalog.courses.search")}
              value={q}
              onChange={(e) => setQ(e.target.value)}
              aria-label={t("catalog.courses.search")}
            />
          </div>
        </div>
      </section>

      <div className="mx-auto max-w-6xl px-4 py-8">
        {/* Toolbar */}
        <div className="mb-5 flex flex-wrap items-center gap-3">
          <p className="text-sm text-muted-foreground" role="status" aria-live="polite">
            <span className="font-serif text-xl font-bold text-foreground">{count}</span>{" "}
            {count === 1 ? t("catalog.courses.resultsOne") : t("catalog.courses.results")}
          </p>
          <div className="ms-auto flex items-center gap-2">
            <label className="flex items-center gap-2 text-sm">
              <ArrowUpDown className="size-4 text-muted-foreground" aria-hidden />
              <span className="sr-only sm:not-sr-only sm:text-muted-foreground">{t("catalog.courses.sort")}</span>
              <select className={cn(selectClass, "h-9 w-auto")} value={sort} onChange={(e) => setSort(e.target.value as Sort)} aria-label={t("catalog.courses.sort")}>
                <option value="relevance">{t("catalog.courses.sortRelevance")}</option>
                <option value="featured">{t("catalog.courses.sortFeatured")}</option>
                <option value="title-asc">{t("catalog.courses.sortTitleAsc")}</option>
                <option value="title-desc">{t("catalog.courses.sortTitleDesc")}</option>
              </select>
            </label>
            <Button type="button" variant="outline" size="sm" className="lg:hidden" onClick={() => setDrawer(true)}>
              <SlidersHorizontal className="size-4" aria-hidden /> {t("catalog.courses.filters")}
            </Button>
          </div>
        </div>

        {/* Active chips */}
        {activeChips.length > 0 ? (
          <div className="mb-6 flex flex-wrap items-center gap-2">
            {activeChips.map((c) => (
              <button
                key={c.key}
                type="button"
                onClick={c.onClear}
                className="inline-flex items-center gap-1.5 rounded-full border border-border bg-card px-3 py-1 text-xs font-medium text-foreground transition-colors hover:border-destructive/40 hover:text-destructive"
              >
                {c.label}
                <X className="size-3" aria-hidden />
              </button>
            ))}
            <button type="button" onClick={clearAll} className="text-xs font-semibold text-copper hover:underline">
              {t("catalog.courses.clearAll")}
            </button>
          </div>
        ) : null}

        <div className="grid gap-8 lg:grid-cols-[15rem_1fr]">
          {/* Desktop filter rail */}
          <aside className="hidden lg:block">
            <div className="sticky top-20 space-y-4 rounded-2xl border border-border bg-card p-5">
              <p className="font-serif text-base font-semibold">{t("catalog.courses.filters")}</p>
              {filterControls}
            </div>
          </aside>

          {/* Results */}
          <div>
            <QueryState
              query={query}
              loading={
                <div className="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
                  {Array.from({ length: 6 }).map((_, i) => <CourseCardSkeleton key={i} />)}
                </div>
              }
              isEmpty={() => refined.length === 0}
              empty={<EmptyState title={t("catalog.courses.empty")} description={t("catalog.courses.emptyHint")} />}
            >
              {(data) => (
                <div className="space-y-8">
                  <div className="stagger-in grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
                    {refined.map((c) => <CourseCard key={c.id} course={c} />)}
                  </div>
                  <Pagination page={data.meta.current_page} lastPage={data.meta.last_page} onPageChange={setPage} />
                </div>
              )}
            </QueryState>
          </div>
        </div>
      </div>

      {/* Mobile filter drawer */}
      <Drawer open={drawer} onOpenChange={setDrawer}>
        <DrawerContent className="p-6">
          <DrawerTitle className="font-serif text-lg font-semibold">{t("catalog.courses.filters")}</DrawerTitle>
          <DrawerDescription className="sr-only">{t("catalog.courses.filters")}</DrawerDescription>
          <div className="mt-4 space-y-4">
            {filterControls}
          </div>
          <div className="mt-6 flex gap-2">
            <Button variant="outline" className="flex-1" onClick={clearAll}>{t("catalog.courses.clearAll")}</Button>
            <Button className="flex-1" onClick={() => setDrawer(false)}>{t("catalog.courses.apply")}</Button>
          </div>
        </DrawerContent>
      </Drawer>
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
