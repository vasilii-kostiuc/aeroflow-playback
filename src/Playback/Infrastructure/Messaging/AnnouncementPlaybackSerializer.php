<?php

declare(strict_types=1);

namespace App\Playback\Infrastructure\Messaging;

use App\Playback\Application\RequestAnnouncementPlayback;
use JsonException;
use LogicException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Decodes the inbound RequestAnnouncementPlayback published by aeroflow-core.
 *
 * The contract crosses a service boundary, so Playback does not rely on Core's PHP
 * class name. It reads only the JSON body and rebuilds its own message, ignoring the
 * producer's `type` header. This keeps the two services coupled to the contract, not
 * to each other's code.
 */
final class AnnouncementPlaybackSerializer implements SerializerInterface
{
    private const array REQUIRED_KEYS = [
        'messageId',
        'correlationId',
        'announcementId',
        'type',
        'priority',
        'audioSequence',
    ];

    public function decode(array $encodedEnvelope): Envelope
    {
        $body = $encodedEnvelope['body'] ?? null;
        if (!is_string($body) || $body === '') {
            throw new MessageDecodingFailedException('Empty playback request body.');
        }

        try {
            /** @var array<string,mixed> $data */
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new MessageDecodingFailedException('Playback request body is not valid JSON.', 0, $exception);
        }

        foreach (self::REQUIRED_KEYS as $key) {
            if (!array_key_exists($key, $data)) {
                throw new MessageDecodingFailedException(sprintf('Playback request is missing "%s".', $key));
            }
        }

        /** @var array<string,mixed>|null $repeatRule */
        $repeatRule = $data['repeatRule'] ?? null;

        return new Envelope(new RequestAnnouncementPlayback(
            messageId: (string) $data['messageId'],
            correlationId: (string) $data['correlationId'],
            announcementId: (string) $data['announcementId'],
            type: (string) $data['type'],
            priority: (int) $data['priority'],
            audioSequence: (array) $data['audioSequence'],
            repeatRule: $repeatRule,
            occurredAt: (string) ($data['occurredAt'] ?? ''),
            schemaVersion: (int) ($data['schemaVersion'] ?? 1),
        ));
    }

    public function encode(Envelope $envelope): array
    {
        throw new LogicException(self::class.' only decodes inbound playback requests.');
    }
}
