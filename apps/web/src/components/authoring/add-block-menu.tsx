"use client";

import type { ReactNode } from "react";
import { Plus } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { Icon } from "@/components/ui/icon";
import { BLOCK_GROUP_ORDER, blocksByGroup } from "@/lib/authoring/block-registry";
import { useAuthoringI18n } from "@/lib/authoring/authoring-i18n";
import type { BlockKind } from "@/lib/authoring/types";

/** Grouped block picker. Unsupported kinds are shown but disabled with a "Soon" tag (never faked). */
export function AddBlockMenu({ onAdd, trigger }: { onAdd: (kind: BlockKind) => void; trigger?: ReactNode }) {
  const { t } = useAuthoringI18n();
  const groups = blocksByGroup();

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        {trigger ?? (
          <Button variant="ghost" size="sm">
            <Plus className="size-4" aria-hidden />
            {t("tree.addBlock")}
          </Button>
        )}
      </DropdownMenuTrigger>
      <DropdownMenuContent align="start" className="max-h-[70vh] w-64 overflow-y-auto">
        {BLOCK_GROUP_ORDER.map((group, gi) => (
          <div key={group}>
            {gi > 0 ? <DropdownMenuSeparator /> : null}
            <DropdownMenuLabel>{t(`group.${group}`)}</DropdownMenuLabel>
            {groups[group].map((def) => (
              <DropdownMenuItem
                key={def.kind}
                disabled={!def.supported}
                onSelect={() => {
                  if (def.supported) onAdd(def.kind);
                }}
                className="gap-2"
              >
                <Icon icon={def.icon} size="sm" />
                <span className="flex-1 truncate">{t(def.labelKey)}</span>
                {!def.supported ? (
                  <Badge variant="outline" className="text-[0.65rem]">
                    {t("block.comingSoon")}
                  </Badge>
                ) : null}
              </DropdownMenuItem>
            ))}
          </div>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
