<?php

declare(strict_types=1);

namespace App\Playback\Application\IntegrationEvent;

use App\Playback\Domain\Entity\PlaybackJob;

final readonly class AnnouncementPlaybackInterrupted extends AnnouncementPlaybackEvent
{
    public static function for(PlaybackJob $job): self
    {
        return new self('announcement_playback.interrupted', $job);
    }
}
