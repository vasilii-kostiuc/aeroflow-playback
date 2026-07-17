<?php

declare(strict_types=1);

namespace App\Playback\Infrastructure\Messaging;

use App\Playback\Application\CancelAnnouncementPlayback;
use App\Playback\Application\RequestAnnouncementPlayback;
use JsonException;
use LogicException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Decodes the inbound playback commands published by aeroflow-core.
 *
 * The contract crosses a service boundary, so Playback does not rely on Core's PHP
 * class names. It reads only the JSON body and discriminates by the `command` field
 * (ADR 002), ignoring the producer's `type` header. This keeps the two services
 * coupled to the contract, not to each other's code.
 *
 * A body without `command` is treated as a request: it is a schemaVersion-1
 * RequestAnnouncementPlayback left in the durable queue from before the cancel
 * slice (task 018). The fallback is transitional and goes away with the next
 * contract change.
 */
final class AnnouncementPlaybackSerializer implements SerializerInterface
{
    private const string COMMAND_REQUEST = 'announcement_playback.request';
    private const string COMMAND_CANCEL = 'announcement_playback.cancel';

    private const array REQUEST_REQUIRED_KEYS = [
        'messageId',
        'correlationId',
        'announcementId',
        'type',
        'priority',
        'audioSequence',
    ];

    private const array CANCEL_REQUIRED_KEYS = [
        'messageId',
        'correlationId',
        'announcementId',
    ];

    public function decode(array $encodedEnvelope): Envelope
    {
        $body = $encodedEnvelope['body'] ?? null;
        if (!is_string($body) || $body === '') {
            throw new MessageDecodingFailedException('Empty playback command body.');
        }

        try {
            /** @var array<string,mixed> $data */
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new MessageDecodingFailedException('Playback command body is not valid JSON.', 0, $exception);
        }

        $command = $data['command'] ?? self::COMMAND_REQUEST;

        return match ($command) {
            self::COMMAND_REQUEST => $this->decodeRequest($data),
            self::COMMAND_CANCEL => $this->decodeCancel($data),
            default => throw new MessageDecodingFailedException(sprintf(
                'Unknown playback command "%s".',
                is_string($command) ? $command : get_debug_type($command),
            )),
        };
    }

    public function encode(Envelope $envelope): array
    {
        throw new LogicException(self::class.' only decodes inbound playback commands.');
    }

    /**
     * @param array<string,mixed> $data
     */
    private function decodeRequest(array $data): Envelope
    {
        $this->assertKeys($data, self::REQUEST_REQUIRED_KEYS);

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

    /**
     * @param array<string,mixed> $data
     */
    private function decodeCancel(array $data): Envelope
    {
        $this->assertKeys($data, self::CANCEL_REQUIRED_KEYS);

        return new Envelope(new CancelAnnouncementPlayback(
            messageId: (string) $data['messageId'],
            correlationId: (string) $data['correlationId'],
            announcementId: (string) $data['announcementId'],
            occurredAt: (string) ($data['occurredAt'] ?? ''),
            schemaVersion: (int) ($data['schemaVersion'] ?? 1),
        ));
    }

    /**
     * @param array<string,mixed> $data
     * @param list<string>        $keys
     */
    private function assertKeys(array $data, array $keys): void
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data)) {
                throw new MessageDecodingFailedException(sprintf('Playback command is missing "%s".', $key));
            }
        }
    }
}
