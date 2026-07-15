<?php

declare(strict_types=1);

namespace App\Playback\Application\Agent;

use App\Playback\Application\CompletePlaybackJob;
use App\Playback\Application\FailPlaybackJob;
use App\Playback\Application\HandleCompletePlaybackJob;
use App\Playback\Application\HandleFailPlaybackJob;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Feeds agent facts into the seam prepared by task 015: completed/failed become the
 * idempotent CompletePlaybackJob/FailPlaybackJob (invoked directly, not re-dispatched,
 * so the whole reaction commits in this handler's transaction). Started is only
 * logged — the dispatcher already moved the job to Playing when it sent the command.
 */
#[AsMessageHandler]
final readonly class HandleAgentPlaybackEvent
{
    public function __construct(
        private HandleCompletePlaybackJob $completeHandler,
        private HandleFailPlaybackJob $failHandler,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(AgentPlaybackEvent $event): void
    {
        match ($event->event) {
            AgentPlaybackEvent::COMPLETED => ($this->completeHandler)(new CompletePlaybackJob($event->jobId)),
            AgentPlaybackEvent::FAILED => $this->handleFailed($event),
            AgentPlaybackEvent::STARTED => $this->logger->info('Agent started playing job {jobId}', ['jobId' => $event->jobId]),
            default => $this->logger->warning('Ignoring unknown agent event {event}', ['event' => $event->event]),
        };
    }

    private function handleFailed(AgentPlaybackEvent $event): void
    {
        $this->logger->warning('Agent failed playing job {jobId}: {reason}', [
            'jobId' => $event->jobId,
            'reason' => $event->reason ?? 'no reason given',
        ]);
        ($this->failHandler)(new FailPlaybackJob($event->jobId, $event->reason));
    }
}
