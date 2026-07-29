<?php

namespace App\Platform\Shared\Media\Enums;

/**
 * P2/W04 - The kind of media an asset carries. Drives which provider ingests it (video/audio -> a
 * streaming provider such as Mux; everything else -> object storage) and which validation rules
 * apply at upload time.
 */
enum MediaType: string
{
    case Video = 'video';
    case Audio = 'audio';
    case Document = 'document';
    case File = 'file';
    case Image = 'image';
    case External = 'external';

    /** Streamed types are transcoded by a streaming provider and played via a signed manifest. */
    public function isStreamed(): bool
    {
        return in_array($this, [self::Video, self::Audio], true);
    }

    /** Non-streamed, non-external types live in object storage and are served via signed URLs. */
    public function isStored(): bool
    {
        return in_array($this, [self::Document, self::File, self::Image], true);
    }
}
