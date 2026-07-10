<?php

declare(strict_types=1);

namespace App\Playback\Application\IntegrationEvent;

use App\Playback\Domain\Entity\PlaybackJob;

/**
 * The playback request reached this service and a PlaybackJob now waits in the queue.
 */
final readonly class AnnouncementPlaybackQueued extends AnnouncementPlaybackEvent
{
    public static function for(PlaybackJob $job): self
    {
        return new self('announcement_playback.queued', $job);
    }
}
