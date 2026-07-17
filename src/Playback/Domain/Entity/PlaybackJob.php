<?php

declare(strict_types=1);

namespace App\Playback\Domain\Entity;

use App\Playback\Domain\Enum\PlaybackJobStatus;
use App\Playback\Domain\Exception\InvalidPlaybackJobTransition;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Aggregate root of the Playback context. A PlaybackJob is the technical request to
 * play one prepared audio sequence. It carries no airport domain concepts — it never
 * knows about flights, gates or counters, only the neutral sequence it received.
 *
 * Lifecycle: Pending -> Playing -> Completed | Failed, plus Pending -> Cancelled
 * (task 018 — the dispatcher removes a waiting announcement). The queue is strictly
 * sequential — an active job is never interrupted by an ordinary one; only the
 * future emergency mode may interrupt. Scheduled and Interrupted belong to later
 * slices; cancelling a job that already plays is not a cancel but the future stop.
 */
#[ORM\Entity]
#[ORM\Table(name: 'playback_job')]
#[ORM\UniqueConstraint(name: 'UNIQ_PLAYBACK_JOB_SOURCE_MESSAGE', columns: ['source_message_id'])]
class PlaybackJob
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    /**
     * messageId of the integration command that produced this job. Unique, so a
     * redelivered RequestAnnouncementPlayback cannot create a duplicate job.
     */
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $sourceMessageId;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $announcementId;

    #[ORM\Column(length: 32)]
    private string $type;

    #[ORM\Column]
    private int $priority;

    #[ORM\Column(length: 32, enumType: PlaybackJobStatus::class)]
    private PlaybackJobStatus $status;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $scheduledAt;

    /** @var list<array{languageCode:string,sortOrder:int,items:list<array<string,mixed>>}> */
    #[ORM\Column(type: 'json')]
    private array $audioSequence;

    /** @var array<string,mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $repeatRule;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    private function __construct()
    {
    }

    /**
     * @param list<array{languageCode:string,sortOrder:int,items:list<array<string,mixed>>}> $audioSequence
     * @param array<string,mixed>|null                                                       $repeatRule
     */
    public static function queue(
        string $sourceMessageId,
        string $announcementId,
        string $type,
        int $priority,
        array $audioSequence,
        ?array $repeatRule = null,
        ?DateTimeImmutable $scheduledAt = null,
    ): self {
        $job = new self();
        $job->id = Uuid::v7();
        $job->sourceMessageId = Uuid::fromString($sourceMessageId);
        $job->announcementId = Uuid::fromString($announcementId);
        $job->type = $type;
        $job->priority = $priority;
        $job->status = PlaybackJobStatus::Pending;
        $job->audioSequence = $audioSequence;
        $job->repeatRule = $repeatRule;
        $job->scheduledAt = $scheduledAt;
        $job->createdAt = new DateTimeImmutable();

        return $job;
    }

    /**
     * Begin playing this job. Only a Pending job may start (single-active is the
     * dispatcher's and the database's concern; the aggregate guards its own status).
     */
    public function start(): void
    {
        if ($this->status !== PlaybackJobStatus::Pending) {
            throw InvalidPlaybackJobTransition::forJob($this->id, $this->status, 'start');
        }

        $this->status = PlaybackJobStatus::Playing;
    }

    /**
     * Mark the job as played to the end. Idempotent: completing an already
     * Completed job is a no-op and reports false so the caller does not publish
     * the completion event twice.
     */
    public function complete(): bool
    {
        if ($this->status === PlaybackJobStatus::Completed) {
            return false;
        }

        if ($this->status !== PlaybackJobStatus::Playing) {
            throw InvalidPlaybackJobTransition::forJob($this->id, $this->status, 'complete');
        }

        $this->status = PlaybackJobStatus::Completed;

        return true;
    }

    /**
     * Mark the job as failed by the audio output. Idempotent like complete().
     */
    public function fail(): bool
    {
        if ($this->status === PlaybackJobStatus::Failed) {
            return false;
        }

        if ($this->status !== PlaybackJobStatus::Playing) {
            throw InvalidPlaybackJobTransition::forJob($this->id, $this->status, 'fail');
        }

        $this->status = PlaybackJobStatus::Failed;

        return true;
    }

    /**
     * Drop a still-waiting job from the queue. Idempotent like complete(): a second
     * cancel is a no-op reporting false so the caller does not publish the event
     * twice. Only Pending cancels — a Playing or finished job is a late cancel and
     * the application layer's decision, not a valid aggregate transition.
     */
    public function cancel(): bool
    {
        if ($this->status === PlaybackJobStatus::Cancelled) {
            return false;
        }

        if ($this->status !== PlaybackJobStatus::Pending) {
            throw InvalidPlaybackJobTransition::forJob($this->id, $this->status, 'cancel');
        }

        $this->status = PlaybackJobStatus::Cancelled;

        return true;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSourceMessageId(): Uuid
    {
        return $this->sourceMessageId;
    }

    public function getAnnouncementId(): Uuid
    {
        return $this->announcementId;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function getStatus(): PlaybackJobStatus
    {
        return $this->status;
    }

    public function getScheduledAt(): ?DateTimeImmutable
    {
        return $this->scheduledAt;
    }

    /** @return list<array{languageCode:string,sortOrder:int,items:list<array<string,mixed>>}> */
    public function getAudioSequence(): array
    {
        return $this->audioSequence;
    }

    /** @return array<string,mixed>|null */
    public function getRepeatRule(): ?array
    {
        return $this->repeatRule;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
