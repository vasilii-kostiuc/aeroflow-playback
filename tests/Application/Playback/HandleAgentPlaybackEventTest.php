<?php

declare(strict_types=1);

namespace App\Tests\Application\Playback;

use App\Playback\Application\Agent\AgentPlaybackEvent;
use App\Playback\Application\Agent\HandleAgentPlaybackEvent;
use App\Playback\Application\HandleCompletePlaybackJob;
use App\Playback\Application\HandleFailPlaybackJob;
use App\Playback\Application\HandleInterruptPlaybackJob;
use App\Playback\Application\PlaybackQueueDispatcher;
use App\Playback\Domain\Entity\PlaybackJob;
use App\Playback\Domain\Enum\PlaybackJobStatus;
use App\Tests\Application\Playback\Support\InMemoryPlaybackJobRepository;
use App\Tests\Application\Playback\Support\RecordingAgentCommandPublisher;
use App\Tests\Application\Playback\Support\RecordingPlaybackEventPublisher;
use App\Tests\Application\Playback\Support\RecordingScheduledWakePlanner;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

final class HandleAgentPlaybackEventTest extends TestCase
{
    private InMemoryPlaybackJobRepository $jobs;
    private RecordingPlaybackEventPublisher $events;
    private RecordingAgentCommandPublisher $agentCommands;
    private HandleAgentPlaybackEvent $handler;

    protected function setUp(): void
    {
        $this->jobs = new InMemoryPlaybackJobRepository();
        $this->events = new RecordingPlaybackEventPublisher();
        $this->agentCommands = new RecordingAgentCommandPublisher();
        $dispatcher = new PlaybackQueueDispatcher($this->jobs, $this->events, $this->agentCommands);
        $this->handler = new HandleAgentPlaybackEvent(
            new HandleCompletePlaybackJob($this->jobs, $this->events, $dispatcher, new RecordingScheduledWakePlanner()),
            new HandleFailPlaybackJob($this->jobs, $this->events, $dispatcher),
            new HandleInterruptPlaybackJob($this->jobs, $this->events, $dispatcher),
            new NullLogger(),
        );
    }

    public function testCompletedEventCompletesJobAndAdvancesQueue(): void
    {
        $active = $this->playingJob();
        $waiting = $this->pendingJob();

        ($this->handler)($this->event(AgentPlaybackEvent::COMPLETED, $active->getId()->toRfc4122()));

        self::assertSame(PlaybackJobStatus::Completed, $active->getStatus());
        self::assertSame(PlaybackJobStatus::Playing, $waiting->getStatus());
        self::assertSame(
            ['announcement_playback.completed', 'announcement_playback.started'],
            $this->events->eventNames(),
        );
        // The next job travels to the agent too.
        self::assertCount(1, $this->agentCommands->published);
        self::assertSame($waiting->getId()->toRfc4122(), $this->agentCommands->published[0]->jobId);
    }

    public function testFailedEventFailsJobAndAdvancesQueue(): void
    {
        $active = $this->playingJob();
        $waiting = $this->pendingJob();

        ($this->handler)($this->event(AgentPlaybackEvent::FAILED, $active->getId()->toRfc4122(), 'player exited 1'));

        self::assertSame(PlaybackJobStatus::Failed, $active->getStatus());
        self::assertSame(PlaybackJobStatus::Playing, $waiting->getStatus());
        // The failure is reported to core with the agent's reason, then the queue moves on.
        self::assertSame(
            ['announcement_playback.failed', 'announcement_playback.started'],
            $this->events->eventNames(),
        );
        self::assertSame('player exited 1', $this->events->published[0]->reason);
    }

    public function testStartedEventChangesNothing(): void
    {
        $active = $this->playingJob();

        ($this->handler)($this->event(AgentPlaybackEvent::STARTED, $active->getId()->toRfc4122()));

        self::assertSame(PlaybackJobStatus::Playing, $active->getStatus());
        self::assertSame([], $this->events->published);
    }

    public function testInterruptedEventInterruptsJobAndAdvancesQueue(): void
    {
        $active = $this->playingJob();
        $waiting = $this->pendingJob();

        ($this->handler)($this->event(AgentPlaybackEvent::INTERRUPTED, $active->getId()->toRfc4122()));

        self::assertSame(PlaybackJobStatus::Interrupted, $active->getStatus());
        self::assertSame(PlaybackJobStatus::Playing, $waiting->getStatus());
        self::assertSame(
            ['announcement_playback.interrupted', 'announcement_playback.started'],
            $this->events->eventNames(),
        );
    }

    public function testInterruptedEventOnAPreemptRequestedJobRequeuesItInsteadOfInterrupting(): void
    {
        $active = $this->playingJob(priority: 100);
        $active->requestPreempt();
        $emergency = $this->pendingJob(priority: PlaybackJob::PREEMPTIVE_PRIORITY);

        ($this->handler)($this->event(AgentPlaybackEvent::INTERRUPTED, $active->getId()->toRfc4122()));

        // Preempted -> Pending, not the operator's terminal Interrupted; the job is
        // eligible to play again, not gone.
        self::assertSame(PlaybackJobStatus::Pending, $active->getStatus());
        self::assertFalse($active->isPreemptRequested());
        self::assertSame(PlaybackJobStatus::Playing, $emergency->getStatus());
        self::assertSame(
            ['announcement_playback.preempted', 'announcement_playback.started'],
            $this->events->eventNames(),
        );
    }

    public function testRedeliveredInterruptedEventAfterRequeueDoesNotPreemptAgain(): void
    {
        $active = $this->playingJob(priority: 100);
        $active->requestPreempt();
        $this->pendingJob(priority: PlaybackJob::PREEMPTIVE_PRIORITY);
        $event = $this->event(AgentPlaybackEvent::INTERRUPTED, $active->getId()->toRfc4122());

        ($this->handler)($event);
        // $active is now Pending (the emergency job outranks it and is Playing
        // instead) — a stale redelivery for the same jobId finds it neither Playing
        // nor Interrupted and is a no-op, guarded by the handler's status check.
        ($this->handler)($event);

        self::assertSame(
            ['announcement_playback.preempted', 'announcement_playback.started'],
            $this->events->eventNames(),
        );
    }

    public function testUnknownEventIsIgnored(): void
    {
        $active = $this->playingJob();

        ($this->handler)($this->event('audio.playback_paused', $active->getId()->toRfc4122()));

        self::assertSame(PlaybackJobStatus::Playing, $active->getStatus());
    }

    private function event(string $name, string $jobId, ?string $reason = null): AgentPlaybackEvent
    {
        return new AgentPlaybackEvent(
            event: $name,
            messageId: Uuid::v7()->toRfc4122(),
            jobId: $jobId,
            reason: $reason,
            occurredAt: '2026-07-10T10:00:00+00:00',
            schemaVersion: 1,
        );
    }

    private function playingJob(int $priority = 100): PlaybackJob
    {
        $job = $this->pendingJob($priority);
        $job->start();

        return $job;
    }

    private function pendingJob(int $priority = 100): PlaybackJob
    {
        $job = PlaybackJob::queue(
            Uuid::v7()->toRfc4122(),
            Uuid::v7()->toRfc4122(),
            'check_in_opening',
            $priority,
            [[
                'languageCode' => 'ro-MD',
                'sortOrder' => 0,
                'items' => [['type' => 'audio_asset', 'audioAssetId' => Uuid::v7()->toRfc4122()]],
            ]],
        );
        $this->jobs->save($job);

        return $job;
    }
}
