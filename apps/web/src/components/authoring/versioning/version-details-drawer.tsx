"use client";

import type { ReactNode } from "react";
import {
  Badge,
  Drawer,
  DrawerContent,
  DrawerDescription,
  DrawerHeader,
  DrawerTitle,
} from "@/components/ui";
import { useVersioningI18n } from "@/lib/authoring/versioning-i18n";
import { formatVersionDate, shortChecksum } from "@/lib/authoring/versioning-format";
import type { ContentVersion } from "@/lib/authoring/versioning-api";

/** Read-only details of a single version (metadata + content summary — never the raw snapshot). */
export function VersionDetailsDrawer({
  version,
  open,
  onOpenChange,
}: {
  version: ContentVersion | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const { t } = useVersioningI18n();

  return (
    <Drawer open={open} onOpenChange={onOpenChange}>
      <DrawerContent className="max-h-[85vh]">
        {version ? (
          <>
            <DrawerHeader>
              <DrawerTitle>{t("versions.number", { n: version.version_number })}</DrawerTitle>
              <DrawerDescription>{version.label ?? t("versions.details.title")}</DrawerDescription>
            </DrawerHeader>

            <dl className="grid gap-3 p-4 text-sm sm:grid-cols-2">
              <Field label={t("versions.details.reason")}>
                <Badge variant="outline">{t(`versions.reason.${version.reason}`)}</Badge>
              </Field>
              <Field label={t("versions.details.created")}>{formatVersionDate(version.created_at)}</Field>
              <Field label={t("versions.details.creator")}>
                {version.created_by !== null ? t("versions.by", { id: version.created_by }) : t("versions.bySystem")}
              </Field>
              <Field label={t("versions.details.schema")}>{version.schema_version}</Field>
              <Field label={t("versions.details.checksum")}>
                <code className="font-mono text-xs">{shortChecksum(version.checksum)}</code>
              </Field>
              <Field label={t("versions.details.source")}>
                {version.source
                  ? version.source.from_other_course
                    ? t("versions.sourceForked", { n: version.source.version_number })
                    : t("versions.source", { n: version.source.version_number })
                  : t("versions.noSource")}
              </Field>
              <div className="sm:col-span-2">
                <Field label={t("versions.details.summary")}>
                  {t("versions.counts", {
                    sections: version.summary.sections,
                    lessons: version.summary.lessons,
                    blocks: version.summary.blocks,
                    modules: version.summary.modules,
                  })}
                </Field>
              </div>
            </dl>
          </>
        ) : null}
      </DrawerContent>
    </Drawer>
  );
}

function Field({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="flex flex-col gap-1">
      <dt className="text-xs font-medium text-muted-foreground">{label}</dt>
      <dd className="text-foreground">{children}</dd>
    </div>
  );
}
