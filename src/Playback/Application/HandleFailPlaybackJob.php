<?php

declare(strict_types=1);

namespace App\Playback\Application;

use App\Playback\Application\IntegrationEvent\AnnouncementPlaybackFailed;
use App\Playback\Domain\Repository\PlaybackJobRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Fails the job, reports the fact to core and advances the queue. The failed
 * event (task 017) keeps the dispatcher's queue screen honest — without it a
 * failed job would look playing forever. Idempotent like completion: a
 * redelivered failure publishes nothing twice but still advances the queue.
 */
#[AsMessageHandler]
final readonly class HandleFailPlaybackJob
{
    public function __construct(
        private PlaybackJobRepositoryInterface $jobs,
        private PlaybackEventPublisherInterface $events,
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
            $this->events->publish(AnnouncementPlaybackFailed::for($job, $command->reason));
        }

        $this->dispatcher->dispatchNext();
    }
}
