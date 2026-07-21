<?php

declare(strict_types=1);

namespace App\Playback\Application;

use App\Playback\Domain\Repository\PlaybackJobRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * The next tick of a repeatable job is due (task 020): Scheduled -> Pending, then
 * advance the queue. Idempotent — a stale wake (the job already terminated because
 * the series was stopped, or is already playing) finds it not Scheduled and no-ops.
 */
#[AsMessageHandler]
final readonly class HandleWakeScheduledPlaybackJob
{
    public function __construct(
        private PlaybackJobRepositoryInterface $jobs,
        private PlaybackQueueDispatcher $dispatcher,
    ) {
    }

    public function __invoke(WakeScheduledPlaybackJob $command): void
    {
        $job = $this->jobs->findById(Uuid::fromString($command->jobId));
        if ($job === null || !$job->wake()) {
            return;
        }

        $this->jobs->save($job);
        $this->dispatcher->dispatchNext();
    }
}
