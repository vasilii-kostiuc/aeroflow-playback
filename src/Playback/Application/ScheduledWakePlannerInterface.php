<?php

declare(strict_types=1);

namespace App\Playback\Application;

/**
 * Plans the delayed re-evaluation of a Scheduled repeatable job (task 020).
 *
 * A job resting between ticks is Scheduled and not a selection candidate, so nothing
 * would ever wake it. The application asks this planner to deliver a
 * WakeScheduledPlaybackJob one interval later; the infrastructure adapter uses a
 * delayed Messenger message. The timer lives entirely inside Playback — no cron.
 */
interface ScheduledWakePlannerInterface
{
    public function planWake(string $jobId, int $delayMinutes): void;
}
