<?php

declare(strict_types=1);

namespace App\Playback\Domain\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Aggregate root holding the one global emergency mode of this audio output (task
 * 026). While it is active the ordinary queue is suspended: jobs below
 * PlaybackJob::PREEMPTIVE_PRIORITY are not started even when the output is free.
 *
 * Persistent, not an in-memory flag: an emergency that outlives a worker restart
 * must still hold the queue. The mode is global and singular, so the row has one
 * fixed identity rather than a history of rows — the history of what sounded lives
 * in PlaybackJob.
 *
 * Playback still knows nothing about airports: "emergency" here means only "the
 * reserved priority level is in force", exactly as PREEMPTIVE_PRIORITY means only a
 * reserved number.
 */
#[ORM\Entity]
#[ORM\Table(name: 'emergency_mode')]
class EmergencyMode
{
    public const string SINGLETON_ID = '01990000-0000-7000-8000-0000000000e1';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column]
    private bool $active;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $activatedAt;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $deactivatedAt;

    private function __construct()
    {
    }

    /** The mode before anything ever activated it. */
    public static function inactive(): self
    {
        $mode = new self();
        $mode->id = Uuid::fromString(self::SINGLETON_ID);
        $mode->active = false;
        $mode->activatedAt = null;
        $mode->deactivatedAt = null;

        return $mode;
    }

    /** Idempotent: activating an already active mode reports false and changes nothing. */
    public function activate(): bool
    {
        if ($this->active) {
            return false;
        }

        $this->active = true;
        $this->activatedAt = new DateTimeImmutable();

        return true;
    }

    /** Idempotent, like activate(): lifting an inactive mode reports false. */
    public function deactivate(): bool
    {
        if (!$this->active) {
            return false;
        }

        $this->active = false;
        $this->deactivatedAt = new DateTimeImmutable();

        return true;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getActivatedAt(): ?DateTimeImmutable
    {
        return $this->activatedAt;
    }

    public function getDeactivatedAt(): ?DateTimeImmutable
    {
        return $this->deactivatedAt;
    }
}
