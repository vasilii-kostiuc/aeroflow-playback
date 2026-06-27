<?php

declare(strict_types=1);

namespace App\Playback\Application;

/**
 * Inbound integration command from aeroflow-core. Playback owns its own copy of the
 * contract: the message is rebuilt from the JSON body by a transport serializer, so
 * Playback is not coupled to Core's PHP classes. Only the fields Playback needs are
 * modelled here.
 */
final readonly class RequestAnnouncementPlayback
{
    /**
     * @param list<array{languageCode:string,sortOrder:int,items:list<array<string,mixed>>}> $audioSequence
     * @param array<string,mixed>|null                                                       $repeatRule
     */
    public function __construct(
        public string $messageId,
        public string $correlationId,
        public string $announcementId,
        public string $type,
        public int $priority,
        public array $audioSequence,
        public ?array $repeatRule,
        public string $occurredAt,
        public int $schemaVersion,
    ) {
    }
}
