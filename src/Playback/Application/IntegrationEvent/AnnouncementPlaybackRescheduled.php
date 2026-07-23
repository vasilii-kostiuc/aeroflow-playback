<?php

declare(strict_types=1);

namespace App\Playback\Application\IntegrationEvent;

use App\Playback\Domain\Entity\PlaybackJob;
use DateTimeInterface;

/**
 * A repeatable job played a tick to the end and re-armed for the next one
 * (Playing -> Scheduled, task 023).
 *
 * Without this fact core sees a repeating job as one endless `started`: the row
 * stays "playing" for the whole series and competes for the single playing slot of
 * the queue read model with whatever genuinely sounds. `nextAt` is the moment the
 * next tick is due, so the dispatcher sees the cadence instead of a stuck row.
 */
final readonly class AnnouncementPlaybackRescheduled extends AnnouncementPlaybackEvent
{
    public static function for(PlaybackJob $job): self
    {
        return new self(
            'announcement_playback.rescheduled',
            $job,
            nextAt: $job->getScheduledAt()?->format(DateTimeInterface::ATOM),
        );
    }
}
