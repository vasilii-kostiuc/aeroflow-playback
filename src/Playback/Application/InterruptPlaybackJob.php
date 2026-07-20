<?php

declare(strict_types=1);

namespace App\Playback\Application;

final readonly class InterruptPlaybackJob
{
    public function __construct(public string $jobId)
    {
    }
}
