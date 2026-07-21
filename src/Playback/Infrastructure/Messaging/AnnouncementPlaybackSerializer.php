<?php

declare(strict_types=1);

namespace App\Playback\Infrastructure\Messaging;

use App\Playback\Application\CancelAnnouncementPlayback;
use App\Playback\Application\RequestAnnouncementPlayback;
use App\Playback\Application\StopAnnouncementPlayback;
use App\Playback\Application\StopAnnouncementRepeat;
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
    private const string COMMAND_STOP = 'announcement_playback.stop';
    private const string COMMAND_STOP_REPEAT = 'announcement_playback.stop_repeat';

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

        $envelope = match ($command) {
            self::COMMAND_REQUEST => $this->decodeRequest($data),
            self::COMMAND_CANCEL => $this->decodeCancel($data),
            self::COMMAND_STOP => $this->decodeStop($data),
            self::COMMAND_STOP_REPEAT => $this->decodeStopRepeat($data),
            default => throw new MessageDecodingFailedException(sprintf(
                'Unknown playback command "%s".',
                is_string($command) ? $command : get_debug_type($command),
            )),
        };

        return RetryCountHeader::restore($envelope, $encodedEnvelope);
    }

    /**
     * Encoding happens when the retry listener re-sends a failed message to this
     * same transport. The body must round-trip through decode(), so it is the
     * inbound contract JSON again — a throwing encode() would kill the whole
     * worker on the first handler failure.
     */
    public function encode(Envelope $envelope): array
    {
        $message = $envelope->getMessage();

        $body = match (true) {
            $message instanceof RequestAnnouncementPlayback => [
                'command' => self::COMMAND_REQUEST,
                'messageId' => $message->messageId,
                'correlationId' => $message->correlationId,
                'announcementId' => $message->announcementId,
                'type' => $message->type,
                'priority' => $message->priority,
                'audioSequence' => $message->audioSequence,
                'repeatRule' => $message->repeatRule,
                'occurredAt' => $message->occurredAt,
                'schemaVersion' => $message->schemaVersion,
            ],
            $message instanceof CancelAnnouncementPlayback => [
                'command' => self::COMMAND_CANCEL,
                'messageId' => $message->messageId,
                'correlationId' => $message->correlationId,
                'announcementId' => $message->announcementId,
                'occurredAt' => $message->occurredAt,
                'schemaVersion' => $message->schemaVersion,
            ],
            $message instanceof StopAnnouncementPlayback => [
                'command' => self::COMMAND_STOP,
                'messageId' => $message->messageId,
                'correlationId' => $message->correlationId,
                'announcementId' => $message->announcementId,
                'occurredAt' => $message->occurredAt,
                'schemaVersion' => $message->schemaVersion,
            ],
            $message instanceof StopAnnouncementRepeat => [
                'command' => self::COMMAND_STOP_REPEAT,
                'messageId' => $message->messageId,
                'correlationId' => $message->correlationId,
                'announcementId' => $message->announcementId,
                'occurredAt' => $message->occurredAt,
                'schemaVersion' => $message->schemaVersion,
            ],
            default => throw new LogicException(sprintf(
                '%s only encodes playback commands, got "%s".',
                self::class,
                get_debug_type($message),
            )),
        };

        return [
            'body' => json_encode($body, JSON_THROW_ON_ERROR),
            'headers' => RetryCountHeader::add($envelope, [
                'type' => $body['command'],
                'Content-Type' => 'application/json',
            ]),
        ];
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

    /** @param array<string,mixed> $data */
    private function decodeStop(array $data): Envelope
    {
        $this->assertKeys($data, self::CANCEL_REQUIRED_KEYS);

        return new Envelope(new StopAnnouncementPlayback(
            messageId: (string) $data['messageId'],
            correlationId: (string) $data['correlationId'],
            announcementId: (string) $data['announcementId'],
            occurredAt: (string) ($data['occurredAt'] ?? ''),
            schemaVersion: (int) ($data['schemaVersion'] ?? 1),
        ));
    }

    /** @param array<string,mixed> $data */
    private function decodeStopRepeat(array $data): Envelope
    {
        $this->assertKeys($data, self::CANCEL_REQUIRED_KEYS);

        return new Envelope(new StopAnnouncementRepeat(
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
