<?php

declare(strict_types=1);

namespace App\Playback\Application;

use App\Playback\Application\IntegrationEvent\EmergencyModeActivated;
use App\Playback\Domain\Repository\EmergencyModeRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Switches emergency mode on (task 026). Idempotent like every other inbound
 * command: a redelivery — or a second emergency announcement launched while the
 * mode already holds — changes nothing and reports nothing back.
 *
 * The queue is not advanced here: activation frees no audio output. The emergency
 * announcement itself arrives as an ordinary request at priority 1000 and takes the
 * output through the usual dispatch (preempting the active job if one is sounding).
 */
#[AsMessageHandler]
final readonly class HandleActivateEmergencyMode
{
    public function __construct(
        private EmergencyModeRepositoryInterface $modes,
        private PlaybackEventPublisherInterface $events,
    ) {
    }

    public function __invoke(ActivateEmergencyMode $command): void
    {
        $mode = $this->modes->current();
        if (!$mode->activate()) {
            return;
        }

        $this->modes->save($mode);
        $this->events->publish(EmergencyModeActivated::now());
    }
}
