<?php

declare(strict_types=1);

namespace App\Playback\Application;

use App\Playback\Application\Agent\AgentCommandPublisherInterface;
use App\Playback\Application\Agent\PlayAudioSequence;
use App\Playback\Application\IntegrationEvent\AnnouncementPlaybackStarted;
use App\Playback\Domain\Repository\PlaybackJobRepositoryInterface;

/**
 * The queue policy of the second slice: strictly sequential, no interruption.
 *
 * Invoked after a new job is queued and after the active job finishes. While a job
 * is Playing nothing happens — ordinary announcements never interrupt each other
 * (only the future emergency mode may). Otherwise the head of the queue (priority
 * desc, then FIFO) is loaded with a write lock, started, announced to core and sent
 * to the audio agent as PlayAudioSequence (task 016). The agent's completed/failed
 * report — or the operator stub — finishes the job via CompletePlaybackJob /
 * FailPlaybackJob.
 */
final readonly class PlaybackQueueDispatcher
{
    public function __construct(
        private PlaybackJobRepositoryInterface $jobs,
        private PlaybackEventPublisherInterface $events,
        private AgentCommandPublisherInterface $agent,
    ) {
    }

    public function dispatchNext(): void
    {
        if ($this->jobs->findActive() !== null) {
            return;
        }

        $next = $this->jobs->findNextPendingForUpdate();
        if ($next === null) {
            return;
        }

        $next->start();
        $this->jobs->save($next);
        $this->events->publish(AnnouncementPlaybackStarted::for($next));
        $this->agent->publish(PlayAudioSequence::for($next));
    }
}
