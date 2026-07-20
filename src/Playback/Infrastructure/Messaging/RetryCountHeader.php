<?php

declare(strict_types=1);

namespace App\Playback\Infrastructure\Messaging;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

/**
 * Carries the Messenger retry count across the neutral JSON contract.
 *
 * The contract serializers intentionally drop PHP stamps, but without the
 * RedeliveryStamp a redelivered retry would always look like attempt #0 and the
 * retry limit would never trigger. A single numeric header keeps the counter
 * honest without leaking serialized PHP objects into the queue.
 */
final class RetryCountHeader
{
    private const string HEADER = 'X-Retry-Count';

    /**
     * @param array<string,string> $headers
     *
     * @return array<string,string>
     */
    public static function add(Envelope $envelope, array $headers): array
    {
        $redelivery = $envelope->last(RedeliveryStamp::class);
        if ($redelivery !== null) {
            $headers[self::HEADER] = (string) $redelivery->getRetryCount();
        }

        return $headers;
    }

    /**
     * @param array{headers?:array<string,mixed>} $encodedEnvelope
     */
    public static function restore(Envelope $envelope, array $encodedEnvelope): Envelope
    {
        $count = $encodedEnvelope['headers'][self::HEADER] ?? null;
        if (is_numeric($count) && (int) $count > 0) {
            return $envelope->with(new RedeliveryStamp((int) $count));
        }

        return $envelope;
    }
}
