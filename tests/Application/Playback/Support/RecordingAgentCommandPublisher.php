<?php

declare(strict_types=1);

namespace App\Tests\Application\Playback\Support;

use App\Playback\Application\Agent\AgentCommandPublisherInterface;
use App\Playback\Application\Agent\PlayAudioSequence;
use App\Playback\Application\Agent\StopAudioPlayback;

final class RecordingAgentCommandPublisher implements AgentCommandPublisherInterface
{
    /** @var list<PlayAudioSequence|StopAudioPlayback> */
    public array $published = [];

    public function publish(PlayAudioSequence|StopAudioPlayback $command): void
    {
        $this->published[] = $command;
    }
}
