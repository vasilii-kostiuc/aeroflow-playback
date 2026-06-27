<?php

declare(strict_types=1);

namespace App\Playback\Domain\Repository;

use App\Playback\Domain\Entity\PlaybackJob;
use Symfony\Component\Uid\Uuid;

interface PlaybackJobRepositoryInterface
{
    public function save(PlaybackJob $job): void;

    /**
     * Looks up a job by the integration command messageId that produced it, so a
     * redelivered RequestAnnouncementPlayback is recognised and not handled twice.
     */
    public function findBySourceMessageId(Uuid $sourceMessageId): ?PlaybackJob;
}
