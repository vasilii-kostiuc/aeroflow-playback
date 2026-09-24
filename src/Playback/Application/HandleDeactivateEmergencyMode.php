<?php

declare(strict_types=1);

namespace App\Playback\Application;

use App\Playback\Application\IntegrationEvent\EmergencyModeDeactivated;
use App\Playback\Domain\Repository\EmergencyModeRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Switches emergency mode off (task 026) and lets the ordinary queue run again:
 * whatever waited behind the guard is dispatched right away, so the dispatcher does
 * not have to wait for the next event to nudge the queue.
 *
 * Idempotent: lifting an already inactive mode changes nothing, reports nothing and
 * does not re-dispatch. Jobs already playing or waiting are untouched — this only
 * removes the guard; stopping a specific sounding announcement is still the
 * operator's Stop (task 019).
 */
#[AsMessageHandler]
final readonly class HandleDeactivateEmergencyMode
{
    public function __construct(
        private EmergencyModeRepositoryInterface $modes,
        private PlaybackEventPublisherInterface $events,
        private PlaybackQueueDispatcher $dispatcher,
    ) {
    }

    public function __invoke(DeactivateEmergencyMode $command): void
    {
        $mode = $this->modes->current();
        if (!$mode->deactivate()) {
            return;
        }

        $this->modes->save($mode);
        $this->events->publish(EmergencyModeDeactivated::now());
        $this->dispatcher->dispatchNext();
    }
}
