<?php

declare(strict_types=1);

namespace App\Playback\Application\IntegrationEvent;

use App\Playback\Domain\Entity\PlaybackJob;

/**
 * The audio output reported the job failed (task 017). Without this fact the
 * dispatcher's queue screen would show a failed job as playing forever.
 */
final readonly class AnnouncementPlaybackFailed extends AnnouncementPlaybackEvent
{
    public static function for(PlaybackJob $job, ?string $reason = null): self
    {
        return new self('announcement_playback.failed', $job, $reason);
    }
}
