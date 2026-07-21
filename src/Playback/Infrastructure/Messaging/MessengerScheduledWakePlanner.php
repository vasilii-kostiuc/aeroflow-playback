<?php

declare(strict_types=1);

namespace App\Playback\Infrastructure\Messaging;

use App\Playback\Application\ScheduledWakePlannerInterface;
use App\Playback\Application\WakeScheduledPlaybackJob;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Plans the next-tick wake as a delayed WakeScheduledPlaybackJob on the scheduler
 * transport (see routing). No deferral is needed: the wake is idempotent and
 * self-guarding, so a wake for a job whose transaction rolled back finds it not
 * Scheduled and no-ops.
 */
final readonly class MessengerScheduledWakePlanner implements ScheduledWakePlannerInterface
{
    private const int MILLIS_PER_MINUTE = 60_000;

    public function __construct(private MessageBusInterface $messageBus)
    {
    }

    public function planWake(string $jobId, int $delayMinutes): void
    {
        $this->messageBus->dispatch(
            new WakeScheduledPlaybackJob($jobId),
            [new DelayStamp($delayMinutes * self::MILLIS_PER_MINUTE)],
        );
    }
}
