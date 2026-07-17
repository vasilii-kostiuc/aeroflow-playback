<?php

declare(strict_types=1);

namespace App\Tests\Unit\Playback\Domain\Entity;

use App\Playback\Domain\Entity\PlaybackJob;
use App\Playback\Domain\Enum\PlaybackJobStatus;
use App\Playback\Domain\Exception\InvalidPlaybackJobTransition;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class PlaybackJobTest extends TestCase
{
    public function testQueueCreatesPendingJobFromRequest(): void
    {
        $messageId = Uuid::v7()->toRfc4122();
        $announcementId = Uuid::v7()->toRfc4122();
        $sequence = [[
            'languageCode' => 'ro-MD',
            'sortOrder' => 0,
            'items' => [['type' => 'audio_asset', 'audioAssetId' => Uuid::v7()->toRfc4122()]],
        ]];

        $job = PlaybackJob::queue($messageId, $announcementId, 'check_in_opening', 100, $sequence);

        self::assertSame(PlaybackJobStatus::Pending, $job->getStatus());
        self::assertSame($messageId, $job->getSourceMessageId()->toRfc4122());
        self::assertSame($announcementId, $job->getAnnouncementId()->toRfc4122());
        self::assertSame('check_in_opening', $job->getType());
        self::assertSame(100, $job->getPriority());
        self::assertSame($sequence, $job->getAudioSequence());
        self::assertNull($job->getRepeatRule());
        self::assertNull($job->getScheduledAt());
        self::assertTrue(Uuid::isValid($job->getId()->toRfc4122()));
    }

    public function testStartMovesPendingJobToPlaying(): void
    {
        $job = $this->pendingJob();

        $job->start();

        self::assertSame(PlaybackJobStatus::Playing, $job->getStatus());
    }

    public function testStartRejectsNonPendingJob(): void
    {
        $job = $this->pendingJob();
        $job->start();

        $this->expectException(InvalidPlaybackJobTransition::class);
        $job->start();
    }

    public function testCompleteMovesPlayingJobToCompleted(): void
    {
        $job = $this->pendingJob();
        $job->start();

        self::assertTrue($job->complete());
        self::assertSame(PlaybackJobStatus::Completed, $job->getStatus());
    }

    public function testCompleteIsIdempotentOnCompletedJob(): void
    {
        $job = $this->pendingJob();
        $job->start();
        $job->complete();

        self::assertFalse($job->complete());
        self::assertSame(PlaybackJobStatus::Completed, $job->getStatus());
    }

    public function testCompleteRejectsPendingJob(): void
    {
        $job = $this->pendingJob();

        $this->expectException(InvalidPlaybackJobTransition::class);
        $job->complete();
    }

    public function testCompleteRejectsFailedJob(): void
    {
        $job = $this->pendingJob();
        $job->start();
        $job->fail();

        $this->expectException(InvalidPlaybackJobTransition::class);
        $job->complete();
    }

    public function testFailMovesPlayingJobToFailed(): void
    {
        $job = $this->pendingJob();
        $job->start();

        self::assertTrue($job->fail());
        self::assertSame(PlaybackJobStatus::Failed, $job->getStatus());
    }

    public function testFailIsIdempotentOnFailedJob(): void
    {
        $job = $this->pendingJob();
        $job->start();
        $job->fail();

        self::assertFalse($job->fail());
        self::assertSame(PlaybackJobStatus::Failed, $job->getStatus());
    }

    public function testCancelMovesPendingJobToCancelled(): void
    {
        $job = $this->pendingJob();

        self::assertTrue($job->cancel());
        self::assertSame(PlaybackJobStatus::Cancelled, $job->getStatus());
    }

    public function testCancelIsIdempotentOnCancelledJob(): void
    {
        $job = $this->pendingJob();
        $job->cancel();

        self::assertFalse($job->cancel());
        self::assertSame(PlaybackJobStatus::Cancelled, $job->getStatus());
    }

    public function testCancelRejectsPlayingJob(): void
    {
        $job = $this->pendingJob();
        $job->start();

        $this->expectException(InvalidPlaybackJobTransition::class);
        $job->cancel();
    }

    public function testCancelledJobCannotBeStarted(): void
    {
        $job = $this->pendingJob();
        $job->cancel();

        $this->expectException(InvalidPlaybackJobTransition::class);
        $job->start();
    }

    public function testCompletedJobCannotBeRestarted(): void
    {
        $job = $this->pendingJob();
        $job->start();
        $job->complete();

        $this->expectException(InvalidPlaybackJobTransition::class);
        $job->start();
    }

    private function pendingJob(int $priority = 100): PlaybackJob
    {
        return PlaybackJob::queue(
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
    }
}
