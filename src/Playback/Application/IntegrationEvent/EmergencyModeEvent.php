<?php

declare(strict_types=1);

namespace App\Playback\Application\IntegrationEvent;

use DateTimeImmutable;
use DateTimeInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Base of the emergency mode reports Playback publishes back to aeroflow-core (task
 * 026): the mode is global, so unlike AnnouncementPlaybackEvent these carry neither
 * announcement nor job — only the fact and when it happened.
 *
 * They exist so core's dispatcher banner shows the state that actually took effect
 * in playback rather than the one core asked for.
 */
abstract readonly class EmergencyModeEvent implements PlaybackIntegrationEvent
{
    public string $messageId;
    public string $occurredAt;
    public int $schemaVersion;

    final protected function __construct(public string $event)
    {
        $this->messageId = Uuid::v7()->toRfc4122();
        $this->occurredAt = new DateTimeImmutable()->format(DateTimeInterface::ATOM);
        $this->schemaVersion = 1;
    }

    final public function eventName(): string
    {
        return $this->event;
    }
}
