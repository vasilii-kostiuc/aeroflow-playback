<?php

declare(strict_types=1);

namespace App\Playback\Application;

use App\Playback\Domain\Entity\PlaybackJob;
use App\Playback\Domain\Repository\PlaybackJobRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Turns an inbound RequestAnnouncementPlayback into a PlaybackJob.
 *
 * Idempotent by messageId: a redelivered command returns the already-achieved result
 * (the existing job) instead of creating a duplicate. The unique constraint on
 * source_message_id is the last-resort guard against a concurrent redelivery.
 */
#[AsMessageHandler]
final readonly class HandleAnnouncementPlaybackRequest
{
    public function __construct(private PlaybackJobRepositoryInterface $jobs)
    {
    }

    public function __invoke(RequestAnnouncementPlayback $message): void
    {
        if ($this->jobs->findBySourceMessageId(Uuid::fromString($message->messageId)) !== null) {
            return;
        }

        $this->jobs->save(PlaybackJob::queue(
            $message->messageId,
            $message->announcementId,
            $message->type,
            $message->priority,
            $message->audioSequence,
            $message->repeatRule,
        ));
    }
}
