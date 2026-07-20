<?php

declare(strict_types=1);

namespace App\Playback\Infrastructure\Messaging;

use App\Playback\Application\Agent\AgentPlaybackEvent;
use JsonException;
use LogicException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Decodes inbound agent status events, discriminated by the `event` field in the
 * JSON body (ADR 002) — playback is not coupled to the agent's classes.
 */
final class AgentEventSerializer implements SerializerInterface
{
    private const array REQUIRED_KEYS = ['event', 'messageId', 'jobId'];

    public function decode(array $encodedEnvelope): Envelope
    {
        $body = $encodedEnvelope['body'];
        if ($body === '') {
            throw new MessageDecodingFailedException('Empty agent event body.');
        }

        try {
            /** @var array<string,mixed> $data */
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new MessageDecodingFailedException('Agent event body is not valid JSON.', 0, $exception);
        }

        foreach (self::REQUIRED_KEYS as $key) {
            if (!array_key_exists($key, $data)) {
                throw new MessageDecodingFailedException(sprintf('Agent event is missing "%s".', $key));
            }
        }

        return RetryCountHeader::restore(new Envelope(new AgentPlaybackEvent(
            event: (string) $data['event'],
            messageId: (string) $data['messageId'],
            jobId: (string) $data['jobId'],
            reason: isset($data['reason']) ? (string) $data['reason'] : null,
            occurredAt: (string) ($data['occurredAt'] ?? ''),
            schemaVersion: (int) ($data['schemaVersion'] ?? 1),
        )), $encodedEnvelope);
    }

    /**
     * Encoding happens when the retry listener re-sends a failed event to this
     * same transport; the body must round-trip through decode(). A throwing
     * encode() would kill the whole worker on the first handler failure.
     */
    public function encode(Envelope $envelope): array
    {
        $message = $envelope->getMessage();
        if (!$message instanceof AgentPlaybackEvent) {
            throw new LogicException(sprintf(
                '%s only encodes agent events, got "%s".',
                self::class,
                get_debug_type($message),
            ));
        }

        $body = [
            'event' => $message->event,
            'messageId' => $message->messageId,
            'jobId' => $message->jobId,
        ];
        if ($message->reason !== null) {
            $body['reason'] = $message->reason;
        }
        $body['occurredAt'] = $message->occurredAt;
        $body['schemaVersion'] = $message->schemaVersion;

        return [
            'body' => json_encode($body, JSON_THROW_ON_ERROR),
            'headers' => RetryCountHeader::add($envelope, [
                'type' => $message->event,
                'Content-Type' => 'application/json',
            ]),
        ];
    }
}
