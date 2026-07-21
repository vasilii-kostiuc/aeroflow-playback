<?php

declare(strict_types=1);

namespace App\Tests\Unit\Playback\Infrastructure\Messaging;

use App\Playback\Application\CancelAnnouncementPlayback;
use App\Playback\Application\RequestAnnouncementPlayback;
use App\Playback\Application\StopAnnouncementPlayback;
use App\Playback\Application\StopAnnouncementRepeat;
use App\Playback\Infrastructure\Messaging\AnnouncementPlaybackSerializer;
use LogicException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Uid\Uuid;

final class AnnouncementPlaybackSerializerTest extends TestCase
{
    public function testDecodesRequestBodyDiscriminatedByCommand(): void
    {
        $messageId = Uuid::v7()->toRfc4122();
        $announcementId = Uuid::v7()->toRfc4122();
        $body = json_encode([
            'command' => 'announcement_playback.request',
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

    public function testBodyWithoutCommandDecodesAsLegacyRequest(): void
    {
        $announcementId = Uuid::v7()->toRfc4122();
        $body = json_encode([
            'messageId' => Uuid::v7()->toRfc4122(),
            'correlationId' => $announcementId,
            'announcementId' => $announcementId,
            'type' => 'check_in_opening',
            'priority' => 100,
            'audioSequence' => [['languageCode' => 'ro-MD', 'sortOrder' => 0, 'items' => []]],
            'occurredAt' => '2026-06-26T10:00:00+00:00',
            'schemaVersion' => 1,
        ], JSON_THROW_ON_ERROR);

        $message = (new AnnouncementPlaybackSerializer())->decode(['body' => $body])->getMessage();

        self::assertInstanceOf(RequestAnnouncementPlayback::class, $message);
        self::assertSame($announcementId, $message->announcementId);
    }

    public function testDecodesCancelBodyDiscriminatedByCommand(): void
    {
        $messageId = Uuid::v7()->toRfc4122();
        $announcementId = Uuid::v7()->toRfc4122();
        $body = json_encode([
            'command' => 'announcement_playback.cancel',
            'messageId' => $messageId,
            'correlationId' => $announcementId,
            'announcementId' => $announcementId,
            'occurredAt' => '2026-07-17T10:00:00+00:00',
            'schemaVersion' => 1,
        ], JSON_THROW_ON_ERROR);

        $message = (new AnnouncementPlaybackSerializer())->decode([
            'body' => $body,
            'headers' => ['type' => 'App\\Announcements\\Application\\Playback\\CancelAnnouncementPlayback'],
        ])->getMessage();

        self::assertInstanceOf(CancelAnnouncementPlayback::class, $message);
        self::assertSame($messageId, $message->messageId);
        self::assertSame($announcementId, $message->announcementId);
        self::assertSame(1, $message->schemaVersion);
    }

    public function testDecodesStopBodyDiscriminatedByCommand(): void
    {
        $messageId = Uuid::v7()->toRfc4122();
        $announcementId = Uuid::v7()->toRfc4122();
        $body = json_encode([
            'command' => 'announcement_playback.stop',
            'messageId' => $messageId,
            'correlationId' => $announcementId,
            'announcementId' => $announcementId,
            'occurredAt' => '2026-07-17T10:00:00+00:00',
            'schemaVersion' => 1,
        ], JSON_THROW_ON_ERROR);

        $message = (new AnnouncementPlaybackSerializer())->decode([
            'body' => $body,
            'headers' => ['type' => 'App\\Announcements\\Application\\Playback\\StopAnnouncementPlayback'],
        ])->getMessage();

        self::assertInstanceOf(StopAnnouncementPlayback::class, $message);
        self::assertSame($messageId, $message->messageId);
        self::assertSame($announcementId, $message->announcementId);
        self::assertSame(1, $message->schemaVersion);
    }

    public function testRejectsStopBodyMissingRequiredField(): void
    {
        $this->expectException(MessageDecodingFailedException::class);

        (new AnnouncementPlaybackSerializer())->decode([
            'body' => json_encode([
                'command' => 'announcement_playback.stop',
                'messageId' => Uuid::v7()->toRfc4122(),
            ], JSON_THROW_ON_ERROR),
        ]);
    }

    public function testDecodesStopRepeatBodyDiscriminatedByCommand(): void
    {
        $messageId = Uuid::v7()->toRfc4122();
        $announcementId = Uuid::v7()->toRfc4122();
        $body = json_encode([
            'command' => 'announcement_playback.stop_repeat',
            'messageId' => $messageId,
            'correlationId' => $announcementId,
            'announcementId' => $announcementId,
            'occurredAt' => '2026-07-20T10:00:00+00:00',
            'schemaVersion' => 1,
        ], JSON_THROW_ON_ERROR);

        $message = (new AnnouncementPlaybackSerializer())->decode(['body' => $body])->getMessage();

        self::assertInstanceOf(StopAnnouncementRepeat::class, $message);
        self::assertSame($messageId, $message->messageId);
        self::assertSame($announcementId, $message->announcementId);
    }

    public function testEncodesStopRepeatCommandBody(): void
    {
        $announcementId = Uuid::v7()->toRfc4122();
        $stopRepeat = new StopAnnouncementRepeat(
            messageId: Uuid::v7()->toRfc4122(),
            correlationId: $announcementId,
            announcementId: $announcementId,
            occurredAt: '2026-07-20T10:00:00+00:00',
            schemaVersion: 1,
        );

        $encoded = (new AnnouncementPlaybackSerializer())->encode(new Envelope($stopRepeat));

        $body = json_decode($encoded['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('announcement_playback.stop_repeat', $body['command']);
        self::assertSame($announcementId, $body['announcementId']);
        self::assertSame('announcement_playback.stop_repeat', $encoded['headers']['type']);
    }

    public function testEncodeDecodeRoundTripsForRetryRedelivery(): void
    {
        $announcementId = Uuid::v7()->toRfc4122();
        $request = new RequestAnnouncementPlayback(
            messageId: Uuid::v7()->toRfc4122(),
            correlationId: $announcementId,
            announcementId: $announcementId,
            type: 'check_in_opening',
            priority: 100,
            audioSequence: [['languageCode' => 'ro-MD', 'sortOrder' => 0, 'items' => []]],
            repeatRule: null,
            occurredAt: '2026-07-18T10:00:00+00:00',
            schemaVersion: 2,
        );
        $serializer = new AnnouncementPlaybackSerializer();

        // The retry listener re-sends the failed envelope through encode(); the
        // redelivered body must decode again and keep the retry counter.
        $encoded = $serializer->encode(new Envelope($request, [new RedeliveryStamp(2)]));
        $decoded = $serializer->decode($encoded);

        $message = $decoded->getMessage();
        self::assertInstanceOf(RequestAnnouncementPlayback::class, $message);
        self::assertSame($request->messageId, $message->messageId);
        self::assertSame($request->audioSequence, $message->audioSequence);
        self::assertSame(2, $decoded->last(RedeliveryStamp::class)?->getRetryCount());
    }

    public function testEncodesStopCommandBody(): void
    {
        $announcementId = Uuid::v7()->toRfc4122();
        $stop = new StopAnnouncementPlayback(
            messageId: Uuid::v7()->toRfc4122(),
            correlationId: $announcementId,
            announcementId: $announcementId,
            occurredAt: '2026-07-18T10:00:00+00:00',
            schemaVersion: 1,
        );

        $encoded = (new AnnouncementPlaybackSerializer())->encode(new Envelope($stop));

        $body = json_decode($encoded['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('announcement_playback.stop', $body['command']);
        self::assertSame($announcementId, $body['announcementId']);
    }

    public function testRejectsUnknownCommand(): void
    {
        $this->expectException(MessageDecodingFailedException::class);
        $this->expectExceptionMessage('Unknown playback command');

        (new AnnouncementPlaybackSerializer())->decode([
            'body' => json_encode([
                'command' => 'announcement_playback.explode',
                'messageId' => Uuid::v7()->toRfc4122(),
            ], JSON_THROW_ON_ERROR),
        ]);
    }

    public function testRejectsCancelBodyMissingRequiredField(): void
    {
        $this->expectException(MessageDecodingFailedException::class);

        (new AnnouncementPlaybackSerializer())->decode([
            'body' => json_encode([
                'command' => 'announcement_playback.cancel',
                'messageId' => Uuid::v7()->toRfc4122(),
            ], JSON_THROW_ON_ERROR),
        ]);
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
