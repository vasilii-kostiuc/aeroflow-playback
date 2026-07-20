<?php

declare(strict_types=1);

namespace App\Playback\Application\Agent;

/**
 * Publishes commands to the audio agent.
 *
 * Same post-commit rule as the integration events: a command raised inside the
 * handler transaction is released only after the commit and discarded on rollback,
 * so a rolled-back start never asks the agent to play.
 */
interface AgentCommandPublisherInterface
{
    public function publish(PlayAudioSequence|StopAudioPlayback $command): void;
}
