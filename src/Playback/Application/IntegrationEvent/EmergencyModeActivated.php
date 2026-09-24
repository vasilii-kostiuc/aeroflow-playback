<?php

declare(strict_types=1);

namespace App\Playback\Application\IntegrationEvent;

/** Emergency mode is now in force: the ordinary queue is suspended (task 026). */
final readonly class EmergencyModeActivated extends EmergencyModeEvent
{
    public static function now(): self
    {
        return new self('emergency_mode.activated');
    }
}
