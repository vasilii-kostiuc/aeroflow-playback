<?php

declare(strict_types=1);

namespace App\Playback\Application\IntegrationEvent;

use App\Playback\Domain\Entity\PlaybackJob;

/**
 * Published when the queue dispatcher preempts this job in favour of a higher,
 * at-or-above-PREEMPTIVE_PRIORITY one (task 025), immediately before the job is
 * requeued to Pending. Distinct from AnnouncementPlaybackInterrupted so Core and the
 * dispatcher queue screen can tell "the operator stopped it" from "an emergency
 * preempted it".
 */
final readonly class AnnouncementPlaybackPreempted extends AnnouncementPlaybackEvent
{
    public static function for(PlaybackJob $job): self
    {
        return new self('announcement_playback.preempted', $job);
    }
}
