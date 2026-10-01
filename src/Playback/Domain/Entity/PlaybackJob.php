<?php

declare(strict_types=1);

namespace App\Playback\Domain\Entity;

use App\Playback\Domain\Enum\PlaybackCompletionResult;
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
 * Lifecycle: Pending -> Playing -> Completed | Failed | Interrupted, plus Pending -> Cancelled
 * (task 018 — the dispatcher removes a waiting announcement). Interrupted is the
 * operator's stop of the currently sounding job (task 019): the agent confirms the
 * physical stop and only then the aggregate leaves Playing. The queue is strictly
 * sequential — an active job is never interrupted by an ordinary one; only the
 * future emergency mode may preempt.
 *
 * A repeatable job (task 020 — "продолжение регистрации") carries a repeatRule with
 * everyMinutes. It is born Scheduled (first tick one interval later) and, instead of
 * ending, re-arms on each completion: Playing -> Scheduled (scheduledAt += interval)
 * -> [delayed wake] Pending -> Playing -> ... The series runs until Core stops it via
 * cancelRepeat(): the current tick finishes, then the job terminates in Completed.
 * Playback never learns why — it only cycles the job until told to stop.
 *
 * Task 025 gives the queue dispatcher the ability to preempt an active job with a
 * job at or above PREEMPTIVE_PRIORITY: Playing -> Preempted -> Pending. Preempted is
 * transient, not terminal — the same application handler that marks a job Preempted
 * immediately requeues it, so it is never observed as a persisted row state; the
 * physical stop reuses the exact audio.stop/audio.playback_interrupted contract task
 * 019 already established, only the aggregate's own interpretation of the agent's
 * confirmation differs (Preempted+requeue instead of the operator's terminal
 * Interrupted). Priority and createdAt are untouched by requeue, so the job keeps its
 * place in its tier's FIFO order instead of being penalised for being preempted.
 */
#[ORM\Entity]
#[ORM\Table(name: 'playback_job')]
#[ORM\UniqueConstraint(name: 'UNIQ_PLAYBACK_JOB_SOURCE_MESSAGE', columns: ['source_message_id'])]
class PlaybackJob
{
    /**
     * Only a job at or above this priority may preempt an already-Playing job (see
     * PlaybackQueueDispatcher). Ordinary priorities (100/100/50/0 — flight,
     * continuation, supplementary, background music) never preempt each other or one
     * another; the queue is strictly sequential for them. Playback does not need to
     * know the word "emergency" — only this reserved threshold, defined by the
     * priority table in README.md.
     */
    public const int PREEMPTIVE_PRIORITY = 1000;
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

    /**
     * When the current (or last) play began — set on every Pending -> Playing, so a
     * repeatable job carries its current tick. Reported in the worker heartbeat
     * (task 034): a job playing for far too long means the agent likely hung.
     */
    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $startedAt = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $stopRequested = false;

    /**
     * Set by the queue dispatcher when it decides to preempt this Playing job with a
     * higher, at-or-above-PREEMPTIVE_PRIORITY one (task 025). Mutually exclusive with
     * stopRequested — whichever request lands first on a job wins; the two never
     * apply to the same physical stop.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $preemptRequested = false;

    /**
     * Set when Core ends the repeat series (task 020). The current tick still plays
     * to the end; the next completion terminates the job instead of re-arming.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $repeatCancelled = false;

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
        $job->audioSequence = $audioSequence;
        $job->repeatRule = $repeatRule;
        $job->createdAt = new DateTimeImmutable();

        // A repeatable job starts asleep: its first tick sounds one interval after
        // opening, not together with the one-shot opening announcement. A one-shot
        // job is Pending immediately (scheduledAt stays whatever was requested).
        $everyMinutes = $job->repeatEveryMinutes();
        if ($everyMinutes !== null) {
            $job->status = PlaybackJobStatus::Scheduled;
            $job->scheduledAt = $job->createdAt->modify(sprintf('+%d minutes', $everyMinutes));
        } else {
            $job->status = PlaybackJobStatus::Pending;
            $job->scheduledAt = $scheduledAt;
        }

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
        $this->startedAt = new DateTimeImmutable();
    }

    /**
     * Wake a Scheduled job for its next tick (task 020): Scheduled -> Pending. The
     * delayed queue re-evaluation calls this. Idempotent — a job that is not
     * Scheduled (already Pending, Playing or terminal) is a no-op reporting false.
     */
    public function wake(): bool
    {
        if ($this->status !== PlaybackJobStatus::Scheduled) {
            return false;
        }

        $this->status = PlaybackJobStatus::Pending;

        return true;
    }

