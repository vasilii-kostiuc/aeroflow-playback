<?php

declare(strict_types=1);

namespace App\Tests\Application\Playback;

use App\Playback\Application\PlaybackQueueDispatcher;
use App\Playback\Domain\Entity\PlaybackJob;
use App\Playback\Domain\Enum\PlaybackJobStatus;
use App\Tests\Application\Playback\Support\InMemoryPlaybackJobRepository;
use App\Tests\Application\Playback\Support\RecordingAgentCommandPublisher;
use App\Tests\Application\Playback\Support\RecordingPlaybackEventPublisher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class PlaybackQueueDispatcherTest extends TestCase
{
    public function testStartsHighestPriorityPendingJob(): void
    {
        $jobs = new InMemoryPlaybackJobRepository();
        $events = new RecordingPlaybackEventPublisher();
        $agent = new RecordingAgentCommandPublisher();
        $additional = $this->queueJob($jobs, priority: 50);
        $flight = $this->queueJob($jobs, priority: 100);

        new PlaybackQueueDispatcher($jobs, $events, $agent)->dispatchNext();

        self::assertSame(PlaybackJobStatus::Playing, $flight->getStatus());
        self::assertSame(PlaybackJobStatus::Pending, $additional->getStatus());
        self::assertSame(['announcement_playback.started'], $events->eventNames());
        self::assertSame($flight->getId()->toRfc4122(), $events->published[0]->jobId);
        // The started job is sent to the audio agent with its sequence as is (016).
        self::assertCount(1, $agent->published);
        self::assertSame($flight->getId()->toRfc4122(), $agent->published[0]->jobId);
        self::assertSame($flight->getAudioSequence(), $agent->published[0]->audioSequence);
    }

    public function testEqualPriorityIsServedFifo(): void
    {
        $jobs = new InMemoryPlaybackJobRepository();
        $events = new RecordingPlaybackEventPublisher();
        $agent = new RecordingAgentCommandPublisher();
        $first = $this->queueJob($jobs, priority: 100);
        $second = $this->queueJob($jobs, priority: 100);

        new PlaybackQueueDispatcher($jobs, $events, $agent)->dispatchNext();

        self::assertSame(PlaybackJobStatus::Playing, $first->getStatus());
        self::assertSame(PlaybackJobStatus::Pending, $second->getStatus());
    }

    public function testDoesNothingWhileAJobIsPlaying(): void
    {
        $jobs = new InMemoryPlaybackJobRepository();
        $events = new RecordingPlaybackEventPublisher();
        $agent = new RecordingAgentCommandPublisher();
        $active = $this->queueJob($jobs, priority: 50);
        $active->start();
        $waiting = $this->queueJob($jobs, priority: 100);

        new PlaybackQueueDispatcher($jobs, $events, $agent)->dispatchNext();

        // A higher-priority ordinary announcement never interrupts the active one.
        self::assertSame(PlaybackJobStatus::Playing, $active->getStatus());
        self::assertSame(PlaybackJobStatus::Pending, $waiting->getStatus());
        self::assertSame([], $events->published);
    }

    public function testDoesNothingOnEmptyQueue(): void
    {
        $jobs = new InMemoryPlaybackJobRepository();
        $events = new RecordingPlaybackEventPublisher();
        $agent = new RecordingAgentCommandPublisher();

        new PlaybackQueueDispatcher($jobs, $events, $agent)->dispatchNext();

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
