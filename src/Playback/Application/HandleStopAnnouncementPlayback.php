<?php

declare(strict_types=1);

namespace App\Playback\Application;

use App\Playback\Application\Agent\AgentCommandPublisherInterface;
use App\Playback\Application\Agent\StopAudioPlayback;
use App\Playback\Domain\Repository\PlaybackJobRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class HandleStopAnnouncementPlayback
{
    public function __construct(private PlaybackJobRepositoryInterface $jobs, private AgentCommandPublisherInterface $agent)
    {
    }

    public function __invoke(StopAnnouncementPlayback $command): void
    {
        $job = $this->jobs->findLatestByAnnouncementIdForUpdate(Uuid::fromString($command->announcementId));
        if ($job === null || !$job->requestStop()) {
            return;
        }

        $this->jobs->save($job);
        $this->agent->publish(StopAudioPlayback::forJobId($job->getId()->toRfc4122()));
    }
}
