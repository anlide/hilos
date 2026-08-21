<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Core\Daemon\Master\MasterFailureUnit;
use Hilos\Core\Daemon\Worker\WorkerTickUnit;
use Throwable;

/**
 * ContainedFailure - a failure a guard swallowed, and where it happened.
 *
 * One value serves two readers: the journal line a person greps for, and the project hook -
 * {@see WorkerManager::onTickFailure()} in a worker, {@see DaemonManager::onContainedFailure()}
 * in the master - where the project decides whether the failure deserves an answer of its own.
 * Both need the same three facts, so they are carried once instead of being assembled twice
 * from the guard's local variables.
 *
 * The card is one for both processes because a failure is described the same way wherever it
 * was caught, and a project that answers in both places should not learn two shapes for it.
 * What differs is only which units exist to fail, and that stays with each process: the unit
 * is typed as {@see FailureUnit}, which {@see WorkerTickUnit} and {@see MasterFailureUnit}
 * implement separately.
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
     * @param FailureUnit $unit Unit of work whose failure was contained
     * @param string $address Which one of that unit failed, in the unit's own terms
     * @param Throwable $failure Failure the unit ended with
     */
    public function __construct(
        public FailureUnit $unit,
        public string $address,
        public Throwable $failure,
    ) {
    }
}
