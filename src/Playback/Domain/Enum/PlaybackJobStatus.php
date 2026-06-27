<?php

declare(strict_types=1);

namespace App\Playback\Domain\Enum;

enum PlaybackJobStatus: string
{
    case Pending = 'pending';
    case Scheduled = 'scheduled';
    case Playing = 'playing';
    case Completed = 'completed';
    case Interrupted = 'interrupted';
    case Cancelled = 'cancelled';
    case Failed = 'failed';
}
