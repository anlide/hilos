<?php

declare(strict_types=1);

namespace Hilos\Core\Source;

/**
 * Whether this process authored a source change or merely applied someone else's.
 *
 * Deliberately separate from {@see SourceChange::$origin}, which names the accept key of the
 * connection whose write caused the change. That one answers "which browser is responsible for
 * this row" and travels over the wire; this one answers "did this process write it at all",
 * never leaves the process, and is the single thing that decides whether a change is broadcast
 * onward. Folding the two together would make an applied remote write look local and send it
 * back where it came from.
 */
enum SourceChangeProvenance
{
    case LocalWrite;
    case AppliedRemote;
}
