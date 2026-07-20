<?php

declare(strict_types=1);

namespace App\Playback\Application\Agent;

use DateTimeImmutable;
use DateTimeInterface;
use Symfony\Component\Uid\Uuid;

/** Outbound command that asks the agent to stop exactly one physical job. */
final readonly class StopAudioPlayback
{
    public const string COMMAND = 'audio.stop';

    private function __construct(
        public string $messageId,
        public string $jobId,
        public string $occurredAt,
        public int $schemaVersion,
    ) {
    }

    public static function forJobId(string $jobId): self
    {
        return new self(Uuid::v7()->toRfc4122(), $jobId, (new DateTimeImmutable())->format(DateTimeInterface::ATOM), 1);
    }
}
