<?php

declare(strict_types=1);

namespace App\Playback\Domain\Repository;

use App\Playback\Domain\Entity\EmergencyMode;

interface EmergencyModeRepositoryInterface
{
    /**
     * The one emergency mode row, or a fresh inactive one when nothing ever
     * activated it. The caller persists it through save() if it changed.
     */
    public function current(): EmergencyMode;

    public function save(EmergencyMode $mode): void;
}
