<?php

declare(strict_types=1);

namespace App\Playback\Infrastructure\Messaging;

use App\Playback\Application\IntegrationEvent\AnnouncementPlaybackEvent;
use LogicException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Encodes outbound AnnouncementPlaybackEvent as plain contract JSON.
 *
 * The `event` field inside the body is the discriminator; Core must not depend on
 * this service's PHP class names, so no `type` header is required for consumption
 * (one is still set for observability in the RabbitMQ UI).
 */
final class PlaybackEventSerializer implements SerializerInterface
{
    public function encode(Envelope $envelope): array
    {
        $message = $envelope->getMessage();
        if (!$message instanceof AnnouncementPlaybackEvent) {
            throw new LogicException(sprintf(
                '%s only encodes outbound playback events, got "%s".',
                self::class,
                $message::class,
            ));
        }

        $body = [
            'event' => $message->event,
            'messageId' => $message->messageId,
            'correlationId' => $message->correlationId,
            'announcementId' => $message->announcementId,
            'jobId' => $message->jobId,
            'occurredAt' => $message->occurredAt,
            'schemaVersion' => $message->schemaVersion,
        ];
        if ($message->reason !== null) {
            $body['reason'] = $message->reason;
        }

        return [
            'body' => json_encode($body, JSON_THROW_ON_ERROR),
            'headers' => [
                'type' => $message->event,
                'Content-Type' => 'application/json',
            ],
        ];
    }

    public function decode(array $encodedEnvelope): Envelope
    {
        throw new LogicException(self::class.' only encodes outbound playback events.');
    }
}
