<?php

declare(strict_types=1);

namespace App\Tests\Application\Playback;

use App\Playback\Application\HandleWakeScheduledPlaybackJob;
use App\Playback\Application\PlaybackQueueDispatcher;
use App\Playback\Application\WakeScheduledPlaybackJob;
use App\Playback\Domain\Entity\PlaybackJob;
use App\Playback\Domain\Enum\PlaybackJobStatus;
use App\Tests\Application\Playback\Support\InMemoryEmergencyModeRepository;
use App\Tests\Application\Playback\Support\InMemoryPlaybackJobRepository;
use App\Tests\Application\Playback\Support\RecordingAgentCommandPublisher;
use App\Tests\Application\Playback\Support\RecordingPlaybackEventPublisher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class HandleWakeScheduledPlaybackJobTest extends TestCase
{
    private InMemoryPlaybackJobRepository $jobs;
    private RecordingPlaybackEventPublisher $events;
    private HandleWakeScheduledPlaybackJob $handler;

    protected function setUp(): void
    {
        $this->jobs = new InMemoryPlaybackJobRepository();
        $this->events = new RecordingPlaybackEventPublisher();
        $dispatcher = new PlaybackQueueDispatcher($this->jobs, $this->events, new RecordingAgentCommandPublisher(), new InMemoryEmergencyModeRepository());
        $this->handler = new HandleWakeScheduledPlaybackJob($this->jobs, $dispatcher);
    }

    public function testWakeStartsTheDueTickOnIdleQueue(): void
    {
        $job = $this->repeatableJob();
        $this->jobs->save($job);

        ($this->handler)(new WakeScheduledPlaybackJob($job->getId()->toRfc4122()));

        // Scheduled -> Pending -> started by the idle dispatcher.
        self::assertSame(PlaybackJobStatus::Playing, $job->getStatus());
        self::assertSame(['announcement_playback.started'], $this->events->eventNames());
    }

    public function testStaleWakeForTerminatedJobIsNoOp(): void
    {
        $job = $this->repeatableJob();
        $job->cancelRepeat(); // Scheduled -> Completed
        $this->jobs->save($job);

        ($this->handler)(new WakeScheduledPlaybackJob($job->getId()->toRfc4122()));

        self::assertSame(PlaybackJobStatus::Completed, $job->getStatus());
        self::assertSame([], $this->events->eventNames());
    }

    public function testUnknownJobIsNoOp(): void
    {
        ($this->handler)(new WakeScheduledPlaybackJob(Uuid::v7()->toRfc4122()));

        self::assertSame([], $this->events->eventNames());
    }

    private function repeatableJob(): PlaybackJob
    {
        return PlaybackJob::queue(
            Uuid::v7()->toRfc4122(),
            Uuid::v7()->toRfc4122(),
            'check_in_continuation',
            100,
            [[
                'languageCode' => 'ro-MD',
                'sortOrder' => 0,
                'items' => [['type' => 'audio_asset', 'audioAssetId' => Uuid::v7()->toRfc4122()]],
            ]],
            ['everyMinutes' => 5],
        );
    }
}
