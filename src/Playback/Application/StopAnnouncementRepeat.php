<?php

declare(strict_types=1);

namespace App\Playback\Application;

/**
 * Neutral Core -> Playback command ending the repeat series of an announcement
 * (task 020), published when check-in closes. Playback does not learn why — it only
 * stops cycling the job. Carries no audio payload, like the cancel and stop commands.
 */
final readonly class StopAnnouncementRepeat
{
    public const string COMMAND = 'announcement_playback.stop_repeat';

    public function __construct(
        public string $messageId,
        public string $correlationId,
        public string $announcementId,
        public string $occurredAt,
        public int $schemaVersion,
    ) {
    }
}
