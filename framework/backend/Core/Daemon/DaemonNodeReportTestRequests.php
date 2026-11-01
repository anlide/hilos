<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Closure;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandChannelWindows;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\CLI\Exception\TestOnlyCommandOnProductionException;
use Hilos\DaemonSection\DTO\DaemonMasterReportNowSignalData;
use Hilos\Environment\NonProductionGate;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\HilosClusterNode as StateHilosClusterNode;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Throwable;

/**
 * Local console asks for a node report now, each waiting for a process roster sent no earlier than itself.
 *
 * Only the roster is waited for. It alone is built over several passes of the main loop; the
 * other master frames are built in one pass and have gone out in the pass the ask arrived in,
 * and the HTTP frame of a node without a server or a router never goes out, so there is nothing
 * to wait for there. The roster's send stamp is the measure, not the force flags: the HTTP flag
 * of such a node is never cleared, and under a freeze every flag stays raised.
 *
 * A wait ends four ways: the roster went out and the node agent is asked to report, which
 * answers the console itself; the roster could not be built; the deadline passed; the caller
 * left. Nothing here touches the database, a file or the network - the ask leaves over the
 * master's existing link to its worker.
 */
final class DaemonNodeReportTestRequests
{
    /** @var array<string, float> Main-loop pass each pending ask arrived in, by console correlation */
    private array $requestedAt = [];

    /** @var array<string, float> Last moment each pending ask may still be answered, by console correlation */
    private array $deadlines = [];

    private float $nextDeadline = INF;

    /**
     * @param AgentManagerDaemon $agents This master's agent roster, asked whether the node agent runs
     * @param MasterSignalSender $signals This master's door to its own node agent
     * @param Closure(): void $forceFrames Raises the force flags of every master frame for the node agent
     * @param Closure(CommandReplyDTO): void $reply Delivery to the held local console
     */
    public function __construct(
        private readonly AgentManagerDaemon $agents,
        private readonly MasterSignalSender $signals,
        private readonly Closure $forceFrames,
        private readonly Closure $reply,
    ) {
    }

    /**
     * Admits an ask, forcing fresh master frames, or refuses it at once.
     *
     * @param CommandRequestDTO $request Held request, independently checked at this boundary
     * @param float $now Start of the main-loop pass the ask arrived in
     */
    public function request(CommandRequestDTO $request, float $now): void
    {
        if (!NonProductionGate::admitted()) {
            ($this->reply)(CommandReplyDTO::error(
                $request->correlationId,
                TestOnlyCommandOnProductionException::message(CliCommands::DAEMON_TEST_NODE_REPORT),
            ));
            return;
        }

        $freeze = Hilos::$rt?->hilosProtectedModeRuntime;
        if ($freeze !== null && $freeze->phase !== StateProtectedModeRuntime::PHASE_INACTIVE) {
            ($this->reply)(CommandReplyDTO::error($request->correlationId, 'No Daemon node report during protected mode.'));
            return;
        }
        if (!$this->agents->isAgentStarted(HilosAgentType::HILOS_DAEMON_NODE)) {
            ($this->reply)(CommandReplyDTO::error($request->correlationId, 'The Daemon node agent is not running on this node.'));
            return;
        }

        ($this->forceFrames)();
        $deadline = $now + CommandChannelWindows::AGENT_WAIT_SECONDS;
        $this->requestedAt[$request->correlationId] = $now;
        $this->deadlines[$request->correlationId] = $deadline;
        $this->nextDeadline = min($this->nextDeadline, $deadline);
    }

    /**
     * Asks the node agent to report now for every wait whose roster has gone out.
     *
     * @param float $rosterSentAt Main-loop pass the last whole process roster was sent in
     */
    public function dispatchDue(float $rosterSentAt): void
    {
        $due = [];
        foreach ($this->requestedAt as $correlationId => $requestedAt) {
            if ($requestedAt <= $rosterSentAt) {
                $due[] = (string)$correlationId;
            }
        }
        foreach ($due as $correlationId) {
            $this->abandon($correlationId);
            try {
                $this->signals->sendToAgent(
                    HilosAgentType::HILOS_DAEMON_NODE,
                    null,
                    HilosSignalConstants::DAEMON_MASTER_REPORT_NOW,
                    new DaemonMasterReportNowSignalData(
                        Hilos::$cluster?->localNodeId() ?? StateHilosClusterNode::STANDALONE_NODE_ID,
                        $correlationId,
                    ),
                );
            } catch (Throwable $failure) {
                ($this->reply)(CommandReplyDTO::error($correlationId, $failure->getMessage()));
            }
        }
    }

    /** @param string $error Sentence naming why the roster could not be built */
    public function fail(string $error): void
    {
        foreach (array_keys($this->requestedAt) as $correlationId) {
            $this->finish((string)$correlationId, $error);
        }
    }

    /** @param string $correlationId Ask whose caller stopped waiting, or that has been answered */
    public function abandon(string $correlationId): void
    {
        unset($this->requestedAt[$correlationId], $this->deadlines[$correlationId]);
        if ($this->requestedAt === []) {
            $this->nextDeadline = INF;
        }
    }

    /** @param float $now Current seconds on the master's loop */
    public function tick(float $now): void
    {
        if ($now < $this->nextDeadline) {
            return;
        }
        $this->nextDeadline = INF;
        foreach ($this->deadlines as $correlationId => $deadline) {
            if ($now >= $deadline) {
                $this->finish((string)$correlationId, 'The Daemon node agent did not report in time.');
            } else {
                $this->nextDeadline = min($this->nextDeadline, $deadline);
            }
        }
    }

    /**
     * @param string $correlationId Wait to consume before replying
     * @param string $error Refusal the held console is answered with
     */
    private function finish(string $correlationId, string $error): void
    {
        $this->abandon($correlationId);
        ($this->reply)(CommandReplyDTO::error($correlationId, $error));
    }
}
