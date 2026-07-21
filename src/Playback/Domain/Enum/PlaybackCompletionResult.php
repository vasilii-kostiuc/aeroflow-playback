<?php

declare(strict_types=1);

namespace App\Playback\Domain\Enum;

/**
 * The outcome of completing a PlaybackJob run.
 *
 * A one-shot job simply completes. A repeatable job (task 020) re-arms for its next
 * tick instead of ending, unless its series was stopped. NoOp reports an idempotent
 * redelivery — the job was not currently playing, so nothing changed.
 */
enum PlaybackCompletionResult
{
    case NoOp;
    case Completed;
    case Rearmed;
}
