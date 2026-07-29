<?php

namespace App\Platform\Shared\Media\Enums;

/**
 * P2/W04 - Lifecycle of a media asset from creation through provider processing to readiness.
 * Transitions are guarded (see canTransitionTo) so an out-of-order webhook can never move an
 * asset backwards or resurrect a deleted one.
 */
enum MediaStatus: string
{
    case Created = 'created';
    case WaitingForUpload = 'waiting_for_upload';
    case Uploading = 'uploading';
    case Uploaded = 'uploaded';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Deleted = 'deleted';

    /** A signed playback URL may only be issued once the provider confirms readiness. */
    public function isPlayable(): bool
    {
        return $this === self::Ready;
    }

    /** No further provider-driven transitions are expected. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Deleted, self::Cancelled], true);
    }

    /** A failed asset may be retried by an authorised instructor. */
    public function isRetryable(): bool
    {
        return $this === self::Failed;
    }

    /**
     * Forward-only lifecycle guard. Retry is the only backward edge (failed -> waiting_for_upload
     * / processing) and is taken explicitly, never by a webhook.
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Created => [self::WaitingForUpload, self::Cancelled, self::Failed],
            self::WaitingForUpload => [self::Uploading, self::Uploaded, self::Cancelled, self::Failed],
            self::Uploading => [self::Uploaded, self::Failed, self::Cancelled],
            self::Uploaded => [self::Processing, self::Ready, self::Failed],
            self::Processing => [self::Ready, self::Failed],
            self::Ready => [self::Deleted],
            self::Failed => [self::WaitingForUpload, self::Processing, self::Deleted],
            self::Cancelled => [self::Deleted],
            self::Deleted => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return $next === $this || in_array($next, $this->allowedNext(), true);
    }
}
