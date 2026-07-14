<?php

declare(strict_types=1);

namespace App\Playback\Infrastructure\Messenger;

use App\Playback\Infrastructure\Event\DeferredAgentCommandPublisher;
use App\Playback\Infrastructure\Event\DeferredPlaybackEventPublisher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Throwable;

/**
 * Opens an outbound-message scope around each command bus message and releases the
 * buffered integration events and agent commands only after the message (and its
 * Doctrine transaction) has been handled successfully. On failure the buffers are
 * discarded together with the rolled-back transaction.
 *
 * Must be registered before `doctrine_transaction` in the command bus middleware
 * list so that its post-handling (commit) runs after the transaction commits.
 */
final readonly class IntegrationEventTransactionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private DeferredPlaybackEventPublisher $events,
        private DeferredAgentCommandPublisher $agentCommands,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $this->events->enter();
        $this->agentCommands->enter();

        try {
            $envelope = $stack->next()->handle($envelope, $stack);
            // Events first (core learns the fact), then the command to the agent.
            $this->events->commit();
            $this->agentCommands->commit();

            return $envelope;
        } catch (Throwable $exception) {
            $this->events->rollback();
            $this->agentCommands->rollback();

            throw $exception;
        }
    }
}
