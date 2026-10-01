<?php

declare(strict_types=1);

namespace App\Tests\Unit\Playback\Infrastructure\Messenger;

use App\Playback\Application\IntegrationEvent\PlaybackHeartbeat;
use App\Playback\Domain\Repository\PlaybackJobRepositoryInterface;
use App\Playback\Infrastructure\Messaging\MessengerPlaybackEventPublisher;
use App\Playback\Infrastructure\Messenger\WorkerHeartbeatListener;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Messenger\WorkerMetadata;

final class WorkerHeartbeatListenerTest extends TestCase
{
    /** @var list<object> */
    private array $sent = [];
    private float $clock = 1000.0;

    public function testPublishesTransportsQueueAndPlayingSinceAtMostEveryInterval(): void
    {
        $jobs = $this->createStub(PlaybackJobRepositoryInterface::class);
        $jobs->method('countPending')->willReturn(3);
        $jobs->method('findPlayingSince')->willReturn(new DateTimeImmutable('2026-09-25T10:00:00+00:00'));
        $listener = $this->listener($jobs);
        $event = $this->runningEvent(['emergency_commands']);

        $listener($event);
        $this->clock += 5;
        $listener($event);
        $this->clock += WorkerHeartbeatListener::INTERVAL_SECONDS;
        $listener($event);

        self::assertCount(2, $this->sent);
        $heartbeat = $this->sent[0];
        self::assertInstanceOf(PlaybackHeartbeat::class, $heartbeat);
        self::assertSame(['emergency_commands'], $heartbeat->transports);
        self::assertSame(3, $heartbeat->pendingJobs);
        self::assertSame('2026-09-25T10:00:00+00:00', $heartbeat->playingSince);
    }

    public function testABrokerFailureDoesNotStopTheWorker(): void
    {
        $jobs = $this->createStub(PlaybackJobRepositoryInterface::class);
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new RuntimeException('broker down'));
        $listener = new WorkerHeartbeatListener(
            $jobs,
            new MessengerPlaybackEventPublisher($bus),
            new NullLogger(),
            fn (): float => $this->clock,
        );

        $listener($this->runningEvent(['announcement_playback']));

        $this->addToAssertionCount(1);
    }

    private function listener(PlaybackJobRepositoryInterface $jobs): WorkerHeartbeatListener
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            $this->sent[] = $message;

            return new Envelope($message);
        });

        return new WorkerHeartbeatListener($jobs, new MessengerPlaybackEventPublisher($bus), new NullLogger(), fn (): float => $this->clock);
    }

    /**
     * @param list<string> $transports
     */
    private function runningEvent(array $transports): WorkerRunningEvent
    {
        $worker = $this->createStub(Worker::class);
        $worker->method('getMetadata')->willReturn(new WorkerMetadata(['transportNames' => $transports]));

        return new WorkerRunningEvent($worker, true);
    }
}
