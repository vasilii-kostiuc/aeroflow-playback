<?php

declare(strict_types=1);

namespace App\Playback\Application;

/**
 * Internal delayed command: re-evaluate a Scheduled repeatable job when its next
 * tick is due (task 020). Delivered one interval after the job re-armed; the handler
 * flips it Scheduled -> Pending and lets the ordinary queue policy pick it up.
 */
final readonly class WakeScheduledPlaybackJob
{
    public function __construct(public string $jobId)
    {
    }
}
