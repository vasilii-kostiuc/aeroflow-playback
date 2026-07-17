<?php

declare(strict_types=1);

namespace App\Playback\Application;

use App\Playback\Application\IntegrationEvent\AnnouncementPlaybackCancelled;
use App\Playback\Domain\Enum\PlaybackJobStatus;
use App\Playback\Domain\Repository\PlaybackJobRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Drops the still-pending job of a cancelled announcement (task 018).
 *
 * Only a Pending job cancels. A job that already plays or finished is a late
 * cancel: the command is acknowledged without touching the job — stopping the
 * current sound is a separate future command, and the queue screen keeps showing
 * what actually happened. Idempotent: a redelivered cancel finds the job already
 * Cancelled and publishes nothing. The job is loaded with a write lock so the
 * cancel cannot race the queue dispatcher starting the same job.
 */
#[AsMessageHandler]
final readonly class HandleCancelAnnouncementPlayback
{
    public function __construct(
        private PlaybackJobRepositoryInterface $jobs,
        private PlaybackEventPublisherInterface $events,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CancelAnnouncementPlayback $command): void
    {
        $job = $this->jobs->findLatestByAnnouncementIdForUpdate(
            Uuid::fromString($command->announcementId),
        );

        if ($job === null) {
            $this->logger->warning('Cancel for unknown announcement acknowledged.', [
                'announcementId' => $command->announcementId,
                'messageId' => $command->messageId,
            ]);

            return;
        }

        $status = $job->getStatus();
        if ($status !== PlaybackJobStatus::Pending && $status !== PlaybackJobStatus::Cancelled) {
            $this->logger->info('Late cancel acknowledged without effect.', [
                'jobId' => $job->getId()->toRfc4122(),
                'status' => $status->value,
            ]);

            return;
        }

        if ($job->cancel()) {
            $this->jobs->save($job);
            $this->events->publish(AnnouncementPlaybackCancelled::for($job));
        }
    }
}
