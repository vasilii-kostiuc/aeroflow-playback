<?php

declare(strict_types=1);

namespace App\Playback\Application;

use App\Playback\Application\Agent\AgentCommandPublisherInterface;
use App\Playback\Application\Agent\PlayAudioSequence;
use App\Playback\Application\Agent\StopAudioPlayback;
use App\Playback\Application\IntegrationEvent\AnnouncementPlaybackStarted;
use App\Playback\Domain\Entity\PlaybackJob;
use App\Playback\Domain\Repository\PlaybackJobRepositoryInterface;

/**
 * The queue policy of the second slice: strictly sequential, no interruption —
 * extended by task 025 with a single reserved exception.
 *
 * Invoked after a new job is queued and after the active job finishes. While a job
 * is Playing, ordinary announcements never interrupt each other: the head of the
 * queue only starts once the active job leaves Playing. The sole exception is a
 * pending job at or above PlaybackJob::PREEMPTIVE_PRIORITY that is strictly higher
 * than the active job's own priority — it preempts instead of waiting (see
 * tryPreempt()). Otherwise the head of the queue (priority desc, then FIFO) is
 * loaded with a write lock, started, announced to core and sent to the audio agent
 * as PlayAudioSequence (task 016). The agent's completed/failed report — or the
 * operator stub — finishes the job via CompletePlaybackJob / FailPlaybackJob.
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
        $active = $this->jobs->findActive();
        if ($active !== null) {
            $this->tryPreempt($active);

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

    /**
     * Ask the agent to stop the active job in favour of a pending one, if — and only
     * if — that pending job is both strictly higher priority than the active job and
     * itself at or above the preemptive threshold. The pending job is not started
     * here: it becomes a normal dispatchNext() candidate once the agent confirms the
     * physical stop and HandleInterruptPlaybackJob requeues the preempted job.
     */
    private function tryPreempt(PlaybackJob $active): void
    {
        $next = $this->jobs->findNextPendingForUpdate();
        if ($next === null
            || $next->getPriority() < PlaybackJob::PREEMPTIVE_PRIORITY
            || $next->getPriority() <= $active->getPriority()
        ) {
            return;
        }

        if ($active->requestPreempt()) {
            $this->jobs->save($active);
            $this->agent->publish(StopAudioPlayback::forJobId($active->getId()->toRfc4122()));
        }
    }
}
