<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon\Worker;

use Hilos\Core\Daemon\WorkerManager;
use Throwable;

/**
 * ContainedFailure - a failure the worker tick swallowed, and where it happened.
 *
 * One value serves two readers: the journal line a person greps for, and
 * {@see WorkerManager::onTickFailure()}, where the project decides whether the failure
 * deserves an answer of its own. Both need the same three facts, so they are carried
 * once instead of being assembled twice from the guard's local variables.
 *
 * The address is a string and not a structure because neither reader wants it in parts.
 * Every unit addresses itself differently - a message by its type, an agent by its id, a
 * subscription by page and accept key - and a structure wide enough for all of them
 * would be mostly null at every call site, while the one thing both readers do with the
 * address is print it or compare it whole.
 *
 * The unit is deliberately not enough on its own: a project told only that "a browser
 * subscription" failed cannot answer, because the answer usually depends on which page.
 */
final readonly class ContainedFailure
{
    /**
     * @param WorkerTickUnit $unit Unit of work whose failure was contained
     * @param string $address Which one of that unit failed, in the unit's own terms
     * @param Throwable $failure Failure the unit ended with
     */
    public function __construct(
        public WorkerTickUnit $unit,
        public string $address,
        public Throwable $failure,
    ) {
    }
}
