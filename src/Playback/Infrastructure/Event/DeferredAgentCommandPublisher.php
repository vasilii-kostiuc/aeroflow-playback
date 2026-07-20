<?php

declare(strict_types=1);

namespace App\Playback\Infrastructure\Event;

use App\Playback\Application\Agent\AgentCommandPublisherInterface;
use App\Playback\Application\Agent\PlayAudioSequence;
use App\Playback\Application\Agent\StopAudioPlayback;
use App\Playback\Infrastructure\Messaging\MessengerAgentCommandPublisher;

/**
 * Post-commit buffer for agent commands, same contract as
 * DeferredPlaybackEventPublisher: a rolled-back start must never reach the agent's
 * speakers. Driven by IntegrationEventTransactionMiddleware.
 */
final class DeferredAgentCommandPublisher implements AgentCommandPublisherInterface
{
    private int $depth = 0;

    /** @var list<PlayAudioSequence|StopAudioPlayback> */
    private array $buffer = [];

    public function __construct(private readonly MessengerAgentCommandPublisher $publisher)
    {
    }

    public function publish(PlayAudioSequence|StopAudioPlayback $command): void
    {
        if ($this->depth === 0) {
            $this->publisher->publish($command);

            return;
        }

        $this->buffer[] = $command;
    }

    public function enter(): void
    {
        ++$this->depth;
    }

    public function commit(): void
    {
        if ($this->depth === 0) {
            return;
        }

        --$this->depth;
        if ($this->depth > 0) {
            return;
        }

        $commands = $this->buffer;
        $this->buffer = [];
        foreach ($commands as $command) {
            $this->publisher->publish($command);
        }
    }

    public function rollback(): void
    {
        if ($this->depth === 0) {
            return;
        }

        --$this->depth;
        if ($this->depth === 0) {
            $this->buffer = [];
        }
    }
}
