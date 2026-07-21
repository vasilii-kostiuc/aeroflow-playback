<?php

declare(strict_types=1);

namespace App\Tests\Application\Playback;

use App\Playback\Application\CompletePlaybackJob;
use App\Playback\Application\FailPlaybackJob;
use App\Playback\Application\HandleCompletePlaybackJob;
use App\Playback\Application\HandleFailPlaybackJob;
use App\Playback\Application\PlaybackQueueDispatcher;
use App\Playback\Domain\Entity\PlaybackJob;
use App\Playback\Domain\Enum\PlaybackJobStatus;
use App\Tests\Application\Playback\Support\InMemoryPlaybackJobRepository;
use App\Tests\Application\Playback\Support\RecordingAgentCommandPublisher;
use App\Tests\Application\Playback\Support\RecordingPlaybackEventPublisher;
use App\Tests\Application\Playback\Support\RecordingScheduledWakePlanner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class HandleCompletePlaybackJobTest extends TestCase
{
    private InMemoryPlaybackJobRepository $jobs;
    private RecordingPlaybackEventPublisher $events;
    private RecordingAgentCommandPublisher $agentCommands;
    private RecordingScheduledWakePlanner $wakePlanner;
    private HandleCompletePlaybackJob $handler;

    protected function setUp(): void
    {
        $this->jobs = new InMemoryPlaybackJobRepository();
        $this->events = new RecordingPlaybackEventPublisher();
        $this->agentCommands = new RecordingAgentCommandPublisher();
        $this->wakePlanner = new RecordingScheduledWakePlanner();
        $dispatcher = new PlaybackQueueDispatcher($this->jobs, $this->events, $this->agentCommands);
        $this->handler = new HandleCompletePlaybackJob($this->jobs, $this->events, $dispatcher, $this->wakePlanner);
    }

    public function testCompletesJobPublishesEventAndStartsNext(): void
    {
        $active = $this->queueJob(priority: 100);
        $active->start();
        $waiting = $this->queueJob(priority: 50);

        ($this->handler)(new CompletePlaybackJob($active->getId()->toRfc4122()));

        self::assertSame(PlaybackJobStatus::Completed, $active->getStatus());
        self::assertSame(PlaybackJobStatus::Playing, $waiting->getStatus());
        self::assertSame(
            ['announcement_playback.completed', 'announcement_playback.started'],
            $this->events->eventNames(),
        );
    }

    public function testRedeliveredCompletionDoesNotPublishTwiceButStillAdvancesQueue(): void
    {
        $active = $this->queueJob(priority: 100);
        $active->start();

        $command = new CompletePlaybackJob($active->getId()->toRfc4122());
        ($this->handler)($command);

        $waiting = $this->queueJob(priority: 50);
        ($this->handler)($command);

        // One completed event for the first delivery, and the queue still advanced
        // to the job that arrived between the two deliveries.
        self::assertSame(
            [
                'announcement_playback.completed',
                'announcement_playback.started',
            ],
            array_slice($this->events->eventNames(), 0, 2),
        );
        self::assertSame(1, count(array_keys($this->events->eventNames(), 'announcement_playback.completed', true)));
        self::assertSame(PlaybackJobStatus::Playing, $waiting->getStatus());
    }

    public function testUnknownJobIsIgnored(): void
    {
        ($this->handler)(new CompletePlaybackJob(Uuid::v7()->toRfc4122()));

        self::assertSame([], $this->events->published);
    }

    public function testFailedJobPublishesFailedEventAndAdvancesQueue(): void
    {
        $active = $this->queueJob(priority: 100);
        $active->start();
        $waiting = $this->queueJob(priority: 50);

        $failHandler = new HandleFailPlaybackJob(
            $this->jobs,
            $this->events,
            new PlaybackQueueDispatcher($this->jobs, $this->events, $this->agentCommands),
        );
        $failHandler(new FailPlaybackJob($active->getId()->toRfc4122(), 'player exited 1'));

        self::assertSame(PlaybackJobStatus::Failed, $active->getStatus());
        self::assertSame(PlaybackJobStatus::Playing, $waiting->getStatus());
        self::assertSame(
            ['announcement_playback.failed', 'announcement_playback.started'],
            $this->events->eventNames(),
        );
        self::assertSame('player exited 1', $this->events->published[0]->reason);

        // A redelivered failure publishes nothing twice.
        $failHandler(new FailPlaybackJob($active->getId()->toRfc4122(), 'player exited 1'));
        self::assertSame(1, count(array_keys($this->events->eventNames(), 'announcement_playback.failed', true)));
    }

    public function testRepeatableJobRearmsAndPlansWakeInsteadOfCompleting(): void
    {
        $job = PlaybackJob::queue(
            Uuid::v7()->toRfc4122(),
            Uuid::v7()->toRfc4122(),
            'check_in_continuation',
            100,
            [[
                'languageCode' => 'ro-MD',
                'sortOrder' => 0,
                'items' => [['type' => 'audio_asset', 'audioAssetId' => Uuid::v7()->toRfc4122()]],
            ]],
            ['everyMinutes' => 5],
        );
        $job->wake();
        $job->start();
        $this->jobs->save($job);

        ($this->handler)(new CompletePlaybackJob($job->getId()->toRfc4122()));

        self::assertSame(PlaybackJobStatus::Scheduled, $job->getStatus());
        // No completion event — the series continues; the next tick is planned.
        self::assertSame([], $this->events->eventNames());
        self::assertSame(
            [['jobId' => $job->getId()->toRfc4122(), 'delayMinutes' => 5]],
            $this->wakePlanner->planned,
        );
    }

    private function queueJob(int $priority): PlaybackJob
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
