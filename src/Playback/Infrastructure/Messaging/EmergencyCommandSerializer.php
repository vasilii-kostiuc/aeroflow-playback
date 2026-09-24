<?php

declare(strict_types=1);

namespace App\Playback\Infrastructure\Messaging;

use App\Playback\Application\ActivateEmergencyMode;
use App\Playback\Application\DeactivateEmergencyMode;
use JsonException;
use LogicException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Decodes the emergency mode control commands published by aeroflow-core (task 026).
 *
 * A separate serializer because they arrive on a separate transport — that is the
 * whole point of ADR 002 level 2: the control plane gets its own queue so an
 * emergency signal never waits behind the bulk of ordinary playback requests. The
 * discrimination rule is the same as on announcement_playback: the `command` field
 * in the body, never the producer's PHP class name. Unlike that queue there is no
 * legacy body to fall back on, so a body without `command` is rejected.
 */
final class EmergencyCommandSerializer implements SerializerInterface
{
    private const array REQUIRED_KEYS = ['messageId', 'correlationId'];

    public function decode(array $encodedEnvelope): Envelope
    {
        $body = $encodedEnvelope['body'] ?? null;
        if (!is_string($body) || $body === '') {
            throw new MessageDecodingFailedException('Empty emergency command body.');
        }

        try {
            /** @var array<string,mixed> $data */
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new MessageDecodingFailedException('Emergency command body is not valid JSON.', 0, $exception);
        }

        $command = $data['command'] ?? null;
        foreach (self::REQUIRED_KEYS as $key) {
            if (!array_key_exists($key, $data)) {
                throw new MessageDecodingFailedException(sprintf('Emergency command is missing "%s".', $key));
            }
        }

        $envelope = match ($command) {
            ActivateEmergencyMode::COMMAND => new Envelope(new ActivateEmergencyMode(
                messageId: (string) $data['messageId'],
                correlationId: (string) $data['correlationId'],
                occurredAt: (string) ($data['occurredAt'] ?? ''),
                schemaVersion: (int) ($data['schemaVersion'] ?? 1),
            )),
            DeactivateEmergencyMode::COMMAND => new Envelope(new DeactivateEmergencyMode(
                messageId: (string) $data['messageId'],
                correlationId: (string) $data['correlationId'],
                occurredAt: (string) ($data['occurredAt'] ?? ''),
                schemaVersion: (int) ($data['schemaVersion'] ?? 1),
            )),
            default => throw new MessageDecodingFailedException(sprintf(
                'Unknown emergency command "%s".',
                is_string($command) ? $command : get_debug_type($command),
            )),
        };

        return RetryCountHeader::restore($envelope, $encodedEnvelope);
    }

    /**
     * Encoding happens when the retry listener re-sends a failed message to this
     * same transport, so the body must round-trip through decode().
     */
    public function encode(Envelope $envelope): array
    {
        $message = $envelope->getMessage();

        $body = match (true) {
            $message instanceof ActivateEmergencyMode => [
                'command' => ActivateEmergencyMode::COMMAND,
                'messageId' => $message->messageId,
                'correlationId' => $message->correlationId,
                'occurredAt' => $message->occurredAt,
                'schemaVersion' => $message->schemaVersion,
            ],
            $message instanceof DeactivateEmergencyMode => [
                'command' => DeactivateEmergencyMode::COMMAND,
                'messageId' => $message->messageId,
                'correlationId' => $message->correlationId,
                'occurredAt' => $message->occurredAt,
                'schemaVersion' => $message->schemaVersion,
            ],
            default => throw new LogicException(sprintf(
                '%s only encodes emergency commands, got "%s".',
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
}
