<?php

declare(strict_types=1);

namespace App\Tests\Application\Playback;

use App\Playback\Application\HandleAnnouncementPlaybackRequest;
use App\Playback\Application\RequestAnnouncementPlayback;
use App\Playback\Domain\Entity\PlaybackJob;
use App\Playback\Domain\Enum\PlaybackJobStatus;
use App\Playback\Domain\Repository\PlaybackJobRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class HandleAnnouncementPlaybackRequestTest extends TestCase
{
    public function testCreatesPendingPlaybackJob(): void
    {
        $jobs = new InMemoryPlaybackJobRepository();
        $handler = new HandleAnnouncementPlaybackRequest($jobs);

        $handler($this->request($messageId = Uuid::v7()->toRfc4122()));

        self::assertCount(1, $jobs->saved);
        $job = array_values($jobs->saved)[0];
        self::assertSame(PlaybackJobStatus::Pending, $job->getStatus());
        self::assertSame($messageId, $job->getSourceMessageId()->toRfc4122());
        self::assertSame(100, $job->getPriority());
    }

    public function testIsIdempotentForRedeliveredMessageId(): void
    {
        $jobs = new InMemoryPlaybackJobRepository();
        $handler = new HandleAnnouncementPlaybackRequest($jobs);

        $message = $this->request(Uuid::v7()->toRfc4122());
        $handler($message);
        $handler($message);

        self::assertCount(1, $jobs->saved);
    }

    private function request(string $messageId): RequestAnnouncementPlayback
    {
        return new RequestAnnouncementPlayback(
            messageId: $messageId,
            correlationId: $announcementId = Uuid::v7()->toRfc4122(),
            announcementId: $announcementId,
            type: 'check_in_opening',
            priority: 100,
            audioSequence: [[
                'languageCode' => 'ro-MD',
                'sortOrder' => 0,
                'items' => [['type' => 'audio_asset', 'audioAssetId' => Uuid::v7()->toRfc4122()]],
            ]],
            repeatRule: null,
            occurredAt: '2026-06-26T10:00:00+00:00',
            schemaVersion: 1,
        );
    }
}

final class InMemoryPlaybackJobRepository implements PlaybackJobRepositoryInterface
{
    /** @var array<string,PlaybackJob> */
    public array $saved = [];

    public function save(PlaybackJob $job): void
    {
        $this->saved[$job->getSourceMessageId()->toRfc4122()] = $job;
    }

    public function findBySourceMessageId(Uuid $sourceMessageId): ?PlaybackJob
    {
        return $this->saved[$sourceMessageId->toRfc4122()] ?? null;
    }
}
