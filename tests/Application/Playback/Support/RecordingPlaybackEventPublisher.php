<?php

declare(strict_types=1);

namespace App\Tests\Application\Playback\Support;

use App\Playback\Application\IntegrationEvent\PlaybackIntegrationEvent;
use App\Playback\Application\PlaybackEventPublisherInterface;

final class RecordingPlaybackEventPublisher implements PlaybackEventPublisherInterface
{
    /** @var list<PlaybackIntegrationEvent> */
    public array $published = [];

    public function publish(PlaybackIntegrationEvent $event): void
    {
        $this->published[] = $event;
    }

    /** @return list<string> the `event` discriminators in publication order */
    public function eventNames(): array
    {
        return array_map(static fn (PlaybackIntegrationEvent $event): string => $event->eventName(), $this->published);
    }
}
