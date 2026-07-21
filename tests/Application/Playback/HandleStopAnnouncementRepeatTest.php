<?php

declare(strict_types=1);

namespace App\Tests\Application\Playback;

use App\Playback\Application\HandleStopAnnouncementRepeat;
use App\Playback\Application\PlaybackQueueDispatcher;
use App\Playback\Application\StopAnnouncementRepeat;
use App\Playback\Domain\Entity\PlaybackJob;
use App\Playback\Domain\Enum\PlaybackJobStatus;
use App\Tests\Application\Playback\Support\InMemoryPlaybackJobRepository;
use App\Tests\Application\Playback\Support\RecordingAgentCommandPublisher;
use App\Tests\Application\Playback\Support\RecordingPlaybackEventPublisher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class HandleStopAnnouncementRepeatTest extends TestCase
{
    private InMemoryPlaybackJobRepository $jobs;
    private RecordingPlaybackEventPublisher $events;
    private HandleStopAnnouncementRepeat $handler;

    protected function setUp(): void
    {
        $this->jobs = new InMemoryPlaybackJobRepository();
        $this->events = new RecordingPlaybackEventPublisher();
        $dispatcher = new PlaybackQueueDispatcher($this->jobs, $this->events, new RecordingAgentCommandPublisher());
        $this->handler = new HandleStopAnnouncementRepeat($this->jobs, $this->events, $dispatcher);
    }

    public function testStopWhilePlayingFlagsSeriesButLetsTickFinish(): void
    {
        $job = $this->repeatableJob();
        $job->wake();
        $job->start();
        $this->jobs->save($job);

        ($this->handler)($this->command($job->getAnnouncementId()->toRfc4122()));

        self::assertTrue($job->isRepeatCancelled());
        // The current tick keeps sounding; no event, no termination yet.
        self::assertSame(PlaybackJobStatus::Playing, $job->getStatus());
        self::assertSame([], $this->events->eventNames());
    }

    public function testStopBetweenTicksTerminatesAndReportsCompletion(): void
    {
        $job = $this->repeatableJob(); // born Scheduled
        $this->jobs->save($job);

        ($this->handler)($this->command($job->getAnnouncementId()->toRfc4122()));

        self::assertSame(PlaybackJobStatus::Completed, $job->getStatus());
        self::assertSame(['announcement_playback.completed'], $this->events->eventNames());
    }

    public function testUnknownAnnouncementIsNoOp(): void
    {
        ($this->handler)($this->command(Uuid::v7()->toRfc4122()));

        self::assertSame([], $this->events->eventNames());
    }

    public function testRedeliveryIsIdempotent(): void
    {
        $job = $this->repeatableJob();
        $this->jobs->save($job);
        $command = $this->command($job->getAnnouncementId()->toRfc4122());

        ($this->handler)($command);
        ($this->handler)($command);

        self::assertSame(['announcement_playback.completed'], $this->events->eventNames());
    }

    public function testStopOnOneShotJobIsNoOp(): void
    {
        $job = PlaybackJob::queue(
            Uuid::v7()->toRfc4122(),
            $announcementId = Uuid::v7()->toRfc4122(),
            'check_in_opening',
            100,
            $this->sequence(),
        );
        $this->jobs->save($job);

        ($this->handler)($this->command($announcementId));

        self::assertSame(PlaybackJobStatus::Pending, $job->getStatus());
        self::assertSame([], $this->events->eventNames());
    }

    private function command(string $announcementId): StopAnnouncementRepeat
    {
        return new StopAnnouncementRepeat(
            messageId: Uuid::v7()->toRfc4122(),
            correlationId: $announcementId,
            announcementId: $announcementId,
            occurredAt: '2026-07-20T10:00:00+00:00',
            schemaVersion: 1,
        );
    }

    private function repeatableJob(): PlaybackJob
    {
        return PlaybackJob::queue(
            Uuid::v7()->toRfc4122(),
            Uuid::v7()->toRfc4122(),
            'check_in_continuation',
            100,
            $this->sequence(),
            ['everyMinutes' => 5],
        );
    }

    /** @return list<array{languageCode:string,sortOrder:int,items:list<array<string,mixed>>}> */
    private function sequence(): array
    {
        return [[
            'languageCode' => 'ro-MD',
            'sortOrder' => 0,
            'items' => [['type' => 'audio_asset', 'audioAssetId' => Uuid::v7()->toRfc4122()]],
        ]];
    }
}
