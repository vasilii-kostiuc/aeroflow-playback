<?php

declare(strict_types=1);

namespace App\Tests\Unit\Playback\Infrastructure\Messaging;

use App\Playback\Application\RequestAnnouncementPlayback;
use App\Playback\Infrastructure\Messaging\AnnouncementPlaybackSerializer;
use LogicException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Uid\Uuid;

final class AnnouncementPlaybackSerializerTest extends TestCase
{
    public function testDecodesJsonBodyIntoMessageIgnoringProducerType(): void
    {
        $messageId = Uuid::v7()->toRfc4122();
        $announcementId = Uuid::v7()->toRfc4122();
        $body = json_encode([
            'messageId' => $messageId,
            'correlationId' => $announcementId,
            'announcementId' => $announcementId,
            'type' => 'check_in_opening',
            'priority' => 100,
            'audioSequence' => [['languageCode' => 'ro-MD', 'sortOrder' => 0, 'items' => []]],
            'repeatRule' => null,
            'occurredAt' => '2026-06-26T10:00:00+00:00',
            'schemaVersion' => 1,
        ], JSON_THROW_ON_ERROR);

        $envelope = (new AnnouncementPlaybackSerializer())->decode([
            'body' => $body,
            'headers' => ['type' => 'App\\Announcements\\Application\\Playback\\RequestAnnouncementPlayback'],
        ]);

        self::assertInstanceOf(Envelope::class, $envelope);
        $message = $envelope->getMessage();
        self::assertInstanceOf(RequestAnnouncementPlayback::class, $message);
        self::assertSame($messageId, $message->messageId);
        self::assertSame($announcementId, $message->announcementId);
        self::assertSame('check_in_opening', $message->type);
        self::assertSame(100, $message->priority);
        self::assertSame(1, $message->schemaVersion);
    }

    public function testRejectsBodyMissingRequiredField(): void
    {
        $this->expectException(MessageDecodingFailedException::class);

        (new AnnouncementPlaybackSerializer())->decode([
            'body' => json_encode(['messageId' => Uuid::v7()->toRfc4122()], JSON_THROW_ON_ERROR),
        ]);
    }

    public function testRejectsInvalidJson(): void
    {
        $this->expectException(MessageDecodingFailedException::class);

        (new AnnouncementPlaybackSerializer())->decode(['body' => '{not json']);
    }

    public function testEncodeIsNotSupported(): void
    {
        $this->expectException(LogicException::class);

        (new AnnouncementPlaybackSerializer())->encode(new Envelope(new \stdClass()));
    }
}
