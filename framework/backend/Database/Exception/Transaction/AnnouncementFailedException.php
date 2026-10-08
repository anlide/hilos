<?php

declare(strict_types=1);

namespace Hilos\Database\Exception\Transaction;

use Hilos\Core\Exception\LogicException;
use Hilos\Database\Database;
use Throwable;

/**
 * Exception: announcements a committed transaction held failed when the commit released them.
 *
 * One per commit, and the wrapper always - the framework's own exceptions are wrapped too, so
 * the failure says from its first word that the commit stood and the data is written. Whoever
 * gets it is the end of the handler the commit was made in ({@see Database::handlerEnd()}); outside
 * a handler, the caller of the commit. The first failure is kept in previous.
 */
class AnnouncementFailedException extends LogicException
{
    /**
     * @param int $index Connection the commit stood on
     * @param int $released How many announcements the commit released
     * @param list<Throwable> $failures What the failed ones raised, in the order they were made; never empty
     * @param string $firstFailedAt When the first one failed, as TimeHelper::getTimestampWithMs() gives it
     * @return self Failure of the commit's announcements, naming every one that failed
     */
    public static function forCommit(int $index, int $released, array $failures, string $firstFailedAt): self
    {
        $parts = [];
        foreach ($failures as $failure) {
            $parts[] = get_class($failure) . ' in ' . basename($failure->getFile()) . ':' . $failure->getLine()
                . ' - ' . $failure->getMessage();
        }

        return new self(
            "The commit on connection {$index} stood, but " . count($failures) . " of its {$released} announcements"
                . " failed after it, the first at {$firstFailedAt}: " . implode('; ', $parts),
            previous: $failures[0],
        );
    }
}
