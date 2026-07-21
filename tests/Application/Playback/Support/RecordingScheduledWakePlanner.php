<?php

declare(strict_types=1);

namespace App\Tests\Application\Playback\Support;

use App\Playback\Application\ScheduledWakePlannerInterface;

final class RecordingScheduledWakePlanner implements ScheduledWakePlannerInterface
{
    /** @var list<array{jobId:string,delayMinutes:int}> */
    public array $planned = [];

    public function planWake(string $jobId, int $delayMinutes): void
    {
        $this->planned[] = ['jobId' => $jobId, 'delayMinutes' => $delayMinutes];
    }
}
