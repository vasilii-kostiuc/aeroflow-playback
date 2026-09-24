<?php

declare(strict_types=1);

namespace App\Tests\Unit\Playback\Infrastructure\Messaging;

use App\Playback\Application\ActivateEmergencyMode;
use App\Playback\Application\DeactivateEmergencyMode;
use App\Playback\Application\IntegrationEvent\EmergencyModeActivated;
use App\Playback\Infrastructure\Messaging\EmergencyCommandSerializer;
use App\Playback\Infrastructure\Messaging\PlaybackEventSerializer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Uid\Uuid;

final class EmergencyCommandSerializerTest extends TestCase
{
    public function testDecodesActivateFromContractJson(): void
    {
        $messageId = Uuid::v7()->toRfc4122();
        $announcementId = Uuid::v7()->toRfc4122();

        $envelope = new EmergencyCommandSerializer()->decode(['body' => json_encode([
            'command' => 'emergency_mode.activate',
            'messageId' => $messageId,
            'correlationId' => $announcementId,
            'occurredAt' => '2026-09-23T10:00:00+00:00',
            'schemaVersion' => 1,
        ], JSON_THROW_ON_ERROR)]);

        $message = $envelope->getMessage();
        self::assertInstanceOf(ActivateEmergencyMode::class, $message);
        self::assertSame($messageId, $message->messageId);
        self::assertSame($announcementId, $message->correlationId);
    }

    public function testDecodesDeactivateFromContractJson(): void
    {
        $messageId = Uuid::v7()->toRfc4122();

        $envelope = new EmergencyCommandSerializer()->decode(['body' => json_encode([
            'command' => 'emergency_mode.deactivate',
            'messageId' => $messageId,
            'correlationId' => $messageId,
            'occurredAt' => '2026-09-23T10:30:00+00:00',
            'schemaVersion' => 1,
        ], JSON_THROW_ON_ERROR)]);

        self::assertInstanceOf(DeactivateEmergencyMode::class, $envelope->getMessage());
    }

    public function testEncodeRoundTripsForTheRetryTransport(): void
    {
        $serializer = new EmergencyCommandSerializer();
        $messageId = Uuid::v7()->toRfc4122();

        $encoded = $serializer->encode(new Envelope(
            new DeactivateEmergencyMode($messageId, $messageId, '2026-09-23T10:30:00+00:00', 1),
        ));

        self::assertInstanceOf(DeactivateEmergencyMode::class, $serializer->decode($encoded)->getMessage());
    }

    /**
     * Unlike announcement_playback there is no legacy body on this queue, so an
     * undiscriminated payload is a decoding failure rather than a fallback.
     */
    public function testRejectsBodyWithoutCommandDiscriminator(): void
    {
        $this->expectException(MessageDecodingFailedException::class);

        new EmergencyCommandSerializer()->decode(['body' => json_encode([
            'messageId' => Uuid::v7()->toRfc4122(),
            'correlationId' => Uuid::v7()->toRfc4122(),
        ], JSON_THROW_ON_ERROR)]);
    }

    public function testRejectsAnAnnouncementPlaybackCommandOnThisQueue(): void
    {
        $this->expectException(MessageDecodingFailedException::class);

        new EmergencyCommandSerializer()->decode(['body' => json_encode([
            'command' => 'announcement_playback.request',
            'messageId' => Uuid::v7()->toRfc4122(),
            'correlationId' => Uuid::v7()->toRfc4122(),
        ], JSON_THROW_ON_ERROR)]);
    }

    /** The outbound mode reports share playback_events but carry no job. */
    public function testEncodesOutboundModeReportWithoutJobFields(): void
    {
        $encoded = new PlaybackEventSerializer()->encode(new Envelope(EmergencyModeActivated::now()));

        /** @var array<string,mixed> $body */
        $body = json_decode((string) $encoded['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('emergency_mode.activated', $body['event']);
        self::assertArrayNotHasKey('jobId', $body);
        self::assertArrayNotHasKey('announcementId', $body);
        self::assertSame(1, $body['schemaVersion']);
    }
}
