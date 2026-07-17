<?php

declare(strict_types=1);

namespace App\Playback\Application\IntegrationEvent;

use App\Playback\Domain\Entity\PlaybackJob;

/**
 * A waiting job was dropped from the queue on the announcement's cancellation
 * (task 018). Core turns this receipt into the "Отменено" row of the queue screen.
 */
final readonly class AnnouncementPlaybackCancelled extends AnnouncementPlaybackEvent
{
    public static function for(PlaybackJob $job): self
    {
        return new self('announcement_playback.cancelled', $job);
    }
}
