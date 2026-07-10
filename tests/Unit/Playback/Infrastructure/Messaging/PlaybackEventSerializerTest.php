<?php

declare(strict_types=1);

namespace App\Tests\Unit\Playback\Infrastructure\Messaging;

use App\Playback\Application\IntegrationEvent\AnnouncementPlaybackQueued;
use App\Playback\Domain\Entity\PlaybackJob;
use App\Playback\Infrastructure\Messaging\PlaybackEventSerializer;
use LogicException;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Uid\Uuid;

final class PlaybackEventSerializerTest extends TestCase
{
    public function testEncodesNeutralContractJson(): void
    {
        $job = PlaybackJob::queue(
            Uuid::v7()->toRfc4122(),
            $announcementId = Uuid::v7()->toRfc4122(),
            'check_in_opening',
            100,
            [[
                'languageCode' => 'ro-MD',
                'sortOrder' => 0,
                'items' => [['type' => 'audio_asset', 'audioAssetId' => Uuid::v7()->toRfc4122()]],
            ]],
        );

        $encoded = new PlaybackEventSerializer()->encode(new Envelope(AnnouncementPlaybackQueued::for($job)));

        $body = json_decode($encoded['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('announcement_playback.queued', $body['event']);
        self::assertSame($announcementId, $body['announcementId']);
        self::assertSame($announcementId, $body['correlationId']);
        self::assertSame($job->getId()->toRfc4122(), $body['jobId']);
        self::assertSame(1, $body['schemaVersion']);
        self::assertTrue(Uuid::isValid($body['messageId']));
        self::assertNotEmpty($body['occurredAt']);
        // The neutral contract carries identifiers only — no airport entities, no audio payload.
        self::assertSame(
            ['event', 'messageId', 'correlationId', 'announcementId', 'jobId', 'occurredAt', 'schemaVersion'],
            array_keys($body),
        );
        self::assertSame('announcement_playback.queued', $encoded['headers']['type']);
    }

    public function testRefusesForeignMessages(): void
    {
        $this->expectException(LogicException::class);

        new PlaybackEventSerializer()->encode(new Envelope(new stdClass()));
    }
}
