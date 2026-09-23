<?php

declare(strict_types=1);

namespace App\Playback\Application;

use App\Playback\Application\IntegrationEvent\AnnouncementPlaybackInterrupted;
use App\Playback\Application\IntegrationEvent\AnnouncementPlaybackPreempted;
use App\Playback\Domain\Enum\PlaybackJobStatus;
use App\Playback\Domain\Repository\PlaybackJobRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * The agent's audio.playback_interrupted is one neutral fact for two different
 * requests: an operator Stop (019) and a queue-dispatcher preemption (025). The
 * agent never learns which — this handler is the single place that tells them apart,
 * by the flag PlaybackQueueDispatcher::tryPreempt() left on the job before asking the
 * agent to stop it.
 */
#[AsMessageHandler]
final readonly class HandleInterruptPlaybackJob
{
    public function __construct(private PlaybackJobRepositoryInterface $jobs, private PlaybackEventPublisherInterface $events, private PlaybackQueueDispatcher $dispatcher)
    {
    }

    public function __invoke(InterruptPlaybackJob $command): void
    {
        $job = $this->jobs->findById(Uuid::fromString($command->jobId));
        if ($job === null) {
            return;
        }

        if ($job->getStatus() !== PlaybackJobStatus::Playing && $job->getStatus() !== PlaybackJobStatus::Interrupted) {
            return;
        }

        if ($job->isPreemptRequested()) {
            if ($job->preempt()) {
                $this->events->publish(AnnouncementPlaybackPreempted::for($job));
                $job->requeue();
                $this->jobs->save($job);
            }
        } elseif ($job->interrupt()) {
            $this->jobs->save($job);
            $this->events->publish(AnnouncementPlaybackInterrupted::for($job));
        }

        $this->dispatcher->dispatchNext();
    }
}
