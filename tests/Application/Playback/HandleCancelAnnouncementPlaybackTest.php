<?php

declare(strict_types=1);

namespace App\Tests\Application\Playback;

use App\Playback\Application\CancelAnnouncementPlayback;
use App\Playback\Application\HandleCancelAnnouncementPlayback;
use App\Playback\Domain\Entity\PlaybackJob;
use App\Playback\Domain\Enum\PlaybackJobStatus;
use App\Tests\Application\Playback\Support\InMemoryPlaybackJobRepository;
use App\Tests\Application\Playback\Support\RecordingPlaybackEventPublisher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

final class HandleCancelAnnouncementPlaybackTest extends TestCase
{
    private InMemoryPlaybackJobRepository $jobs;
    private RecordingPlaybackEventPublisher $events;
    private HandleCancelAnnouncementPlayback $handler;

    protected function setUp(): void
    {
        $this->jobs = new InMemoryPlaybackJobRepository();
        $this->events = new RecordingPlaybackEventPublisher();
        $this->handler = new HandleCancelAnnouncementPlayback($this->jobs, $this->events, new NullLogger());
    }

    public function testCancelsPendingJobAndPublishesCancelled(): void
    {
        $job = $this->queuedJob();

        ($this->handler)($this->cancel($job->getAnnouncementId()->toRfc4122()));

        self::assertSame(PlaybackJobStatus::Cancelled, $job->getStatus());
        self::assertSame(['announcement_playback.cancelled'], $this->events->eventNames());
    }

    public function testRedeliveredCancelPublishesNothing(): void
    {
        $job = $this->queuedJob();
        $command = $this->cancel($job->getAnnouncementId()->toRfc4122());

        ($this->handler)($command);
        ($this->handler)($command);

        self::assertSame(PlaybackJobStatus::Cancelled, $job->getStatus());
        self::assertSame(['announcement_playback.cancelled'], $this->events->eventNames());
    }

    public function testLateCancelLeavesPlayingJobAlone(): void
    {
        $job = $this->queuedJob();
        $job->start();

        ($this->handler)($this->cancel($job->getAnnouncementId()->toRfc4122()));

        self::assertSame(PlaybackJobStatus::Playing, $job->getStatus());
        self::assertSame([], $this->events->eventNames());
    }

    public function testLateCancelLeavesCompletedJobAlone(): void
    {
        $job = $this->queuedJob();
        $job->start();
        $job->complete();

        ($this->handler)($this->cancel($job->getAnnouncementId()->toRfc4122()));

        self::assertSame(PlaybackJobStatus::Completed, $job->getStatus());
        self::assertSame([], $this->events->eventNames());
    }

    public function testCancelForUnknownAnnouncementIsAcknowledged(): void
    {
        ($this->handler)($this->cancel(Uuid::v7()->toRfc4122()));

        self::assertSame([], $this->events->eventNames());
    }

    private function queuedJob(): PlaybackJob
    {
        $job = PlaybackJob::queue(
            Uuid::v7()->toRfc4122(),
            Uuid::v7()->toRfc4122(),
            'check_in_opening',
            100,
            [[
                'languageCode' => 'ro-MD',
                'sortOrder' => 0,
                'items' => [['type' => 'audio_asset', 'audioAssetId' => Uuid::v7()->toRfc4122()]],
            ]],
        );
        $this->jobs->save($job);

        return $job;
    }

    private function cancel(string $announcementId): CancelAnnouncementPlayback
    {
        return new CancelAnnouncementPlayback(
            messageId: Uuid::v7()->toRfc4122(),
            correlationId: $announcementId,
            announcementId: $announcementId,
            occurredAt: '2026-07-17T10:00:00+00:00',
            schemaVersion: 1,
        );
    }
}
