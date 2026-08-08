"use client";

import type { ReactNode } from "react";
import { Plus } from "lucide-react";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { Icon } from "@/components/ui/icon";
import { blockDef } from "@/lib/authoring/block-registry";
import { SUPPORTED_BLOCK_KINDS } from "@/lib/authoring/content-blocks/registry";
import { useAuthoringI18n } from "@/lib/authoring/authoring-i18n";
import type { BlockKind } from "@/lib/authoring/types";

/**
 * Add-content-block menu. Unlike the curriculum tree's picker (which shows every design-complete kind
 * with unsupported ones disabled), the nested-blocks layer only PERSISTS runtime-supported kinds, so
 * this menu lists ONLY those — there is no disabled/"coming soon" path to accidentally choose.
 */
export function AddContentBlockMenu({ onAdd, trigger }: { onAdd: (kind: BlockKind) => void; trigger?: ReactNode }) {
  const { t } = useAuthoringI18n();

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        {trigger ?? (
          <Button variant="outline" size="sm">
            <Plus className="size-4" aria-hidden />
            {t("cblock.add")}
          </Button>
        )}
      </DropdownMenuTrigger>
      <DropdownMenuContent align="start" className="max-h-[70vh] w-60 overflow-y-auto">
        {SUPPORTED_BLOCK_KINDS.map((kind) => {
          const def = blockDef(kind);
          return (
            <DropdownMenuItem key={kind} onSelect={() => onAdd(kind)} className="gap-2">
              <Icon icon={def.icon} size="sm" />
              <span className="flex-1 truncate">{t(def.labelKey)}</span>
            </DropdownMenuItem>
          );
        })}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
