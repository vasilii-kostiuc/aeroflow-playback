<?php

declare(strict_types=1);

namespace App\Playback\Application\Agent;

use App\Playback\Domain\Entity\PlaybackJob;
use DateTimeImmutable;
use DateTimeInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Outbound integration command to aeroflow-agent (queue agent_commands): play the
 * prepared audio sequence of one job. Neutral contract with the `command`
 * discriminator in the body from day one (ADR 002) — Stop/Pause/Resume/SetVolume
 * will share this queue in later slices.
 */
final readonly class PlayAudioSequence
{
    public const string COMMAND = 'audio.play_sequence';

    /**
     * @param list<array{languageCode:string,sortOrder:int,items:list<array<string,mixed>>}> $audioSequence
     */
    private function __construct(
        public string $messageId,
        public string $jobId,
        public array $audioSequence,
        public string $occurredAt,
        public int $schemaVersion,
    ) {
    }

    public static function for(PlaybackJob $job): self
    {
        return new self(
            messageId: Uuid::v7()->toRfc4122(),
            jobId: $job->getId()->toRfc4122(),
            audioSequence: $job->getAudioSequence(),
            occurredAt: new DateTimeImmutable()->format(DateTimeInterface::ATOM),
            schemaVersion: 1,
        );
    }
}
