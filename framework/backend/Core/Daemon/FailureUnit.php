<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use BackedEnum;
use Hilos\Core\Daemon\Master\MasterFailureUnit;
use Hilos\Core\Daemon\Worker\WorkerTickUnit;

/**
 * FailureUnit - one unit of work whose failure a guard can contain, in either process.
 *
 * The card describing a contained failure is one for the whole framework
 * ({@see ContainedFailure}), but what counts as a unit is not: the worker skips a message,
 * an agent or a subscription ({@see WorkerTickUnit}), while the master loses a connection,
 * an accept or a loop iteration ({@see MasterFailureUnit}). Listing both in one enum would
 * offer every writer of a journal line cases that cannot happen where it stands, so the
 * enumerations stay apart and only the type above them is shared.
 *
 * It declares no method of its own on purpose. What the two readers of a card need from a
 * unit is the words it prints under, and a backed enum already carries them as `->value`;
 * a method added here would be a second name for the same string, and the first writer to
 * pick the other one would split the journal.
 */
interface FailureUnit extends BackedEnum
{
}
