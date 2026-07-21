<?php

declare(strict_types=1);

namespace App\Playback\Application;

use App\Playback\Application\IntegrationEvent\AnnouncementPlaybackCompleted;
use App\Playback\Domain\Enum\PlaybackJobStatus;
use App\Playback\Domain\Repository\PlaybackJobRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Ends the repeat series of an announcement (task 020).
 *
 * A Playing tick keeps sounding and terminates on its own completion; a job resting
 * between ticks (Scheduled/Pending) terminates now and is reported completed so the
 * queue read-model moves it to recent, and the queue is advanced in case the freed
 * output has other waiting jobs. Idempotent: an unknown announcement, a one-shot job,
 * an already-stopped series or a terminal job is a no-op — a redelivery sends nothing.
 */
#[AsMessageHandler]
final readonly class HandleStopAnnouncementRepeat
{
    public function __construct(
        private PlaybackJobRepositoryInterface $jobs,
        private PlaybackEventPublisherInterface $events,
        private PlaybackQueueDispatcher $dispatcher,
    ) {
    }

    public function __invoke(StopAnnouncementRepeat $command): void
    {
        $job = $this->jobs->findLatestByAnnouncementIdForUpdate(Uuid::fromString($command->announcementId));
        if ($job === null || !$job->cancelRepeat()) {
            return;
        }

        $this->jobs->save($job);

        // Terminated between ticks: report it and free the output. A Playing tick
        // instead finishes on its own and completes through the normal path.
        if ($job->getStatus() === PlaybackJobStatus::Completed) {
            $this->events->publish(AnnouncementPlaybackCompleted::for($job));
            $this->dispatcher->dispatchNext();
        }
    }
}
