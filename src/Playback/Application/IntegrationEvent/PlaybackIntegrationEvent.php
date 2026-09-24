<?php

declare(strict_types=1);

namespace App\Playback\Application\IntegrationEvent;

/**
 * Marker of an outbound fact published back to aeroflow-core over playback_events.
 *
 * Two families implement it: the per-job AnnouncementPlaybackEvent and the global
 * EmergencyModeEvent (task 026), which carries no job at all. They share a queue and
 * a publisher, and the consumer tells them apart by the `event` field in the body —
 * never by these PHP class names.
 */
interface PlaybackIntegrationEvent
{
    /** The `event` discriminator this fact carries in the contract body. */
    public function eventName(): string;
}
