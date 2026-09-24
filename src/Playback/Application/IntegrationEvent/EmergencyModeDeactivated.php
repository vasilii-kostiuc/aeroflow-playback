<?php

declare(strict_types=1);

namespace App\Playback\Application\IntegrationEvent;

/** Emergency mode is lifted: the ordinary queue runs again (task 026). */
final readonly class EmergencyModeDeactivated extends EmergencyModeEvent
{
    public static function now(): self
    {
        return new self('emergency_mode.deactivated');
    }
}
