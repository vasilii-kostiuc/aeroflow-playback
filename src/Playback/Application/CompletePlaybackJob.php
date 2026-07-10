<?php

declare(strict_types=1);

namespace App\Playback\Application;

/**
 * The audio output reported the active job played to the end.
 *
 * Until aeroflow-agent exists there is no real reporter: tests and the operator
 * console command are the stub. The future agent PlaybackCompleted event will feed
 * this same command without changing the domain model.
 */
final readonly class CompletePlaybackJob
{
    public function __construct(public string $jobId)
    {
    }
}
