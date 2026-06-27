<?php

declare(strict_types=1);

namespace App\Tests\Unit\Playback\Domain\Entity;

use App\Playback\Domain\Entity\PlaybackJob;
use App\Playback\Domain\Enum\PlaybackJobStatus;
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
}
