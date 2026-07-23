<?php

declare(strict_types=1);

namespace App\Playback\Application;

use App\Playback\Application\IntegrationEvent\AnnouncementPlaybackCompleted;
use App\Playback\Application\IntegrationEvent\AnnouncementPlaybackRescheduled;
use App\Playback\Domain\Enum\PlaybackCompletionResult;
use App\Playback\Domain\Repository\PlaybackJobRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Completes the job and advances the queue.
 *
 * A one-shot job terminates and is reported completed. A repeatable job (task 020)
 * re-arms instead: it goes Scheduled, a delayed wake is planned for the next tick and
 * the re-arm is reported as Rescheduled rather than Completed (task 023) — the series
 * is not over, so core must not file the row as finished, but it must stop showing it
 * as sounding. Either way the output is freed, so the queue is advanced. Idempotent: a
 * redelivered completion (NoOp) neither re-publishes nor re-arms, but still advances
 * the queue so no Pending job waits behind an empty output.
 */
#[AsMessageHandler]
final readonly class HandleCompletePlaybackJob
{
    public function __construct(
        private PlaybackJobRepositoryInterface $jobs,
        private PlaybackEventPublisherInterface $events,
        private PlaybackQueueDispatcher $dispatcher,
        private ScheduledWakePlannerInterface $wakePlanner,
    ) {
    }

    public function __invoke(CompletePlaybackJob $command): void
    {
        $job = $this->jobs->findById(Uuid::fromString($command->jobId));
        if ($job === null) {
            return;
        }

        $result = $job->complete();
        if ($result !== PlaybackCompletionResult::NoOp) {
            $this->jobs->save($job);
        }

        match ($result) {
            PlaybackCompletionResult::Completed => $this->events->publish(AnnouncementPlaybackCompleted::for($job)),
            PlaybackCompletionResult::Rearmed => [
                $this->wakePlanner->planWake(
                    $job->getId()->toRfc4122(),
                    (int) $job->repeatEveryMinutes(),
                ),
                $this->events->publish(AnnouncementPlaybackRescheduled::for($job)),
            ],
            PlaybackCompletionResult::NoOp => null,
        };

        $this->dispatcher->dispatchNext();
    }
}
