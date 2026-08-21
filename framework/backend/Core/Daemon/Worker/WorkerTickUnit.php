<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon\Worker;

use Hilos\Core\Daemon\FailureUnit;
use Hilos\Core\Daemon\WorkerManager;

/**
 * WorkerTickUnit - one unit of work a worker tick is made of.
 *
 * The tick of {@see WorkerManager::run()} contains a failure per unit: the unit is
 * skipped, its failure reaches the journal and the project's hook, and the units around
 * it keep ticking. What counts as a unit is therefore not a matter of taste - it is
 * whatever can fail without its neighbours being any the worse for it, which is why one
 * message, one agent and one browser subscription are units while the loop iteration
 * they sit in is not.
 *
 * The value of a case is the words that go into the journal line and into the limiter's
 * key, so it reads as English and not as a constant name.
 */
enum WorkerTickUnit: string implements FailureUnit
{
    /** One message read from the daemon connection */
    case DAEMON_MESSAGE = 'daemon message';

    /** One worker-local agent: its tick, its deferred work and its self-stop hook */
    case AGENT = 'agent';

    /** The project's own per-iteration hook, {@see WorkerManager::onTick()} */
    case WORKER_TICK = 'worker tick';

    /** The signal drain that carries the browser flush between its two phases */
    case SIGNAL_DISPATCH = 'signal dispatch';

    /** One browser subscription inside the fan-out of one source change */
    case BROWSER_SUBSCRIPTION = 'browser subscription';

    /** The analytics tick */
    case ANALYTICS = 'analytics';

    /** The project's failure hook itself, for the failure it raises while answering another */
    case FAILURE_HOOK = 'failure hook';
}
