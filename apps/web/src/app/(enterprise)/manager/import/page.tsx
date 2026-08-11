"use client";

import { useState } from "react";
import { FileCheck2, Upload, Play } from "lucide-react";
import { errorMessage } from "@/lib/api/errors";
import { useI18n } from "@/lib/i18n/i18n-context";
import type { ImportRow } from "@/lib/enterprise/manager-api";
import { useAnalyzeImport, useCommitImport } from "@/lib/enterprise/manager-hooks";
import { PageHeader } from "@/components/student/page-header";
import { SectionCard } from "@/components/org/section-card";
import { StatCard } from "@/components/student/stat-card";
import { FormAlert } from "@/components/auth/form-alert";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Badge } from "@/components/ui/badge";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";

const statusVariant: Record<ImportRow["status"], "success" | "destructive" | "warning"> = {
  valid: "success",
  error: "destructive",
  duplicate: "warning",
};

export default function ManagerImportPage() {
  const { t } = useI18n();
  const analyze = useAnalyzeImport();
  const commit = useCommitImport();

  const [file, setFile] = useState<File | null>(null);
  const [invite, setInvite] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const dryRun = analyze.data ?? null;
  const committed = commit.data ?? null;

  // Never commit silently on errors: require a clean dry-run with at least one valid row.
  const canCommit = dryRun !== null && dryRun.summary.errors === 0 && dryRun.summary.valid > 0;

  const onFileChange = (next: File | null) => {
    setFile(next);
    setError(null);
    analyze.reset();
    commit.reset();
  };

  const onAnalyze = () => {
    if (!file) {
      setError(t("manager.import.noFile"));
      return;
    }
    setError(null);
    commit.reset();
    analyze.mutate(file, { onError: (err) => setError(errorMessage(err, t("manager.error"))) });
  };

  const onCommit = () => {
    if (!file || !canCommit) return;
    setError(null);
    commit.mutate({ file, invite }, { onError: (err) => setError(errorMessage(err, t("manager.error"))) });
  };

  return (
    <div className="space-y-6">
      <PageHeader
        eyebrow={t("manager.import.eyebrow")}
        icon="Users"
        title={t("manager.import.title")}
        subtitle={t("manager.import.subtitle")}
      />

      {error ? <FormAlert>{error}</FormAlert> : null}

      <SectionCard title={t("manager.import.chooseFile")}>
        <div className="space-y-4">
          <div className="flex flex-wrap items-end gap-3">
            <div className="space-y-1">
              <label htmlFor="import-file" className="text-sm font-medium">
                {t("manager.import.chooseFile")}
              </label>
              <input
                id="import-file"
                type="file"
                accept=".csv,text/csv,text/plain"
                onChange={(e) => onFileChange(e.target.files?.[0] ?? null)}
                className="block text-sm file:me-3 file:rounded-md file:border file:border-input file:bg-muted file:px-3 file:py-1.5 file:text-sm"
              />
            </div>
            <Button onClick={onAnalyze} disabled={analyze.isPending || !file}>
              <Play className="size-4" aria-hidden />
              {analyze.isPending ? t("manager.import.analyzing") : t("manager.import.analyze")}
            </Button>
          </div>
        </div>
      </SectionCard>

      {dryRun ? (
        <SectionCard title={t("manager.import.dryRunTitle")}>
          <div className="space-y-4">
            <div className="grid gap-3 sm:grid-cols-4">
              <StatCard label={t("manager.import.total")} value={dryRun.summary.total} />
              <StatCard label={t("manager.import.valid")} value={dryRun.summary.valid} />
              <StatCard label={t("manager.import.errors")} value={dryRun.summary.errors} />
              <StatCard label={t("manager.import.duplicates")} value={dryRun.summary.duplicates} />
            </div>

            <div className="overflow-x-auto rounded-lg border">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>{t("manager.import.line")}</TableHead>
                    <TableHead>{t("manager.import.emailCol")}</TableHead>
                    <TableHead>{t("manager.import.roleCol")}</TableHead>
                    <TableHead>{t("manager.import.statusCol")}</TableHead>
                    <TableHead>{t("manager.import.errorsCol")}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {dryRun.rows.map((row) => (
                    <TableRow key={row.line}>
                      <TableCell className="tabular-nums">{row.line}</TableCell>
                      <TableCell className="font-medium">{row.email || "—"}</TableCell>
                      <TableCell>{row.role}</TableCell>
                      <TableCell>
                        <Badge variant={statusVariant[row.status]}>{t(`manager.import.status${cap(row.status)}`)}</Badge>
                      </TableCell>
                      <TableCell className="text-sm text-muted-foreground">
                        {row.errors.length > 0 ? row.errors.join(" ") : "—"}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>

            {!canCommit ? <FormAlert variant="error">{t("manager.import.commitBlocked")}</FormAlert> : null}

            <label className="flex items-center gap-2 text-sm">
              <Checkbox checked={invite} onChange={(e) => setInvite(e.target.checked)} />
              {t("manager.import.invite")}
            </label>

            <Button onClick={onCommit} disabled={!canCommit || commit.isPending}>
              <Upload className="size-4" aria-hidden />
              {commit.isPending ? t("manager.import.committing") : t("manager.import.commit")}
            </Button>
          </div>
        </SectionCard>
      ) : null}

      {committed ? (
        <SectionCard title={t("manager.import.complete")}>
          <div className="space-y-3">
            <FormAlert variant="success">
              <span className="inline-flex items-center gap-2">
                <FileCheck2 className="size-4" aria-hidden /> {t("manager.import.complete")}
              </span>
            </FormAlert>
            <div className="grid gap-3 sm:grid-cols-3">
              <StatCard label={t("manager.import.created")} value={committed.created} />
              <StatCard label={t("manager.import.invited")} value={committed.invited} />
              <StatCard label={t("manager.import.skipped")} value={committed.skipped} />
            </div>
          </div>
        </SectionCard>
      ) : null}
    </div>
  );
}

function cap(s: string): string {
  return s.charAt(0).toUpperCase() + s.slice(1);
}
