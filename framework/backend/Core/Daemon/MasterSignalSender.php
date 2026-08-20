<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalRouter;

/**
 * MasterSignalSender - master-side seam project code hands work out through.
 *
 * Code that runs on the master loop is not allowed to do the work it discovers: the loop
 * serves every connection of the node, so a query, a file, or a call made here stops all of
 * them. What it is allowed to do is say what happened, to somebody who may block - a named
 * agent, or every worker of this node. The {@see DaemonManager} implements this, so a project
 * daemon reaches both doors through `$this` and nothing wider: the servers holding the actual
 * links stay private, exactly as they are behind {@see ConnectionDropper}.
 *
 * Both doors put a frame in a socket write buffer and return, with ONE exception worth
 * knowing before either is called on a hot path: delivery to an agent that is not running
 * starts it, and starting runs the project's own agent-daemon factory synchronously on the
 * master loop (see {@see sendToAgent()}). The seam does not make that factory safe - it is
 * master-loop code like any other and is bound by the same rule.
 *
 * Neither door reports delivery - see the void return on each - because there is nothing a
 * master loop could usefully do about a failure, and a caller invited to branch on the answer
 * would be invited to do exactly the work this seam exists to move elsewhere. Delivery
 * failures are written to the log instead, with the signal name and the addressee.
 *
 * This is the imperative door, for when the addressee is known by name and there is no route
 * to declare. The ordinary way to move a signal is still {@see SignalRouter::queueSignal()},
 * which routes by who sent it; ordering between the two is not guaranteed, because this
 * writes to the socket at once and the queue drains at the end of the loop iteration.
 */
interface MasterSignalSender
{
    /**
     * Sends a signal to one agent, wherever in the cluster it is running.
     *
     * Placement decides the route: an agent this node hosts is delivered to over the local
     * worker link, one placed on another node is forwarded over the peer channel. Delivery
     * STARTS a stopped agent - the local path is the same one the router uses, and it starts
     * an agent that is not running rather than dropping the signal.
     *
     * That start is the one part of this call that is not a buffered write: the project's
     * agent-daemon factory runs here, on the master loop, before anything is sent. A running
     * agent costs a buffer write; a stopped one costs whatever that factory costs, so a
     * factory that reads a file or the database blocks the loop this seam exists to protect.
     *
     * @param string $agentType Agent type to address
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @param string $signalName Signal name the receiving agent dispatches on
     * @param SignalDataInterface $data Signal payload
     */
    public function sendToAgent(string $agentType, ?string $agentIndex, string $signalName, SignalDataInterface $data): void;

    /**
     * Sends a signal to every worker of this node, including monopolistic ones.
     *
     * Strictly local: "every worker of a node" is addressed by naming the node, so there is
     * no cross-node form of this. Agents running inside those workers are not handed the
     * signal - {@see sendToAgent()} is how an agent is addressed. The receiving side is
     * {@see WorkerManager::onDaemonSignal()}, which a project overrides.
     *
     * @param string $signalName Signal name the receiving workers dispatch on
     * @param SignalDataInterface $data Signal payload
     */
    public function sendToWorkers(string $signalName, SignalDataInterface $data): void;
}
