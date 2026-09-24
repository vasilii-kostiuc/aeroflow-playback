<?php

declare(strict_types=1);

namespace App\Tests\Application\Playback\Support;

use App\Playback\Domain\Entity\EmergencyMode;
use App\Playback\Domain\Repository\EmergencyModeRepositoryInterface;

final class InMemoryEmergencyModeRepository implements EmergencyModeRepositoryInterface
{
    public ?EmergencyMode $saved = null;

    private EmergencyMode $mode;

    public function __construct(bool $active = false)
    {
        $this->mode = EmergencyMode::inactive();
        if ($active) {
            $this->mode->activate();
        }
    }

    public function current(): EmergencyMode
    {
        return $this->mode;
    }

    public function save(EmergencyMode $mode): void
    {
        $this->mode = $mode;
        $this->saved = $mode;
    }
}
