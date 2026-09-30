<?php

declare(strict_types=1);

namespace Hilos\Database\Exception\Transaction;

use Hilos\Core\Exception\LogicException;
use Throwable;

/**
 * Exception: an announcement a transaction held raised something outside the framework's
 * exception tree when the commit released it.
 *
 * The framework's own announcements raise Hilos exceptions and reach the caller of the commit
 * as they are; this wraps the rest - an Error, a foreign exception out of a project's
 * afterCommit() closure - so the commit keeps the one contract its callers catch by. The
 * original failure is kept in previous.
 */
class AnnouncementFailedException extends LogicException
{
    /**
     * @param Throwable $previous What the released announcement raised
     */
    public function __construct(Throwable $previous)
    {
        parent::__construct(
            'An announcement held for the commit failed when the commit released it: ' . $previous->getMessage(),
            previous: $previous,
        );
    }
}
