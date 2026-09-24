<?php

declare(strict_types=1);

namespace App\Playback\Application;

/**
 * Inbound control command from aeroflow-core (task 026): suspend the ordinary queue
 * until the mode is lifted.
 *
 * It arrives on its own emergency_commands transport (ADR 002, level 2), never on
 * the announcement_playback queue — a control signal must not wait behind the bulk
 * of ordinary playback requests. Playback owns its copy of the contract; the message
 * is rebuilt from the JSON body discriminated by the `command` field.
 */
final readonly class ActivateEmergencyMode
{
    public const string COMMAND = 'emergency_mode.activate';

    public function __construct(
        public string $messageId,
        public string $correlationId,
        public string $occurredAt,
        public int $schemaVersion,
    ) {
    }
}
