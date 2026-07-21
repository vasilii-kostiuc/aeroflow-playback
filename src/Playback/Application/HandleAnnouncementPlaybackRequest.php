<?php

declare(strict_types=1);

namespace App\Playback\Application;

use App\Playback\Application\IntegrationEvent\AnnouncementPlaybackQueued;
use App\Playback\Domain\Entity\PlaybackJob;
use App\Playback\Domain\Repository\PlaybackJobRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Turns an inbound RequestAnnouncementPlayback into a PlaybackJob.
 *
 * Idempotent by messageId: a redelivered command returns the already-achieved result
 * (the existing job) instead of creating a duplicate — without re-publishing Queued
 * or re-dispatching. The unique constraint on source_message_id is the last-resort
 * guard against a concurrent redelivery.
 */
#[AsMessageHandler]
final readonly class HandleAnnouncementPlaybackRequest
{
    public function __construct(
        private PlaybackJobRepositoryInterface $jobs,
        private PlaybackEventPublisherInterface $events,
        private PlaybackQueueDispatcher $dispatcher,
        private ScheduledWakePlannerInterface $wakePlanner,
    ) {
    }

    public function __invoke(RequestAnnouncementPlayback $message): void
    {
        if ($this->jobs->findBySourceMessageId(Uuid::fromString($message->messageId)) !== null) {
            return;
        }

        $job = PlaybackJob::queue(
            $message->messageId,
            $message->announcementId,
            $message->type,
            $message->priority,
            $message->audioSequence,
            $message->repeatRule,
        );
        $this->jobs->save($job);
        $this->events->publish(AnnouncementPlaybackQueued::for($job));

        // A repeatable job is born Scheduled: plan its first tick one interval out.
        if ($job->isRepeatable()) {
            $this->wakePlanner->planWake($job->getId()->toRfc4122(), (int) $job->repeatEveryMinutes());
        }

        // The queue may be idle; try to start the head (possibly this very job).
        $this->dispatcher->dispatchNext();
    }
}
