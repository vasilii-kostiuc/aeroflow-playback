<?php

declare(strict_types=1);

namespace App\Playback\Infrastructure\Messenger;

use App\Playback\Application\IntegrationEvent\PlaybackHeartbeat;
use App\Playback\Domain\Repository\PlaybackJobRepositoryInterface;
use App\Playback\Infrastructure\Messaging\MessengerPlaybackEventPublisher;
use Closure;
use DateTimeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Throwable;

/**
 * Publishes a PlaybackHeartbeat for this worker process (task 034). The worker
 * loop fires WorkerRunningEvent about every second, busy or idle; the heartbeat
 * goes out at most every INTERVAL seconds.
 *
 * A heartbeat is a report, never a reason to stop working: a broker or database
 * failure here is logged and the worker carries on.
 */
#[AsEventListener(event: WorkerRunningEvent::class)]
final class WorkerHeartbeatListener
{
    public const int INTERVAL_SECONDS = 15;

    private ?float $lastSentAt = null;

    /** @var Closure(): float */
    private Closure $now;

    public function __construct(
        private readonly PlaybackJobRepositoryInterface $jobs,
        private readonly MessengerPlaybackEventPublisher $publisher,
        private readonly LoggerInterface $logger,
        ?Closure $now = null,
    ) {
        $this->now = $now ?? static fn (): float => microtime(true);
    }

    public function __invoke(WorkerRunningEvent $event): void
    {
        $now = ($this->now)();
        if (null !== $this->lastSentAt && $now - $this->lastSentAt < self::INTERVAL_SECONDS) {
            return;
        }
        $this->lastSentAt = $now;

        try {
            $this->publisher->publish(new PlaybackHeartbeat(
                array_values($event->getWorker()->getMetadata()->getTransportNames()),
                $this->jobs->countPending(),
                $this->jobs->findPlayingSince()?->format(DateTimeInterface::ATOM),
            ));
        } catch (Throwable $exception) {
            $this->logger->warning('Playback heartbeat was not published: {message}', [
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);
        }
    }
}
