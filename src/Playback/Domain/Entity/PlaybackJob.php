<?php

declare(strict_types=1);

namespace App\Playback\Domain\Entity;

use App\Playback\Domain\Enum\PlaybackJobStatus;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Aggregate root of the Playback context. A PlaybackJob is the technical request to
 * play one prepared audio sequence. It carries no airport domain concepts — it never
 * knows about flights, gates or counters, only the neutral sequence it received.
 *
 * First slice: jobs are created in the Pending state from RequestAnnouncementPlayback.
 * The priority queue, interruption and emergency mode arrive in later slices.
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
