<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon\Master;

use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Daemon\FailureUnit;

/**
 * MasterFailureUnit - one unit of work the master contains a failure of.
 *
 * The master serves every connection of the node from a single loop, so it swallows what
 * it can rather than let one bad client end the process. What it swallows is listed here,
 * and the list is short for the same reason the worker's is: a unit is whatever can fail
 * without its neighbours being any the worse for it. A connection qualifies, an accept
 * qualifies; the loop iteration is the exception that proves the rule, kept here because
 * the node announces its departure instead of dying under the exception, and the project
 * is told before it goes ({@see DaemonManager::run()}).
 *
 * The value of a case is the words that go into the journal line, so it reads as English
 * and not as a constant name - the same rule the worker's units follow.
 */
enum MasterFailureUnit: string implements FailureUnit
{
    /** One live connection, read either by its server's tick or by the loop's read callback */
    case CONNECTION = 'connection';

    /** One incoming connection the server failed to accept */
    case CONNECTION_ACCEPT = 'connection accept';

    /** One iteration of the master loop, after which the node leaves */
    case LOOP_ITERATION = 'loop iteration';

    /**
     * The project's failure hook itself, for the failure it raises while answering another.
     *
     * Named here because the hook is a guarded unit like the three above, but no card ever
     * carries it: the only reader of a card is the hook, and calling it with its own failure
     * is the loop the guard exists to prevent. The failure is written and stops there.
     */
    case FAILURE_HOOK = 'failure hook';
}
