<?php

declare(strict_types=1);

namespace App\Tests\Application\Playback\Support;

use App\Playback\Application\Agent\AgentCommandPublisherInterface;
use App\Playback\Application\Agent\PlayAudioSequence;

final class RecordingAgentCommandPublisher implements AgentCommandPublisherInterface
{
    /** @var list<PlayAudioSequence> */
    public array $published = [];

    public function publish(PlayAudioSequence $command): void
    {
        $this->published[] = $command;
    }
}
