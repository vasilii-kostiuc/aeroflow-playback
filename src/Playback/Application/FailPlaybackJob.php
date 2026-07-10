<?php

declare(strict_types=1);

namespace App\Playback\Application;

/**
 * The audio output reported the active job failed. Counterpart of
 * CompletePlaybackJob; fed by the future agent PlaybackFailed event.
 */
final readonly class FailPlaybackJob
{
    public function __construct(public string $jobId)
    {
    }
}
