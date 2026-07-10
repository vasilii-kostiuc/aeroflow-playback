<?php

declare(strict_types=1);

namespace App\Playback\Application\IntegrationEvent;

use App\Playback\Domain\Entity\PlaybackJob;

/**
 * The dispatcher moved the job to Playing — its audio sequence is now on air.
 */
final readonly class AnnouncementPlaybackStarted extends AnnouncementPlaybackEvent
{
    public static function for(PlaybackJob $job): self
    {
        return new self('announcement_playback.started', $job);
    }
}
