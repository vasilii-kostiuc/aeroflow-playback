<?php

declare(strict_types=1);

namespace App\Tests\Unit\Playback\Domain\Entity;

use App\Playback\Domain\Entity\PlaybackJob;
use App\Playback\Domain\Enum\PlaybackCompletionResult;
use App\Playback\Domain\Enum\PlaybackJobStatus;
use App\Playback\Domain\Exception\InvalidPlaybackJobTransition;
use DateTimeImmutable;
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

    public function testStartRecordsWhenThePlayBegan(): void
    {
        $job = $this->pendingJob();
        self::assertNull($job->getStartedAt());

        $before = new DateTimeImmutable('-1 second');
        $job->start();

        self::assertNotNull($job->getStartedAt());
        self::assertGreaterThanOrEqual($before, $job->getStartedAt());
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

        self::assertSame(PlaybackCompletionResult::Completed, $job->complete());
        self::assertSame(PlaybackJobStatus::Completed, $job->getStatus());
    }

    public function testCompleteIsIdempotentOnCompletedJob(): void
    {
        $job = $this->pendingJob();
        $job->start();
        $job->complete();

        self::assertSame(PlaybackCompletionResult::NoOp, $job->complete());
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

    public function testInterruptMovesPlayingJobToInterrupted(): void
    {
        $job = $this->pendingJob();
        $job->start();

        self::assertTrue($job->interrupt());
        self::assertSame(PlaybackJobStatus::Interrupted, $job->getStatus());
        self::assertFalse($job->interrupt());
    }

    public function testStopRequestIsOnlyAcceptedOnceWhilePlaying(): void
    {
        $job = $this->pendingJob();
        $job->start();

        self::assertTrue($job->requestStop());
        self::assertFalse($job->requestStop());
    }

    public function testPreemptRequestIsOnlyAcceptedOnceWhilePlaying(): void
    {
        $job = $this->pendingJob();
        $job->start();

        self::assertTrue($job->requestPreempt());
        self::assertFalse($job->requestPreempt());
    }

    public function testStopAndPreemptRequestsAreMutuallyExclusive(): void
    {
        $stopFirst = $this->pendingJob();
        $stopFirst->start();
        self::assertTrue($stopFirst->requestStop());
        self::assertFalse($stopFirst->requestPreempt());

        $preemptFirst = $this->pendingJob();
        $preemptFirst->start();
        self::assertTrue($preemptFirst->requestPreempt());
        self::assertFalse($preemptFirst->requestStop());
    }

    public function testPreemptMovesPlayingJobToPreempted(): void
    {
        $job = $this->pendingJob();
        $job->start();
        $job->requestPreempt();

        self::assertTrue($job->preempt());
        self::assertSame(PlaybackJobStatus::Preempted, $job->getStatus());
        self::assertFalse($job->preempt());
    }

    public function testPreemptRejectsNonPlayingJob(): void
    {
        $job = $this->pendingJob();

        $this->expectException(InvalidPlaybackJobTransition::class);
        $job->preempt();
    }

    public function testRequeueReturnsPreemptedJobToPendingWithOriginalPriorityAndCreatedAt(): void
    {
        $job = $this->pendingJob(priority: 1000);
        $createdAt = $job->getCreatedAt();
        $job->start();
        $job->requestPreempt();
        $job->preempt();

        self::assertTrue($job->requeue());
        self::assertSame(PlaybackJobStatus::Pending, $job->getStatus());
        self::assertSame(1000, $job->getPriority());
        self::assertSame($createdAt, $job->getCreatedAt());
        self::assertFalse($job->isPreemptRequested());
    }

    public function testRequeuedJobCanBePreemptedAgainOnceReplaying(): void
    {
        $job = $this->pendingJob();
        $job->start();
        $job->requestPreempt();
        $job->preempt();
        $job->requeue();
        $job->start();

        self::assertTrue($job->requestPreempt());
    }

    public function testRequeueIsNoOpUnlessJobIsPreempted(): void
    {
        $job = $this->pendingJob();

        self::assertFalse($job->requeue());
        self::assertSame(PlaybackJobStatus::Pending, $job->getStatus());
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

    public function testRepeatableJobIsBornScheduledOneIntervalAhead(): void
    {
        $job = $this->repeatableJob(5);

        self::assertSame(PlaybackJobStatus::Scheduled, $job->getStatus());
        self::assertTrue($job->isRepeatable());
        self::assertSame(5, $job->repeatEveryMinutes());
        self::assertNotNull($job->getScheduledAt());
        self::assertGreaterThan(new DateTimeImmutable('+4 minutes'), $job->getScheduledAt());
    }

    public function testWakeMovesScheduledJobToPending(): void
    {
        $job = $this->repeatableJob(5);

        self::assertTrue($job->wake());
        self::assertSame(PlaybackJobStatus::Pending, $job->getStatus());
    }

    public function testWakeIsNoOpWhenNotScheduled(): void
    {
        $job = $this->repeatableJob(5);
        $job->wake();
        $job->start();

        self::assertFalse($job->wake());
        self::assertSame(PlaybackJobStatus::Playing, $job->getStatus());
    }

    public function testRepeatableJobRearmsOnCompletionInsteadOfCompleting(): void
    {
        $job = $this->repeatableJob(5);
        $job->wake();
        $job->start();

        self::assertSame(PlaybackCompletionResult::Rearmed, $job->complete());
        self::assertSame(PlaybackJobStatus::Scheduled, $job->getStatus());
        self::assertGreaterThan(new DateTimeImmutable('+4 minutes'), $job->getScheduledAt());
    }

    public function testCompletionOfRepeatableJobBetweenTicksIsIdempotentNoOp(): void
    {
        $job = $this->repeatableJob(5);
        $job->wake();
        $job->start();
        $job->complete(); // re-armed, now Scheduled

        // A redelivery of the tick completion arrives while the job rests: no-op.
        self::assertSame(PlaybackCompletionResult::NoOp, $job->complete());
        self::assertSame(PlaybackJobStatus::Scheduled, $job->getStatus());
    }

    public function testCancelRepeatWhilePlayingLetsCurrentTickFinishThenTerminates(): void
    {
        $job = $this->repeatableJob(5);
        $job->wake();
        $job->start();

        self::assertTrue($job->cancelRepeat());
        self::assertTrue($job->isRepeatCancelled());
        // The current tick keeps sounding until it completes.
        self::assertSame(PlaybackJobStatus::Playing, $job->getStatus());

        self::assertSame(PlaybackCompletionResult::Completed, $job->complete());
        self::assertSame(PlaybackJobStatus::Completed, $job->getStatus());
    }

    public function testCancelRepeatBetweenTicksTerminatesImmediately(): void
    {
        $job = $this->repeatableJob(5); // Scheduled

        self::assertTrue($job->cancelRepeat());
        self::assertSame(PlaybackJobStatus::Completed, $job->getStatus());
    }

    public function testCancelRepeatIsIdempotentAndNoOpOnOneShotJob(): void
    {
        $oneShot = $this->pendingJob();
        self::assertFalse($oneShot->cancelRepeat());

        $job = $this->repeatableJob(5);
        self::assertTrue($job->cancelRepeat());
        self::assertFalse($job->cancelRepeat());
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

    private function repeatableJob(int $everyMinutes): PlaybackJob
    {
        return PlaybackJob::queue(
            Uuid::v7()->toRfc4122(),
            Uuid::v7()->toRfc4122(),
            'check_in_continuation',
            100,
            [[
                'languageCode' => 'ro-MD',
                'sortOrder' => 0,
                'items' => [['type' => 'audio_asset', 'audioAssetId' => Uuid::v7()->toRfc4122()]],
            ]],
            ['everyMinutes' => $everyMinutes],
        );
    }
}
