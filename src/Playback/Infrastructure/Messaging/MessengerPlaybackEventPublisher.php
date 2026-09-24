<?php

declare(strict_types=1);

namespace App\Playback\Infrastructure\Messaging;

use App\Playback\Application\IntegrationEvent\PlaybackIntegrationEvent;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Sends an outbound integration event to aeroflow-core. Routing sends every
 * PlaybackIntegrationEvent (per-job facts and emergency mode reports alike) to the
 * playback_events RabbitMQ transport; this service has no local handler for them.
 */
final readonly class MessengerPlaybackEventPublisher
{
    public function __construct(private MessageBusInterface $messageBus)
    {
    }

    public function publish(PlaybackIntegrationEvent $event): void
    {
        $this->messageBus->dispatch($event);
    }
}
