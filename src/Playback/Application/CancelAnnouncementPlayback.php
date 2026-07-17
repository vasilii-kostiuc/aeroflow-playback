<?php

declare(strict_types=1);

namespace App\Playback\Application;

/**
 * Inbound integration command from aeroflow-core (task 018): the announcement was
 * cancelled, drop its still-pending job. Playback owns its own copy of the
 * contract; the message is rebuilt from the JSON body by the transport serializer
 * discriminating on the `command` field (ADR 002).
 */
final readonly class CancelAnnouncementPlayback
{
    public function __construct(
        public string $messageId,
        public string $correlationId,
        public string $announcementId,
        public string $occurredAt,
        public int $schemaVersion,
    ) {
    }
}
