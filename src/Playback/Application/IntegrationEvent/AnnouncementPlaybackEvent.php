<?php

declare(strict_types=1);

namespace App\Playback\Application\IntegrationEvent;

use App\Playback\Domain\Entity\PlaybackJob;
use DateTimeImmutable;
use DateTimeInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Base of the outbound integration events Playback publishes back to aeroflow-core.
 *
 * The contract is neutral and versioned: it carries only identifiers and the moment
 * the fact occurred — no airport entities, no audio payload. `event` names the fact
 * inside the JSON body so the consumer discriminates by contract, not by PHP class
 * name. correlationId equals announcementId (same correlation the inbound
 * RequestAnnouncementPlayback started).
 */
abstract readonly class AnnouncementPlaybackEvent
{
    public string $messageId;
    public string $correlationId;
    public string $announcementId;
    public string $jobId;
    public string $occurredAt;
    public int $schemaVersion;

    final protected function __construct(
        public string $event,
        PlaybackJob $job,
        public ?string $reason = null,
    ) {
        $this->messageId = Uuid::v7()->toRfc4122();
        $this->correlationId = $job->getAnnouncementId()->toRfc4122();
        $this->announcementId = $job->getAnnouncementId()->toRfc4122();
        $this->jobId = $job->getId()->toRfc4122();
        $this->occurredAt = new DateTimeImmutable()->format(DateTimeInterface::ATOM);
        $this->schemaVersion = 1;
    }
}
