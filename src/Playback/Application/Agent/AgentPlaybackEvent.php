<?php

declare(strict_types=1);

namespace App\Playback\Application\Agent;

/**
 * Inbound status event from aeroflow-agent (queue agent_events), rebuilt from the
 * JSON body and discriminated by the `event` field — playback does not know the
 * agent's classes. One message class carries all three facts because playback
 * treats them through the same seam (Complete/Fail commands, log for started).
 */
final readonly class AgentPlaybackEvent
{
    public const string STARTED = 'audio.playback_started';
    public const string COMPLETED = 'audio.playback_completed';
    public const string FAILED = 'audio.playback_failed';

    public function __construct(
        public string $event,
        public string $messageId,
        public string $jobId,
        public ?string $reason,
        public string $occurredAt,
        public int $schemaVersion,
    ) {
    }
}
