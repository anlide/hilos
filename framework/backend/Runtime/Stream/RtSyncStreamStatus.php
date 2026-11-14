<?php

declare(strict_types=1);

namespace Hilos\Runtime\Stream;

/**
 * What a receiver answers about one part of an ordinary RT stream.
 *
 * Only a non-final `Next` grants credit: one following part of the same operation. A final part
 * is answered once its operation was applied and its further delivery queued (`Applied`), or never
 * applied at all (`Refused`, `Gap`); neither of the last two is ever read as success.
 */
enum RtSyncStreamStatus: string
{
    case Next = 'next';
    case Applied = 'applied';
    case Refused = 'refused';
    case Gap = 'gap';
}
