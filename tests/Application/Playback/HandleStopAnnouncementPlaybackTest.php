<?php

declare(strict_types=1);

namespace App\Tests\Application\Playback;

use App\Playback\Application\Agent\StopAudioPlayback;
use App\Playback\Application\HandleStopAnnouncementPlayback;
use App\Playback\Application\StopAnnouncementPlayback;
use App\Playback\Domain\Entity\PlaybackJob;
use App\Playback\Domain\Enum\PlaybackJobStatus;
use App\Tests\Application\Playback\Support\InMemoryPlaybackJobRepository;
use App\Tests\Application\Playback\Support\RecordingAgentCommandPublisher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class HandleStopAnnouncementPlaybackTest extends TestCase
{
    private InMemoryPlaybackJobRepository $jobs;
    private RecordingAgentCommandPublisher $agent;
    private HandleStopAnnouncementPlayback $handler;

    protected function setUp(): void
    {
        $this->jobs = new InMemoryPlaybackJobRepository();
        $this->agent = new RecordingAgentCommandPublisher();
        $this->handler = new HandleStopAnnouncementPlayback($this->jobs, $this->agent);
    }

    public function testStopOfPlayingJobSendsOneAgentStop(): void
    {
        $job = $this->queuedJob();
        $job->start();

        ($this->handler)($this->stop($job->getAnnouncementId()->toRfc4122()));

        self::assertSame(PlaybackJobStatus::Playing, $job->getStatus());
        self::assertCount(1, $this->agent->published);
        $command = $this->agent->published[0];
        self::assertInstanceOf(StopAudioPlayback::class, $command);
        self::assertSame($job->getId()->toRfc4122(), $command->jobId);
    }

    public function testRedeliveredStopDoesNotSendASecondAgentStop(): void
    {
        $job = $this->queuedJob();
        $job->start();
        $command = $this->stop($job->getAnnouncementId()->toRfc4122());

        ($this->handler)($command);
        ($this->handler)($command);

        self::assertCount(1, $this->agent->published);
    }

    public function testStopOfPendingJobIsANoOp(): void
    {
        $job = $this->queuedJob();

        ($this->handler)($this->stop($job->getAnnouncementId()->toRfc4122()));

        self::assertSame(PlaybackJobStatus::Pending, $job->getStatus());
        self::assertSame([], $this->agent->published);
    }

    public function testLateStopOfFinishedJobIsANoOp(): void
    {
        $job = $this->queuedJob();
        $job->start();
        $job->complete();

        ($this->handler)($this->stop($job->getAnnouncementId()->toRfc4122()));

        self::assertSame(PlaybackJobStatus::Completed, $job->getStatus());
        self::assertSame([], $this->agent->published);
    }

    public function testStopForUnknownAnnouncementIsAcknowledged(): void
    {
        ($this->handler)($this->stop(Uuid::v7()->toRfc4122()));

        self::assertSame([], $this->agent->published);
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

    private function stop(string $announcementId): StopAnnouncementPlayback
    {
        return new StopAnnouncementPlayback(
            messageId: Uuid::v7()->toRfc4122(),
            correlationId: $announcementId,
            announcementId: $announcementId,
            occurredAt: '2026-07-17T10:00:00+00:00',
            schemaVersion: 1,
        );
    }
}
