<?php

declare(strict_types=1);

namespace App\Tests\Application\Playback;

use App\Playback\Application\ActivateEmergencyMode;
use App\Playback\Application\DeactivateEmergencyMode;
use App\Playback\Application\HandleActivateEmergencyMode;
use App\Playback\Application\HandleDeactivateEmergencyMode;
use App\Playback\Application\PlaybackQueueDispatcher;
use App\Playback\Domain\Entity\PlaybackJob;
use App\Playback\Domain\Enum\PlaybackJobStatus;
use App\Tests\Application\Playback\Support\InMemoryEmergencyModeRepository;
use App\Tests\Application\Playback\Support\InMemoryPlaybackJobRepository;
use App\Tests\Application\Playback\Support\RecordingAgentCommandPublisher;
use App\Tests\Application\Playback\Support\RecordingPlaybackEventPublisher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class EmergencyModeTest extends TestCase
{
    private InMemoryPlaybackJobRepository $jobs;
    private InMemoryEmergencyModeRepository $modes;
    private RecordingPlaybackEventPublisher $events;
    private RecordingAgentCommandPublisher $agent;

    protected function setUp(): void
    {
        $this->jobs = new InMemoryPlaybackJobRepository();
        $this->modes = new InMemoryEmergencyModeRepository();
        $this->events = new RecordingPlaybackEventPublisher();
        $this->agent = new RecordingAgentCommandPublisher();
    }

    public function testActivationSwitchesTheModeOnAndReportsItOnce(): void
    {
        $handler = new HandleActivateEmergencyMode($this->modes, $this->events);

        $handler($this->activate());
        $handler($this->activate());

        self::assertTrue($this->modes->current()->isActive());
        self::assertSame(['emergency_mode.activated'], $this->events->eventNames());
    }

    public function testDeactivationSwitchesTheModeOffAndReportsItOnce(): void
    {
        new HandleActivateEmergencyMode($this->modes, $this->events)($this->activate());
        $this->events->published = [];
        $handler = new HandleDeactivateEmergencyMode($this->modes, $this->events, $this->dispatcher());

        $handler($this->deactivate());
        $handler($this->deactivate());

        self::assertFalse($this->modes->current()->isActive());
        self::assertSame(['emergency_mode.deactivated'], $this->events->eventNames());
    }

    public function testDeactivatingAnInactiveModeIsANoOp(): void
    {
        new HandleDeactivateEmergencyMode($this->modes, $this->events, $this->dispatcher())($this->deactivate());

        self::assertFalse($this->modes->current()->isActive());
        self::assertSame([], $this->events->published);
    }

    public function testOrdinaryJobDoesNotStartWhileTheModeHoldsAFreeOutput(): void
    {
        $flight = $this->queueJob(priority: 100);
        $this->modes->current()->activate();

        $this->dispatcher()->dispatchNext();

        // The audio output is free, yet an ordinary job is not a candidate at all.
        self::assertSame(PlaybackJobStatus::Pending, $flight->getStatus());
        self::assertSame([], $this->events->published);
        self::assertSame([], $this->agent->published);
    }

    public function testEmergencyJobStartsWhileTheModeHolds(): void
    {
        $emergency = $this->queueJob(priority: PlaybackJob::PREEMPTIVE_PRIORITY);
        $this->queueJob(priority: 100);
        $this->modes->current()->activate();

        $this->dispatcher()->dispatchNext();

        self::assertSame(PlaybackJobStatus::Playing, $emergency->getStatus());
        self::assertSame(['announcement_playback.started'], $this->events->eventNames());
    }

    public function testEmergencyJobStillPreemptsAnOrdinaryOneWhileTheModeHolds(): void
    {
        $flight = $this->queueJob(priority: 100);
        $flight->start();
        $this->queueJob(priority: PlaybackJob::PREEMPTIVE_PRIORITY);
        $this->modes->current()->activate();

        $this->dispatcher()->dispatchNext();

        // Regression of task 025: the guard narrows the candidates, it does not
        // disable preemption.
        self::assertTrue($flight->isPreemptRequested());
        self::assertCount(1, $this->agent->published);
    }

    public function testLiftingTheModeReleasesTheWaitingOrdinaryJob(): void
    {
        $flight = $this->queueJob(priority: 100);
        new HandleActivateEmergencyMode($this->modes, $this->events)($this->activate());
        $this->dispatcher()->dispatchNext();
        self::assertSame(PlaybackJobStatus::Pending, $flight->getStatus());

        new HandleDeactivateEmergencyMode($this->modes, $this->events, $this->dispatcher())($this->deactivate());

        self::assertSame(PlaybackJobStatus::Playing, $flight->getStatus());
        self::assertContains('announcement_playback.started', $this->events->eventNames());
    }

    private function dispatcher(): PlaybackQueueDispatcher
    {
        return new PlaybackQueueDispatcher($this->jobs, $this->events, $this->agent, $this->modes);
    }

    private function activate(): ActivateEmergencyMode
    {
        $messageId = Uuid::v7()->toRfc4122();

        return new ActivateEmergencyMode($messageId, $messageId, '2026-09-23T10:00:00+00:00', 1);
    }

    private function deactivate(): DeactivateEmergencyMode
    {
        $messageId = Uuid::v7()->toRfc4122();

        return new DeactivateEmergencyMode($messageId, $messageId, '2026-09-23T10:30:00+00:00', 1);
    }

    private function queueJob(int $priority): PlaybackJob
    {
        $job = PlaybackJob::queue(
            Uuid::v7()->toRfc4122(),
            Uuid::v7()->toRfc4122(),
            'emergency',
            $priority,
            [[
                'languageCode' => 'ru',
                'sortOrder' => 0,
                'items' => [['type' => 'audio_asset', 'audioAssetId' => Uuid::v7()->toRfc4122()]],
            ]],
        );
        $this->jobs->save($job);

        return $job;
    }
}
