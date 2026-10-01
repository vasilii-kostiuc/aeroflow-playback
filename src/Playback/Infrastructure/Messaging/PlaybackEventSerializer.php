<?php

declare(strict_types=1);

namespace App\Playback\Infrastructure\Messaging;

use App\Playback\Application\IntegrationEvent\AnnouncementPlaybackEvent;
use App\Playback\Application\IntegrationEvent\EmergencyModeEvent;
use App\Playback\Application\IntegrationEvent\PlaybackHeartbeat;
use LogicException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Encodes the outbound integration events as plain contract JSON.
 *
 * The `event` field inside the body is the discriminator; Core must not depend on
 * this service's PHP class names, so no `type` header is required for consumption
 * (one is still set for observability in the RabbitMQ UI). Emergency mode reports
 * (task 026) and worker heartbeats (task 034) share the queue but not the shape:
 * being global, they carry no announcement and no job.
 */
final class PlaybackEventSerializer implements SerializerInterface
{
    public function encode(Envelope $envelope): array
    {
        $message = $envelope->getMessage();
        if ($message instanceof PlaybackHeartbeat) {
            return $this->body([
                'event' => $message->event,
                'messageId' => $message->messageId,
                'occurredAt' => $message->occurredAt,
                'schemaVersion' => $message->schemaVersion,
                'transports' => $message->transports,
                'pendingJobs' => $message->pendingJobs,
                'playingSince' => $message->playingSince,
            ], $message->event);
        }
        if ($message instanceof EmergencyModeEvent) {
            return $this->body([
                'event' => $message->event,
                'messageId' => $message->messageId,
                'occurredAt' => $message->occurredAt,
                'schemaVersion' => $message->schemaVersion,
            ], $message->event);
        }

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
        if ($message->nextAt !== null) {
            $body['nextAt'] = $message->nextAt;
        }

        return $this->body($body, $message->event);
    }

    /**
     * @param array<string,mixed> $body
     *
     * @return array{body:string,headers:array<string,string>}
     */
    private function body(array $body, string $event): array
    {
        return [
            'body' => json_encode($body, JSON_THROW_ON_ERROR),
            'headers' => [
                'type' => $event,
                'Content-Type' => 'application/json',
            ],
        ];
    }

    public function decode(array $encodedEnvelope): Envelope
    {
        throw new LogicException(self::class.' only encodes outbound playback events.');
    }
}
