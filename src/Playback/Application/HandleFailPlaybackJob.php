<?php

declare(strict_types=1);

namespace App\Playback\Application;

use App\Playback\Domain\Repository\PlaybackJobRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Fails the job and advances the queue. No integration event is published for a
 * failure in this slice — core reception of a failure fact arrives together with
 * the agent slice, where real failures become possible.
 */
#[AsMessageHandler]
final readonly class HandleFailPlaybackJob
{
    public function __construct(
        private PlaybackJobRepositoryInterface $jobs,
        private PlaybackQueueDispatcher $dispatcher,
    ) {
    }

    public function __invoke(FailPlaybackJob $command): void
    {
        $job = $this->jobs->findById(Uuid::fromString($command->jobId));
        if ($job === null) {
            return;
        }

        if ($job->fail()) {
            $this->jobs->save($job);
        }

        $this->dispatcher->dispatchNext();
    }
}
