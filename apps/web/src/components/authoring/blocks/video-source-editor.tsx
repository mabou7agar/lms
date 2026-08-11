"use client";

import { useState } from "react";
import { FormField } from "@/components/ui/form-field";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { isSafeUrl } from "@/lib/authoring/block-content";
import type { BlockFormValues } from "@/lib/authoring/content-blocks/registry";
import { useAuthoringI18n } from "@/lib/authoring/authoring-i18n";

/**
 * Provider selector for a video / audio block. The provider list is the REAL, supported set —
 * exactly the media source keys the backend accepts for a Video/Audio block (see
 * `BlockPayloadRules`): a Mux playback reference, a direct URL, or a stored (uploaded) object key.
 * No fake provider (YouTube/Vimeo/…) is offered because the backend has no such integration.
 *
 * Picking a provider shows ONLY that provider's field and clears the others, so a block is authored
 * against a single, unambiguous source (the reference the learner runtime resolves). The selection is
 * derived from the current values on mount (lazy initializer — no effect), keeping lint clean.
 */

type ProviderId = "mux" | "url" | "storage";

const PROVIDERS: ReadonlyArray<{ id: ProviderId; fieldKey: "mux_playback_id" | "url" | "s3_key"; labelKey: string; hintKey: string }> = [
  { id: "mux", fieldKey: "mux_playback_id", labelKey: "cblock.media.provider.mux", hintKey: "cblock.media.provider.muxHint" },
  { id: "url", fieldKey: "url", labelKey: "cblock.media.provider.url", hintKey: "cblock.media.provider.urlHint" },
  { id: "storage", fieldKey: "s3_key", labelKey: "cblock.media.provider.storage", hintKey: "cblock.media.provider.storageHint" },
];

const FIELD_KEYS = PROVIDERS.map((p) => p.fieldKey);

function providerFrom(values: BlockFormValues): ProviderId {
  const match = PROVIDERS.find((p) => (values[p.fieldKey]?.en ?? "").trim() !== "");
  return match?.id ?? "mux";
}

export function VideoSourceEditor({
  values,
  onChange,
}: {
  values: BlockFormValues;
  onChange: (next: BlockFormValues) => void;
}) {
  const { t } = useAuthoringI18n();
  const [provider, setProvider] = useState<ProviderId>(() => providerFrom(values));

  const active = PROVIDERS.find((p) => p.id === provider) ?? PROVIDERS[0];
  const value = values[active.fieldKey] ?? { en: "", ar: "" };

  // A shared reference is stored in every locale; mirror the single input into both en and ar.
  const setValue = (text: string) => onChange({ ...values, [active.fieldKey]: { en: text, ar: text } });

  const changeProvider = (next: ProviderId) => {
    setProvider(next);
    // Only one source is ever persisted: clear every other provider's field on switch.
    const keepKey = PROVIDERS.find((p) => p.id === next)?.fieldKey;
    const cleared: BlockFormValues = { ...values };
    for (const key of FIELD_KEYS) {
      if (key !== keepKey) cleared[key] = { en: "", ar: "" };
    }
    onChange(cleared);
  };

  const isUrl = active.id === "url";
  const unsafeUrl = isUrl && value.en.trim() !== "" && !isSafeUrl(value.en);

  return (
    <div className="space-y-5">
      <FormField label={t("cblock.media.provider")} hint={t("cblock.media.providerHint")}>
        <Select value={provider} onValueChange={(v) => changeProvider(v as ProviderId)}>
          <SelectTrigger aria-label={t("cblock.media.provider")}>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {PROVIDERS.map((p) => (
              <SelectItem key={p.id} value={p.id}>
                {t(p.labelKey)}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </FormField>

      <FormField
        label={t(active.labelKey)}
        hint={t(active.hintKey)}
        error={unsafeUrl ? t("link.unsafe") : undefined}
      >
        <Input
          type={isUrl ? "url" : "text"}
          inputMode={isUrl ? "url" : undefined}
          dir="ltr"
          value={value.en}
          placeholder={isUrl ? "https://" : undefined}
          onChange={(e) => setValue(e.target.value)}
        />
      </FormField>
    </div>
  );
}
