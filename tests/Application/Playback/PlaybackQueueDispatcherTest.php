<?php

declare(strict_types=1);

namespace App\Tests\Application\Playback;

use App\Playback\Application\PlaybackQueueDispatcher;
use App\Playback\Domain\Entity\PlaybackJob;
use App\Playback\Domain\Enum\PlaybackJobStatus;
use App\Tests\Application\Playback\Support\InMemoryPlaybackJobRepository;
use App\Tests\Application\Playback\Support\RecordingPlaybackEventPublisher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class PlaybackQueueDispatcherTest extends TestCase
{
    public function testStartsHighestPriorityPendingJob(): void
    {
        $jobs = new InMemoryPlaybackJobRepository();
        $events = new RecordingPlaybackEventPublisher();
        $additional = $this->queueJob($jobs, priority: 50);
        $flight = $this->queueJob($jobs, priority: 100);

        new PlaybackQueueDispatcher($jobs, $events)->dispatchNext();

        self::assertSame(PlaybackJobStatus::Playing, $flight->getStatus());
        self::assertSame(PlaybackJobStatus::Pending, $additional->getStatus());
        self::assertSame(['announcement_playback.started'], $events->eventNames());
        self::assertSame($flight->getId()->toRfc4122(), $events->published[0]->jobId);
    }

    public function testEqualPriorityIsServedFifo(): void
    {
        $jobs = new InMemoryPlaybackJobRepository();
        $events = new RecordingPlaybackEventPublisher();
        $first = $this->queueJob($jobs, priority: 100);
        $second = $this->queueJob($jobs, priority: 100);

        new PlaybackQueueDispatcher($jobs, $events)->dispatchNext();

        self::assertSame(PlaybackJobStatus::Playing, $first->getStatus());
        self::assertSame(PlaybackJobStatus::Pending, $second->getStatus());
    }

    public function testDoesNothingWhileAJobIsPlaying(): void
    {
        $jobs = new InMemoryPlaybackJobRepository();
        $events = new RecordingPlaybackEventPublisher();
        $active = $this->queueJob($jobs, priority: 50);
        $active->start();
        $waiting = $this->queueJob($jobs, priority: 100);

        new PlaybackQueueDispatcher($jobs, $events)->dispatchNext();

        // A higher-priority ordinary announcement never interrupts the active one.
        self::assertSame(PlaybackJobStatus::Playing, $active->getStatus());
        self::assertSame(PlaybackJobStatus::Pending, $waiting->getStatus());
        self::assertSame([], $events->published);
    }

    public function testDoesNothingOnEmptyQueue(): void
    {
        $jobs = new InMemoryPlaybackJobRepository();
        $events = new RecordingPlaybackEventPublisher();

        new PlaybackQueueDispatcher($jobs, $events)->dispatchNext();

        self::assertSame([], $events->published);
    }

    private function queueJob(InMemoryPlaybackJobRepository $jobs, int $priority): PlaybackJob
    {
        $job = PlaybackJob::queue(
            Uuid::v7()->toRfc4122(),
            Uuid::v7()->toRfc4122(),
            'check_in_opening',
            $priority,
            [[
                'languageCode' => 'ro-MD',
                'sortOrder' => 0,
                'items' => [['type' => 'audio_asset', 'audioAssetId' => Uuid::v7()->toRfc4122()]],
            ]],
        );
        $jobs->save($job);

        return $job;
    }
}
