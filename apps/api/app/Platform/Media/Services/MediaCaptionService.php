<?php

namespace App\Platform\Media\Services;

use App\Platform\Media\Enums\CaptionStatus;
use App\Platform\Media\Exceptions\MediaValidationException;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Media\Models\MediaCaption;
use App\Platform\Shared\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * P2/W04 - Manages caption/subtitle track METADATA (an uploaded VTT/SRT reference) for an asset.
 * The platform never transcribes. Language tags are validated against a BCP-47 shape and one track
 * per language is enforced (unique index). Storage of the actual caption bytes is a client/provider
 * concern; this records where they live.
 */
class MediaCaptionService
{
    /** Well-formed BCP-47: primary subtag + optional script/region/variant/extension subtags. */
    private const BCP47 = '/^[a-zA-Z]{2,3}(-[a-zA-Z]{4})?(-([a-zA-Z]{2}|\d{3}))?(-([a-zA-Z0-9]{5,8}|\d[a-zA-Z0-9]{3}))*$/';

    public function __construct(private readonly AuditLogger $audit) {}

    public function addCaption(
        MediaAsset $asset,
        int $actorId,
        string $language,
        string $label,
        string $format = 'vtt',
        ?string $storageKey = null,
        ?string $providerRef = null,
    ): MediaCaption {
        $this->assertLanguage($language);
        $this->assertFormat($format);

        return DB::transaction(function () use ($asset, $actorId, $language, $label, $format, $storageKey, $providerRef): MediaCaption {
            $exists = MediaCaption::query()
                ->where('media_asset_id', $asset->id)
                ->where('language', $language)
                ->exists();

            if ($exists) {
                throw new MediaValidationException(
                    "A {$language} caption already exists for this asset.",
                    ['field' => 'language'],
                );
            }

            $caption = new MediaCaption;
            $caption->forceFill([
                'media_asset_id' => $asset->id,
                'language' => $language,
                'label' => $label,
                'format' => $format,
                'storage_key' => $storageKey,
                'provider_ref' => $providerRef,
                'status' => $storageKey !== null || $providerRef !== null
                    ? CaptionStatus::Ready->value
                    : CaptionStatus::Pending->value,
                'created_by' => $actorId,
            ])->save();

            $this->audit->log('media.caption.added', $asset, ['language' => $language], $actorId);

            return $caption;
        });
    }

    /** @return Collection<int, MediaCaption> */
    public function listCaptions(MediaAsset $asset): Collection
    {
        return MediaCaption::query()
            ->where('media_asset_id', $asset->id)
            ->orderBy('language')
            ->get();
    }

    public function removeCaption(MediaAsset $asset, MediaCaption $caption, int $actorId): void
    {
        if ($caption->media_asset_id !== $asset->id) {
            throw new MediaValidationException('Caption does not belong to this asset.');
        }

        $caption->delete();
        $this->audit->log('media.caption.removed', $asset, ['language' => $caption->language], $actorId);
    }

    private function assertLanguage(string $language): void
    {
        if (preg_match(self::BCP47, $language) !== 1) {
            throw new MediaValidationException(
                "'{$language}' is not a valid BCP-47 language tag.",
                ['field' => 'language'],
            );
        }
    }

    private function assertFormat(string $format): void
    {
        if (! in_array($format, ['vtt', 'srt'], true)) {
            throw new MediaValidationException(
                'Caption format must be vtt or srt.',
                ['field' => 'format'],
            );
        }
    }
}
