<?php

declare(strict_types=1);

namespace App\Playback\Application\IntegrationEvent;

use DateTimeImmutable;
use DateTimeInterface;
use Symfony\Component\Uid\Uuid;

/**
 * "This worker is alive" (task 034), published by every playback worker at most
 * every few seconds on playback_events. Symfony's AMQP transport polls instead of
 * subscribing, so RabbitMQ shows no consumers for a live worker — only this fact
 * tells core that the queues are being served.
 *
 * Global, like EmergencyModeEvent: no announcement, no job.
 */
final readonly class PlaybackHeartbeat implements PlaybackIntegrationEvent
{
    public const string EVENT = 'playback.heartbeat';

    public string $event;
    public string $messageId;
    public string $occurredAt;
    public int $schemaVersion;

    /**
     * @param list<string> $transports the transports this worker consumes
     * @param string|null  $playingSince ATOM time the Playing job began, null when idle
     */
    public function __construct(
        public array $transports,
        public int $pendingJobs,
        public ?string $playingSince,
    ) {
        $this->event = self::EVENT;
        $this->messageId = Uuid::v7()->toRfc4122();
        $this->occurredAt = new DateTimeImmutable()->format(DateTimeInterface::ATOM);
        $this->schemaVersion = 1;
    }

    public function eventName(): string
    {
        return self::EVENT;
    }
}
