<?php

declare(strict_types=1);

namespace App\Playback\Application;

use App\Playback\Application\IntegrationEvent\AnnouncementPlaybackCompleted;
use App\Playback\Domain\Repository\PlaybackJobRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Completes the job and advances the queue.
 *
 * Idempotent: re-completing an already Completed job neither publishes the event
 * again nor fails — but the queue is still advanced, so a redelivered completion
 * cannot leave a Pending job waiting behind an empty output.
 */
#[AsMessageHandler]
final readonly class HandleCompletePlaybackJob
{
    public function __construct(
        private PlaybackJobRepositoryInterface $jobs,
        private PlaybackEventPublisherInterface $events,
        private PlaybackQueueDispatcher $dispatcher,
    ) {
    }

    public function __invoke(CompletePlaybackJob $command): void
    {
        $job = $this->jobs->findById(Uuid::fromString($command->jobId));
        if ($job === null) {
            return;
        }

        if ($job->complete()) {
            $this->jobs->save($job);
            $this->events->publish(AnnouncementPlaybackCompleted::for($job));
        }

        $this->dispatcher->dispatchNext();
    }
}
