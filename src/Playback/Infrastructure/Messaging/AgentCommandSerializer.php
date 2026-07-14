<?php

declare(strict_types=1);

namespace App\Playback\Infrastructure\Messaging;

use App\Playback\Application\Agent\PlayAudioSequence;
use LogicException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Encodes outbound agent commands as plain contract JSON. The `command` field in
 * the body is the discriminator (ADR 002); the agent never sees this service's PHP
 * class names.
 */
final class AgentCommandSerializer implements SerializerInterface
{
    public function encode(Envelope $envelope): array
    {
        $message = $envelope->getMessage();
        if (!$message instanceof PlayAudioSequence) {
            throw new LogicException(sprintf(
                '%s only encodes outbound agent commands, got "%s".',
                self::class,
                $message::class,
            ));
        }

        return [
            'body' => json_encode([
                'command' => PlayAudioSequence::COMMAND,
                'messageId' => $message->messageId,
                'jobId' => $message->jobId,
                'audioSequence' => $message->audioSequence,
                'occurredAt' => $message->occurredAt,
                'schemaVersion' => $message->schemaVersion,
            ], JSON_THROW_ON_ERROR),
            'headers' => [
                'type' => PlayAudioSequence::COMMAND,
                'Content-Type' => 'application/json',
            ],
        ];
    }

    public function decode(array $encodedEnvelope): Envelope
    {
        throw new LogicException(self::class.' only encodes outbound agent commands.');
    }
}
