<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Closure;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandChannelWindows;
use Hilos\Constants\TimeConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\CLI\Exception\TestOnlyCommandOnProductionException;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\EventLoopTimer;
use Hilos\Core\EventLoop\Exception\EventLoopException;
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
 * The roster is built over several main-loop passes. Its epoch send token orders the asks;
 * a separate monotonic deadline bounds each wait. The manager supplies the completed-roster
 * fact only after the other forced master frames, over their existing worker link. A node
 * without an HTTP server or router owes no HTTP frame.
 *
 * A wait ends four ways: the roster went out and the node agent is asked to report, which
 * answers the console itself; the roster could not be built; the deadline passed; the caller
 * left. Nothing here touches the database, a file or the network - the ask leaves over the
 * master's existing link to its worker.
 */
final class DaemonNodeReportTestRequests
{
    /** @var array<string, float> Epoch arrival of each pending ask, by console correlation */
    private array $requestedAt = [];

    /** @var array<string, float> Last moment each pending ask may still be answered, by console correlation */
    private array $deadlines = [];

    private float $nextDeadline = INF;

    private ?EventLoopTimer $deadlineTimer = null;

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
     * The timer cues the manager; only its guarded phase executes overdue requests.
     *
     * @param EventLoop $loop This master's single native loop
     * @param Closure(): void $wake Cue the existing guarded deadline phase
     * @throws EventLoopException When a live original deadline cannot be armed
     */
    public function attachEventLoop(EventLoop $loop, Closure $wake): void
    {
        $this->detachEventLoop();
        $this->deadlineTimer = $loop->createTimer($wake);
        $this->rearmDeadlineTimer();
    }

    /** Cancels this binding's native and queued work, preserving every live wait for rebind. */
    public function detachEventLoop(): void
    {
        $this->deadlineTimer?->close();
        $this->deadlineTimer = null;
    }

    /**
     * Admits an ask, forcing fresh master frames, or refuses it at once.
     *
     * @param CommandRequestDTO $request Held request, independently checked at this boundary
     * @param float $now Epoch seconds at the actual request arrival
     * @param ?float $monotonicNow Monotonic observation for deadline tests
     * @throws EventLoopException When the nearest live deadline cannot be armed or canceled
     */
    public function request(CommandRequestDTO $request, float $now, ?float $monotonicNow = null): void
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
        $deadline = ($monotonicNow ?? hrtime(true) / TimeConstants::NS_PER_MILLISECOND / TimeConstants::MS_PER_SECOND)
            + CommandChannelWindows::AGENT_WAIT_SECONDS;
        $this->requestedAt[$request->correlationId] = $now;
        $this->deadlines[$request->correlationId] = $deadline;
        $this->rearmDeadlineTimer();
    }

    /**
     * Asks the node agent to report now for every wait whose roster has gone out.
     *
     * @param float $rosterSentAt Epoch seconds at the actual complete roster send
     * @param ?float $now Monotonic observation for deadline tests
     * @throws EventLoopException When the next live deadline cannot be armed
     */
    public function dispatchDue(float $rosterSentAt, ?float $now = null): void
    {
        $this->tick($now);
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

    /**
     * @param string $error Sentence naming why the roster could not be built
     * @throws EventLoopException When the nearest live deadline cannot be armed or canceled
     */
    public function fail(string $error): void
    {
        foreach (array_keys($this->requestedAt) as $correlationId) {
            $this->finish((string)$correlationId, $error);
        }
    }

    /**
     * @param string $correlationId Ask whose caller stopped waiting, or that has been answered
     * @throws EventLoopException When the nearest live deadline cannot be armed or canceled
     */
    public function abandon(string $correlationId): void
    {
        if (!isset($this->requestedAt[$correlationId])) {
            return;
        }
        unset($this->requestedAt[$correlationId], $this->deadlines[$correlationId]);
        $this->rearmDeadlineTimer();
    }

    /**
     * @param ?float $now Monotonic seconds, injectable for deadline tests
     * @throws EventLoopException When the next live deadline cannot be armed
     */
    public function tick(?float $now = null): void
    {
        $now ??= hrtime(true) / TimeConstants::NS_PER_MILLISECOND / TimeConstants::MS_PER_SECOND;
        if ($now < $this->nextDeadline) {
            $this->rearmDeadlineTimer();
            return;
        }
        foreach ($this->deadlines as $correlationId => $deadline) {
            if ($now >= $deadline) {
                $this->finish((string)$correlationId, 'The Daemon node agent did not report in time.');
            }
        }
    }

    /**
     * @param string $correlationId Wait to consume before replying
     * @param string $error Refusal the held console is answered with
     * @throws EventLoopException When the nearest live deadline cannot be armed or canceled
     */
    private function finish(string $correlationId, string $error): void
    {
        $this->abandon($correlationId);
        ($this->reply)(CommandReplyDTO::error($correlationId, $error));
    }

    /** @throws EventLoopException When the nearest live wait cannot be armed or canceled */
    private function rearmDeadlineTimer(): void
    {
        $this->nextDeadline = $this->deadlines === [] ? INF : min($this->deadlines);
        $this->deadlineTimer?->armAt(is_finite($this->nextDeadline) ? $this->nextDeadline : null);
    }
}
