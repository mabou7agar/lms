"use client";

import { useState } from "react";
import { FileMinus } from "lucide-react";
import { useI18n } from "@/lib/i18n/i18n-context";
import { useCreditNotes } from "@/lib/commerce/admin-hooks";
import { AdminGuard } from "@/components/commerce/admin-guard";
import { CreditNoteCard } from "@/components/commerce/credit-note-card";
import { QueryState } from "@/components/student/query-state";
import { Pagination } from "@/components/ui/pagination";
import { EmptyState } from "@/components/states/empty-state";

function CreditNotesView() {
  const { t } = useI18n();
  const [page, setPage] = useState(1);
  const query = useCreditNotes(page);

  return (
    <>
      <header className="mb-8">
        <h1 className="font-serif text-3xl font-semibold tracking-tight sm:text-4xl">
          {t("commerce.admin.creditNotes")}
        </h1>
        <p className="mt-2 text-muted-foreground">{t("commerce.admin.creditNotesSubtitle")}</p>
      </header>

      <QueryState
        query={query}
        isEmpty={(d) => d.data.length === 0}
        empty={<EmptyState icon={<FileMinus className="size-8" />} title={t("commerce.admin.emptyCreditNotes")} />}
      >
        {(data) => (
          <div className="space-y-4">
            <div className="stagger-in grid gap-4 sm:grid-cols-2">
              {data.data.map((note) => (
                <CreditNoteCard key={note.id} creditNote={note} />
              ))}
            </div>
            <Pagination page={data.meta.current_page} lastPage={data.meta.last_page} onPageChange={setPage} />
          </div>
        )}
      </QueryState>
    </>
  );
}

export default function CreditNotesPage() {
  return (
    <AdminGuard>
      <CreditNotesView />
    </AdminGuard>
  );
}
