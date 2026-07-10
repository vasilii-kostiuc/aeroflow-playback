<?php

declare(strict_types=1);

namespace App\Tests\Application\Playback\Support;

use App\Playback\Application\IntegrationEvent\AnnouncementPlaybackEvent;
use App\Playback\Application\PlaybackEventPublisherInterface;

final class RecordingPlaybackEventPublisher implements PlaybackEventPublisherInterface
{
    /** @var list<AnnouncementPlaybackEvent> */
    public array $published = [];

    public function publish(AnnouncementPlaybackEvent $event): void
    {
        $this->published[] = $event;
    }

    /** @return list<string> the `event` discriminators in publication order */
    public function eventNames(): array
    {
        return array_map(static fn (AnnouncementPlaybackEvent $event): string => $event->event, $this->published);
    }
}
