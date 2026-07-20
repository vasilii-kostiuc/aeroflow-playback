<?php

declare(strict_types=1);

namespace App\Playback\Application;

/** Neutral Core -> Playback operator command for a currently sounding announcement. */
final readonly class StopAnnouncementPlayback
{
    public const string COMMAND = 'announcement_playback.stop';

    public function __construct(
        public string $messageId,
        public string $correlationId,
        public string $announcementId,
        public string $occurredAt,
        public int $schemaVersion,
    ) {
    }
}
