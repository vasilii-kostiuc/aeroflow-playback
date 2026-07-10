<?php

declare(strict_types=1);

namespace App\Playback\Application;

use App\Playback\Application\IntegrationEvent\AnnouncementPlaybackEvent;

/**
 * Publishes outbound integration events to aeroflow-core.
 *
 * Implementations must honour the post-commit rule: an event raised inside a
 * command transaction is released only after that transaction commits, and is
 * discarded on rollback (same compromise as Core — durable outbox is deferred).
 */
interface PlaybackEventPublisherInterface
{
    public function publish(AnnouncementPlaybackEvent $event): void;
}
