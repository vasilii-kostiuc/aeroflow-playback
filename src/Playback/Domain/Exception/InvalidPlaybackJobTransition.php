<?php

declare(strict_types=1);

namespace App\Playback\Domain\Exception;

use App\Playback\Domain\Enum\PlaybackJobStatus;
use DomainException;
use Symfony\Component\Uid\Uuid;

/**
 * A PlaybackJob was asked to make a lifecycle transition its current status does
 * not allow (e.g. starting a job that is not Pending, or completing one that never
 * started). Idempotent re-completion of an already terminal job is NOT an error and
 * never raises this exception.
 */
final class InvalidPlaybackJobTransition extends DomainException
{
    public static function forJob(Uuid $jobId, PlaybackJobStatus $from, string $transition): self
    {
        return new self(sprintf(
            'Playback job %s cannot %s from status "%s".',
            $jobId->toRfc4122(),
            $transition,
            $from->value,
        ));
    }
}
