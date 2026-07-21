<?php

declare(strict_types=1);

namespace App\Tests\Application\Playback;

use App\Playback\Application\HandleAnnouncementPlaybackRequest;
use App\Playback\Application\PlaybackQueueDispatcher;
use App\Playback\Application\RequestAnnouncementPlayback;
use App\Playback\Domain\Enum\PlaybackJobStatus;
use App\Tests\Application\Playback\Support\InMemoryPlaybackJobRepository;
use App\Tests\Application\Playback\Support\RecordingAgentCommandPublisher;
use App\Tests\Application\Playback\Support\RecordingPlaybackEventPublisher;
use App\Tests\Application\Playback\Support\RecordingScheduledWakePlanner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class HandleAnnouncementPlaybackRequestTest extends TestCase
{
    private InMemoryPlaybackJobRepository $jobs;
    private RecordingPlaybackEventPublisher $events;
    private RecordingAgentCommandPublisher $agentCommands;
    private RecordingScheduledWakePlanner $wakePlanner;
    private HandleAnnouncementPlaybackRequest $handler;

    protected function setUp(): void
    {
        $this->jobs = new InMemoryPlaybackJobRepository();
        $this->events = new RecordingPlaybackEventPublisher();
        $this->agentCommands = new RecordingAgentCommandPublisher();
        $this->wakePlanner = new RecordingScheduledWakePlanner();
        $dispatcher = new PlaybackQueueDispatcher($this->jobs, $this->events, $this->agentCommands);
        $this->handler = new HandleAnnouncementPlaybackRequest($this->jobs, $this->events, $dispatcher, $this->wakePlanner);
    }

    public function testCreatesJobPublishesQueuedAndStartsItOnIdleQueue(): void
    {
        ($this->handler)($this->request($messageId = Uuid::v7()->toRfc4122()));

        self::assertCount(1, $this->jobs->saved);
        $job = array_values($this->jobs->saved)[0];
        self::assertSame($messageId, $job->getSourceMessageId()->toRfc4122());
        self::assertSame(100, $job->getPriority());
        // The queue was idle, so the dispatcher starts the new job right away.
        self::assertSame(PlaybackJobStatus::Playing, $job->getStatus());
        self::assertSame(
            ['announcement_playback.queued', 'announcement_playback.started'],
            $this->events->eventNames(),
        );
    }

    public function testNewJobWaitsWhileAnotherIsPlaying(): void
    {
        ($this->handler)($this->request(Uuid::v7()->toRfc4122()));
        ($this->handler)($this->request(Uuid::v7()->toRfc4122()));

        [$first, $second] = array_values($this->jobs->saved);
        self::assertSame(PlaybackJobStatus::Playing, $first->getStatus());
        self::assertSame(PlaybackJobStatus::Pending, $second->getStatus());
        // Second request queued but did not start: only one Started was published.
        self::assertSame(
            ['announcement_playback.queued', 'announcement_playback.started', 'announcement_playback.queued'],
            $this->events->eventNames(),
        );
    }

    public function testIsIdempotentForRedeliveredMessageId(): void
    {
        $message = $this->request(Uuid::v7()->toRfc4122());
        ($this->handler)($message);
        ($this->handler)($message);

        self::assertCount(1, $this->jobs->saved);
        // The redelivery neither re-published Queued nor produced a second Started.
        self::assertSame(
            ['announcement_playback.queued', 'announcement_playback.started'],
            $this->events->eventNames(),
        );
    }

    private function request(string $messageId): RequestAnnouncementPlayback
    {
        return new RequestAnnouncementPlayback(
            messageId: $messageId,
            correlationId: $announcementId = Uuid::v7()->toRfc4122(),
            announcementId: $announcementId,
            type: 'check_in_opening',
            priority: 100,
            audioSequence: [[
                'languageCode' => 'ro-MD',
                'sortOrder' => 0,
                'items' => [['type' => 'audio_asset', 'audioAssetId' => Uuid::v7()->toRfc4122()]],
            ]],
            repeatRule: null,
            occurredAt: '2026-06-26T10:00:00+00:00',
            schemaVersion: 1,
        );
    }
}