    /**
     * Report the active run played to the end.
     *
     * A one-shot job terminates in Completed. A repeatable job re-arms for its next
     * tick (Playing -> Scheduled, scheduledAt += interval) unless its series was
     * stopped, in which case it terminates in Completed like a one-shot.
     *
     * Idempotent: re-completing an already Completed job is a NoOp. A repeatable job
     * found between ticks (Scheduled/Pending) is a redelivery of the previous tick's
     * completion — also a NoOp — because a genuine new completion only arrives while
     * the job is Playing.
     */
    public function complete(): PlaybackCompletionResult
    {
        if ($this->status === PlaybackJobStatus::Completed) {
            return PlaybackCompletionResult::NoOp;
        }

        if ($this->isRepeatable()
            && ($this->status === PlaybackJobStatus::Scheduled || $this->status === PlaybackJobStatus::Pending)) {
            return PlaybackCompletionResult::NoOp;
        }

        if ($this->status !== PlaybackJobStatus::Playing) {
            throw InvalidPlaybackJobTransition::forJob($this->id, $this->status, 'complete');
        }

        $everyMinutes = $this->repeatEveryMinutes();
        if ($everyMinutes !== null && !$this->repeatCancelled) {
            $this->scheduledAt = (new DateTimeImmutable())->modify(sprintf('+%d minutes', $everyMinutes));
            $this->status = PlaybackJobStatus::Scheduled;

            return PlaybackCompletionResult::Rearmed;
        }

        $this->status = PlaybackJobStatus::Completed;

        return PlaybackCompletionResult::Completed;
    }

    /**
     * Core ends the repeat series (task 020). Neutral, not a physical stop: a Playing
     * tick keeps sounding and terminates on its next completion; a job resting between
     * ticks (Scheduled/Pending) terminates now. Idempotent and only meaningful for a
     * repeatable job — a one-shot or an already-stopped/terminal job reports false.
     */
    public function cancelRepeat(): bool
    {
        if (!$this->isRepeatable() || $this->repeatCancelled) {
            return false;
        }

        if ($this->status !== PlaybackJobStatus::Playing
            && $this->status !== PlaybackJobStatus::Scheduled
            && $this->status !== PlaybackJobStatus::Pending) {
            return false;
        }

        $this->repeatCancelled = true;

        if ($this->status !== PlaybackJobStatus::Playing) {
            $this->status = PlaybackJobStatus::Completed;
        }

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

    /** The operator requested a physical stop; the agent still confirms interruption. */
    public function requestStop(): bool
    {
        if ($this->status !== PlaybackJobStatus::Playing || $this->stopRequested || $this->preemptRequested) {
            return false;
        }

        $this->stopRequested = true;

        return true;
    }

    /**
     * The queue dispatcher requested a preemptive physical stop in favour of a
     * higher-priority pending job (task 025); the agent still confirms interruption
     * via the same audio.stop/audio.playback_interrupted contract as an operator
     * stop. Mutually exclusive with an operator stop already in flight.
     */
    public function requestPreempt(): bool
    {
        if ($this->status !== PlaybackJobStatus::Playing || $this->stopRequested || $this->preemptRequested) {
            return false;
        }

        $this->preemptRequested = true;

        return true;
    }

    public function isPreemptRequested(): bool
    {
        return $this->preemptRequested;
    }

    /** Mark the job interrupted by the audio agent. */
    public function interrupt(): bool
    {
        if ($this->status === PlaybackJobStatus::Interrupted) {
            return false;
        }

        if ($this->status !== PlaybackJobStatus::Playing) {
            throw InvalidPlaybackJobTransition::forJob($this->id, $this->status, 'interrupt');
        }

        $this->status = PlaybackJobStatus::Interrupted;

        return true;
    }

    /**
     * Mark the job preempted by the audio agent's confirmation of the preemptive stop
     * (task 025). Transient, not terminal: the caller is expected to requeue() it
     * immediately afterwards, in the same handler — Preempted is never meant to
     * outlive that one application-layer call.
     */
    public function preempt(): bool
    {
        if ($this->status === PlaybackJobStatus::Preempted) {
            return false;
        }

        if ($this->status !== PlaybackJobStatus::Playing) {
            throw InvalidPlaybackJobTransition::forJob($this->id, $this->status, 'preempt');
        }

        $this->status = PlaybackJobStatus::Preempted;

        return true;
    }

    /**
     * Return a preempted job to the back of the dispatcher's Pending selection —
     * "back" only in the sense of leaving Preempted; priority and createdAt are
     * untouched, so the job keeps its original place in its tier's FIFO order and is
     * not penalised for having been preempted. Clears preemptRequested so the job can
     * be preempted (or stopped) again next time it plays.
     */
    public function requeue(): bool
    {
        if ($this->status !== PlaybackJobStatus::Preempted) {
            return false;
        }

        $this->status = PlaybackJobStatus::Pending;
        $this->preemptRequested = false;

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

    /** The repeat interval in minutes, or null when the job is one-shot. */
    public function repeatEveryMinutes(): ?int
    {
        $everyMinutes = $this->repeatRule['everyMinutes'] ?? null;

        return is_int($everyMinutes) && $everyMinutes > 0 ? $everyMinutes : null;
    }

    public function isRepeatable(): bool
    {
        return $this->repeatEveryMinutes() !== null;
    }

    public function isRepeatCancelled(): bool
    {
        return $this->repeatCancelled;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getStartedAt(): ?DateTimeImmutable
    {
        return $this->startedAt;
    }
}
