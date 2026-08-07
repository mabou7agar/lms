<?php

namespace App\Domains\Authoring\Actions\Section;

use App\Domains\Authoring\Actions\Concerns\GuardsLockVersion;
use App\Domains\Authoring\Models\Section;
use App\Platform\Shared\Actions\BaseAction;

class UpdateSectionAction extends BaseAction
{
    use GuardsLockVersion;

    /**
     * @param  array<string, mixed>  $data
     * @param  int|null  $expectedVersion  optimistic-lock guard (C3); null skips the check for
     *                                     backward-compatible callers.
     */
    public function execute(Section $section, array $data, ?int $expectedVersion = null): Section
    {
        return $this->transaction(function () use ($section, $data, $expectedVersion): Section {
            // Short transaction: lock only the target row for the compare-and-write window.
            $locked = Section::query()->whereKey($section->getKey())->lockForUpdate()->firstOrFail();

            $this->assertLockVersion($locked, $expectedVersion);

            $locked->fill(array_filter([
                'title' => $data['title'] ?? null,
                'title_i18n' => $data['title_i18n'] ?? null,
                'summary' => $data['summary'] ?? null,
                'summary_i18n' => $data['summary_i18n'] ?? null,
            ], fn ($v) => $v !== null));

            $this->advanceLockVersion($locked);
            $locked->save();

            return $locked;
        });
    }
}
