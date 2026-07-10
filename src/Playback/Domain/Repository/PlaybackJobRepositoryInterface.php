<?php

declare(strict_types=1);

namespace App\Playback\Domain\Repository;

use App\Playback\Domain\Entity\PlaybackJob;
use Symfony\Component\Uid\Uuid;

interface PlaybackJobRepositoryInterface
{
    public function save(PlaybackJob $job): void;

    public function findById(Uuid $id): ?PlaybackJob;

    /**
     * Looks up a job by the integration command messageId that produced it, so a
     * redelivered RequestAnnouncementPlayback is recognised and not handled twice.
     */
    public function findBySourceMessageId(Uuid $sourceMessageId): ?PlaybackJob;

    /**
     * The single currently Playing job, if any. The queue is strictly sequential:
     * at most one job plays per audio output (one global output in this version).
     */
    public function findActive(): ?PlaybackJob;

    /**
     * The next Pending job in queue order — priority descending, then FIFO by
     * createdAt (id as the final tie-break). Loaded with a write lock so two
     * concurrent dispatch attempts cannot both start it; the caller must therefore
     * run inside a transaction.
     */
    public function findNextPendingForUpdate(): ?PlaybackJob;
}
