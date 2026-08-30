<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Core\Agent\Daemon\AgentManagerDaemon;

/**
 * AgentLossSink - master-side seam the roster hands a dead worker's agents to.
 *
 * A worker that dies takes every agent it hosted with it, and the daemon learns this from
 * the socket rather than from a report: no stopping agent speaks for a process that is
 * already gone. {@see AgentManagerDaemon::forgetAgentsOfWorker()} takes them off the roster
 * so the next frame addressed to one starts it again, and says so here on the way out.
 *
 * This is a report and not a failure. It has no {@see ContainedFailure} card and does not
 * ride that sink, for two reasons the enum of master units states itself: a unit there is
 * whatever can fail without its neighbours being any the worse for it, which a worker is
 * the opposite of, and a card carries a throwable, which a death observed on a socket does
 * not have. Nor is it {@see DaemonManager::onPlacementDegraded()}: that one fires on the
 * leader when failover could place an agent nowhere at all, a terminal state, while these
 * agents come back on this node the moment anything addresses them.
 *
 * Narrow on purpose, like {@see ContainedFailureSink}: the roster learns one door and not
 * the manager behind it.
 *
 * There is no answer to give back - the roster has already been emptied by the time it
 * reports, and a report the reader could branch on would invite it to decide again.
 */
interface AgentLossSink
{
    /**
     * Takes the agents that went down with a worker, on the way to the project.
     *
     * Called after the journal line and never instead of it: the record is not the
     * project's to replace. Runs on the master loop, so an implementation does what the
     * hook it feeds is allowed to do and nothing slower.
     *
     * @param int $workerIndex Index of the worker that died
     * @param bool $isMonopolistic True when that worker was monopolistic
     * @param list<string> $agentIds Ids of the agents it was hosting, in roster order
     */
    public function reportAgentsLostWithWorker(int $workerIndex, bool $isMonopolistic, array $agentIds): void;
}
