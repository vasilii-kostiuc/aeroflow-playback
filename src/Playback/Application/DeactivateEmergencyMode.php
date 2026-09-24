<?php

declare(strict_types=1);

namespace App\Playback\Application;

/**
 * Inbound control command from aeroflow-core (task 026): the dispatcher lifted
 * emergency mode, so the ordinary queue may start jobs again. Same transport and
 * contract shape as ActivateEmergencyMode.
 */
final readonly class DeactivateEmergencyMode
{
    public const string COMMAND = 'emergency_mode.deactivate';

    public function __construct(
        public string $messageId,
        public string $correlationId,
        public string $occurredAt,
        public int $schemaVersion,
    ) {
    }
}
