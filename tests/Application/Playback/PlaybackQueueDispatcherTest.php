<?php

declare(strict_types=1);

namespace App\Tests\Application\Playback;

use App\Playback\Application\Agent\StopAudioPlayback;
use App\Playback\Application\PlaybackQueueDispatcher;
use App\Playback\Domain\Entity\PlaybackJob;
use App\Playback\Domain\Enum\PlaybackJobStatus;
use App\Tests\Application\Playback\Support\InMemoryEmergencyModeRepository;
use App\Tests\Application\Playback\Support\InMemoryPlaybackJobRepository;
use App\Tests\Application\Playback\Support\RecordingAgentCommandPublisher;
use App\Tests\Application\Playback\Support\RecordingPlaybackEventPublisher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class PlaybackQueueDispatcherTest extends TestCase
{
    public function testStartsHighestPriorityPendingJob(): void
    {
        $jobs = new InMemoryPlaybackJobRepository();
        $events = new RecordingPlaybackEventPublisher();
        $agent = new RecordingAgentCommandPublisher();
        $additional = $this->queueJob($jobs, priority: 50);
        $flight = $this->queueJob($jobs, priority: 100);

        new PlaybackQueueDispatcher($jobs, $events, $agent, new InMemoryEmergencyModeRepository())->dispatchNext();

        self::assertSame(PlaybackJobStatus::Playing, $flight->getStatus());
        self::assertSame(PlaybackJobStatus::Pending, $additional->getStatus());
        self::assertSame(['announcement_playback.started'], $events->eventNames());
        self::assertSame($flight->getId()->toRfc4122(), $events->published[0]->jobId);
        // The started job is sent to the audio agent with its sequence as is (016).
        self::assertCount(1, $agent->published);
        self::assertSame($flight->getId()->toRfc4122(), $agent->published[0]->jobId);
        self::assertSame($flight->getAudioSequence(), $agent->published[0]->audioSequence);
    }

    public function testEqualPriorityIsServedFifo(): void
    {
        $jobs = new InMemoryPlaybackJobRepository();
        $events = new RecordingPlaybackEventPublisher();
        $agent = new RecordingAgentCommandPublisher();
        $first = $this->queueJob($jobs, priority: 100);
        $second = $this->queueJob($jobs, priority: 100);

        new PlaybackQueueDispatcher($jobs, $events, $agent, new InMemoryEmergencyModeRepository())->dispatchNext();

        self::assertSame(PlaybackJobStatus::Playing, $first->getStatus());
        self::assertSame(PlaybackJobStatus::Pending, $second->getStatus());
    }

    public function testDoesNothingWhileAJobIsPlaying(): void
    {
        $jobs = new InMemoryPlaybackJobRepository();
        $events = new RecordingPlaybackEventPublisher();
        $agent = new RecordingAgentCommandPublisher();
        $active = $this->queueJob($jobs, priority: 50);
        $active->start();
        $waiting = $this->queueJob($jobs, priority: 100);

        new PlaybackQueueDispatcher($jobs, $events, $agent, new InMemoryEmergencyModeRepository())->dispatchNext();

        // A higher-priority ordinary announcement never interrupts the active one.
        self::assertSame(PlaybackJobStatus::Playing, $active->getStatus());
        self::assertSame(PlaybackJobStatus::Pending, $waiting->getStatus());
        self::assertSame([], $events->published);
        self::assertSame([], $agent->published);
    }

    public function testPreemptsActiveJobForAPendingJobAtOrAboveThePreemptiveThreshold(): void
    {
        $jobs = new InMemoryPlaybackJobRepository();
        $events = new RecordingPlaybackEventPublisher();
        $agent = new RecordingAgentCommandPublisher();
        $active = $this->queueJob($jobs, priority: 100);
        $active->start();
        $emergency = $this->queueJob($jobs, priority: PlaybackJob::PREEMPTIVE_PRIORITY);

        new PlaybackQueueDispatcher($jobs, $events, $agent, new InMemoryEmergencyModeRepository())->dispatchNext();

        // The active job is asked to stop — but not started/completed here: it only
        // leaves Playing once the agent confirms the physical stop.
        self::assertSame(PlaybackJobStatus::Playing, $active->getStatus());
        self::assertTrue($active->isPreemptRequested());
        self::assertSame(PlaybackJobStatus::Pending, $emergency->getStatus());
        self::assertCount(1, $agent->published);
        $command = $agent->published[0];
        self::assertInstanceOf(StopAudioPlayback::class, $command);
        self::assertSame($active->getId()->toRfc4122(), $command->jobId);
        // No AnnouncementPlaybackStarted for the emergency job yet — that happens on
        // the next dispatchNext(), after HandleInterruptPlaybackJob requeues the
        // preempted job.
        self::assertSame([], $events->published);
    }

    public function testDoesNotPreemptWhenPendingPriorityOnlyEqualsTheActiveOnesEvenAtTheThreshold(): void
    {
        $jobs = new InMemoryPlaybackJobRepository();
        $events = new RecordingPlaybackEventPublisher();
        $agent = new RecordingAgentCommandPublisher();
        $active = $this->queueJob($jobs, priority: PlaybackJob::PREEMPTIVE_PRIORITY);
        $active->start();
        $secondEmergency = $this->queueJob($jobs, priority: PlaybackJob::PREEMPTIVE_PRIORITY);

        new PlaybackQueueDispatcher($jobs, $events, $agent, new InMemoryEmergencyModeRepository())->dispatchNext();

        // Equal-priority emergencies queue up and play back to back; neither
        // preempts the other.
        self::assertSame(PlaybackJobStatus::Playing, $active->getStatus());
        self::assertFalse($active->isPreemptRequested());
        self::assertSame(PlaybackJobStatus::Pending, $secondEmergency->getStatus());
        self::assertSame([], $agent->published);
    }

    public function testDoesNotPreemptForAHigherButBelowThresholdPriority(): void
    {
        $jobs = new InMemoryPlaybackJobRepository();
        $events = new RecordingPlaybackEventPublisher();
        $agent = new RecordingAgentCommandPublisher();
        $active = $this->queueJob($jobs, priority: 50);
        $active->start();
        // Flight (100) is strictly higher than supplementary (50), but 100 is still
        // an ordinary priority: it must queue, not preempt.
        $flight = $this->queueJob($jobs, priority: 100);

        new PlaybackQueueDispatcher($jobs, $events, $agent, new InMemoryEmergencyModeRepository())->dispatchNext();

        self::assertSame(PlaybackJobStatus::Playing, $active->getStatus());
        self::assertFalse($active->isPreemptRequested());
        self::assertSame(PlaybackJobStatus::Pending, $flight->getStatus());
        self::assertSame([], $agent->published);
    }

    public function testDoesNothingWhileAJobIsPlayingAndNothingIsPending(): void
    {
        $jobs = new InMemoryPlaybackJobRepository();
        $events = new RecordingPlaybackEventPublisher();
        $agent = new RecordingAgentCommandPublisher();
        $active = $this->queueJob($jobs, priority: PlaybackJob::PREEMPTIVE_PRIORITY);
        $active->start();

        new PlaybackQueueDispatcher($jobs, $events, $agent, new InMemoryEmergencyModeRepository())->dispatchNext();

        self::assertSame(PlaybackJobStatus::Playing, $active->getStatus());
        self::assertFalse($active->isPreemptRequested());
        self::assertSame([], $agent->published);
    }

    public function testDoesNothingOnEmptyQueue(): void
    {
        $jobs = new InMemoryPlaybackJobRepository();
        $events = new RecordingPlaybackEventPublisher();
        $agent = new RecordingAgentCommandPublisher();

        new PlaybackQueueDispatcher($jobs, $events, $agent, new InMemoryEmergencyModeRepository())->dispatchNext();

        self::assertSame([], $events->published);
    }

    private function queueJob(InMemoryPlaybackJobRepository $jobs, int $priority): PlaybackJob
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
        $jobs->save($job);

        return $job;
    }
}
