<?php

declare(strict_types=1);

namespace App\Playback\Application;

use App\Playback\Application\IntegrationEvent\AnnouncementPlaybackInterrupted;
use App\Playback\Domain\Enum\PlaybackJobStatus;
use App\Playback\Domain\Repository\PlaybackJobRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

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

        if ($job->interrupt()) {
            $this->jobs->save($job);
            $this->events->publish(AnnouncementPlaybackInterrupted::for($job));
        }

        $this->dispatcher->dispatchNext();
    }
}
