<?php

declare(strict_types=1);

namespace App\Playback\Infrastructure\Event;

use App\Playback\Application\IntegrationEvent\PlaybackIntegrationEvent;
use App\Playback\Application\PlaybackEventPublisherInterface;
use App\Playback\Infrastructure\Messaging\MessengerPlaybackEventPublisher;

/**
 * Buffers integration events raised inside a command bus transaction and releases
 * them only after the transaction commits, so a rolled-back handler never leaks a
 * Queued/Started/Completed fact to core. Same post-commit compromise as Core's
 * DeferredDomainEventPublisher; the in-process precursor to a durable outbox.
 *
 * Outside a transactional scope (depth 0) events are sent immediately. The scope
 * is driven by IntegrationEventTransactionMiddleware.
 */
final class DeferredPlaybackEventPublisher implements PlaybackEventPublisherInterface
{
    private int $depth = 0;

    /** @var list<PlaybackIntegrationEvent> */
    private array $buffer = [];

    public function __construct(private readonly MessengerPlaybackEventPublisher $publisher)
    {
    }

    public function publish(PlaybackIntegrationEvent $event): void
    {
        if ($this->depth === 0) {
            $this->publisher->publish($event);

            return;
        }

        $this->buffer[] = $event;
    }

    public function enter(): void
    {
        ++$this->depth;
    }

    /**
     * Leave the current scope; on the outermost scope, send the buffered events
     * (called after the transaction has committed).
     */
    public function commit(): void
    {
        if ($this->depth === 0) {
            return;
        }

        --$this->depth;
        if ($this->depth > 0) {
            return;
        }

        $events = $this->buffer;
        $this->buffer = [];
        foreach ($events as $event) {
            $this->publisher->publish($event);
        }
    }

    /**
     * Leave the current scope discarding buffered events on the outermost scope
     * (the transaction rolled back).
     */
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
