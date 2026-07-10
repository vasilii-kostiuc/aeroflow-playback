<?php

declare(strict_types=1);

namespace App\Playback\Application\IntegrationEvent;

use App\Playback\Domain\Entity\PlaybackJob;

/**
 * The audio output reported the job played to the end.
 */
final readonly class AnnouncementPlaybackCompleted extends AnnouncementPlaybackEvent
{
    public static function for(PlaybackJob $job): self
    {
        return new self('announcement_playback.completed', $job);
    }
}
