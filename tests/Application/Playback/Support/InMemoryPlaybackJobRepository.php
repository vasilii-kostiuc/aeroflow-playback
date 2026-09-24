<?php

declare(strict_types=1);

namespace App\Tests\Application\Playback\Support;

use App\Playback\Domain\Entity\PlaybackJob;
use App\Playback\Domain\Enum\PlaybackJobStatus;
use App\Playback\Domain\Repository\PlaybackJobRepositoryInterface;
use Symfony\Component\Uid\Uuid;

final class InMemoryPlaybackJobRepository implements PlaybackJobRepositoryInterface
{
    /** @var array<string,PlaybackJob> keyed by job id */
    public array $saved = [];

    public function save(PlaybackJob $job): void
    {
        $this->saved[$job->getId()->toRfc4122()] = $job;
    }

    public function findById(Uuid $id): ?PlaybackJob
    {
        return $this->saved[$id->toRfc4122()] ?? null;
    }

    public function findBySourceMessageId(Uuid $sourceMessageId): ?PlaybackJob
    {
        foreach ($this->saved as $job) {
            if ($job->getSourceMessageId()->equals($sourceMessageId)) {
                return $job;
            }
        }

        return null;
    }

    public function findActive(): ?PlaybackJob
    {
        foreach ($this->saved as $job) {
            if ($job->getStatus() === PlaybackJobStatus::Playing) {
                return $job;
            }
        }

        return null;
    }

    public function findNextPendingForUpdate(?int $minPriority = null): ?PlaybackJob
    {
        $pending = array_values(array_filter(
            $this->saved,
            static fn (PlaybackJob $job): bool => $job->getStatus() === PlaybackJobStatus::Pending
                && ($minPriority === null || $job->getPriority() >= $minPriority),
        ));

        // Same order as the Doctrine implementation: priority desc, createdAt asc,
        // id asc (UUIDv7 ids are time-ordered, so this is a stable FIFO tie-break).
        usort($pending, static fn (PlaybackJob $a, PlaybackJob $b): int => [-$a->getPriority(), $a->getCreatedAt(), $a->getId()->toRfc4122()]
            <=> [-$b->getPriority(), $b->getCreatedAt(), $b->getId()->toRfc4122()]);

        return $pending[0] ?? null;
    }

    public function findLatestByAnnouncementIdForUpdate(Uuid $announcementId): ?PlaybackJob
    {
        $matching = array_values(array_filter(
            $this->saved,
            static fn (PlaybackJob $job): bool => $job->getAnnouncementId()->equals($announcementId),
        ));

        // Same order as the Doctrine implementation: newest first.
        usort($matching, static fn (PlaybackJob $a, PlaybackJob $b): int => [$b->getCreatedAt(), $b->getId()->toRfc4122()]
            <=> [$a->getCreatedAt(), $a->getId()->toRfc4122()]);

        return $matching[0] ?? null;
    }
}
