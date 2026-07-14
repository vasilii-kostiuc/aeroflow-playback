<?php

declare(strict_types=1);

namespace App\Playback\Infrastructure\Messaging;

use App\Playback\Application\Agent\PlayAudioSequence;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Sends an agent command to the agent_commands RabbitMQ transport (see routing);
 * this service has no local handler for it.
 */
final readonly class MessengerAgentCommandPublisher
{
    public function __construct(private MessageBusInterface $messageBus)
    {
    }

    public function publish(PlayAudioSequence $command): void
    {
        $this->messageBus->dispatch($command);
    }
}
