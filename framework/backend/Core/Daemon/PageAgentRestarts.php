<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Closure;
use Hilos\Cluster\Exception\ClusterConfigurationException;
use Hilos\Cluster\Exception\ClusterDisabledException;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandChannelWindows;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Agent\AgentId;
use Hilos\DaemonSection\DTO\DaemonAgentRestartRequestSignalData;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Utils\Helpers\RandomHelper;
use Hilos\Utils\Logger;

/** Master-owned web waiters; the existing restart door owns the work itself. */
final class PageAgentRestarts
{
    private const string CORRELATION_PREFIX = 'web-agent-restart-';
    private const int CORRELATION_BYTES = 16;
    private const string TIMEOUT_REASON = 'The daemon did not answer agent:restart within 30s; check the agent state before trying again.';

    /** @var array<string, PageAgentRestartWaiter> Correlation id to original page request and deadline */
    private array $waiters = [];

    /** An ordinary tick does no walk before the earliest possible expiry. */
    private float $nextDeadline = INF;

    /**
     * @param AgentRestartDoor $restartDoor Existing placement and restart mechanism
     * @param MasterSignalSender $signals Return path to the admitting page agent
     * @param Closure(string): void $abandon Releases only command waiting, never the restart
     */
    public function __construct(
        private readonly AgentRestartDoor $restartDoor,
        private readonly MasterSignalSender $signals,
        private readonly Closure $abandon,
    ) {
    }

    /**
     * Correlation ids need uniqueness only; they carry no permission or secret.
     * Registers before handing off so a synchronous refusal can find its waiter.
     *
     * @param DaemonAgentRestartRequestSignalData $request Page-admitted request
     * @param ?float $now Current seconds, injectable for deadline tests
     * @throws EnvException When the cluster mode or local node cannot be read
     * @throws ClusterConfigurationException When the cluster node identity is invalid
     * @throws ClusterDisabledException When an enabled cluster refuses its identity
     */
    public function request(DaemonAgentRestartRequestSignalData $request, ?float $now = null): void
    {
        $clustered = Hilos::$cluster?->isEnabled() === true;
        $originNodeId = $clustered ? Hilos::$cluster->localNodeId() : null;
        $agent = AgentId::fromId($request->agentId);
        do {
            $correlationId = self::CORRELATION_PREFIX . RandomHelper::hex(self::CORRELATION_BYTES);
        } while (isset($this->waiters[$correlationId]));

        $deadline = ($now ?? microtime(true)) + CommandChannelWindows::CHANNEL_HELD_SECONDS;
        $this->waiters[$correlationId] = new PageAgentRestartWaiter($request, $deadline);
        $this->nextDeadline = min($this->nextDeadline, $deadline);
        $this->restartDoor->requestAgentRestart(new CommandRequestDTO(
            correlationId: $correlationId,
            command: CliCommands::AGENT_RESTART,
            payload: [
                CommandConstants::FIELD_AGENT_TYPE => $agent->type,
                CommandConstants::FIELD_AGENT_INDEX => $agent->index,
                CommandConstants::FIELD_NODE_ID => $clustered ? $request->nodeId : null,
            ],
            originNodeId: $originNodeId,
        ));
    }

    /**
     * @param string $correlationId Command reply correlation to check
     * @return bool Whether this correlation still has a web waiter
     */
    public function hasWaiter(string $correlationId): bool
    {
        return isset($this->waiters[$correlationId]);
    }

    /**
     * Removes the waiter before sending, so re-entry or a duplicate cannot answer twice.
     * Unknown replies are ignored; malformed outcomes leave the waiter until its deadline.
     *
     * @param CommandReplyDTO $reply Local or peer-forwarded command outcome
     */
    public function acceptReply(CommandReplyDTO $reply): void
    {
        $waiter = $this->waiters[$reply->correlationId] ?? null;
        if ($waiter === null) {
            return;
        }
        $message = null;
        $error = null;
        if ($reply->isOk()) {
            $agentId = $reply->payload[CommandConstants::FIELD_AGENT_ID] ?? null;
            $nodeId = $reply->payload[CommandConstants::FIELD_NODE_ID] ?? null;
            if (!is_string($agentId) || !is_string($nodeId)) {
                Logger::error('Agent restart reply lacks agentId or nodeId; the page waiter remains until its deadline');
                return;
            }
            $message = sprintf('Agent %s restarted on node %s.', $agentId, $nodeId);
        } else {
            $error = $reply->payload[CommandConstants::FIELD_MESSAGE] ?? null;
            if (!is_string($error)) {
                Logger::error('Agent restart refusal lacks its message; the page waiter remains until its deadline');
                return;
            }
        }
        unset($this->waiters[$reply->correlationId]);
        if ($this->waiters === []) {
            $this->nextDeadline = INF;
        }
        $this->answer($waiter->request, $message, $error);
    }

    /**
     * Releases expired waiting only; a slow restart remains owned by AgentRestarts.
     *
     * @param float $now Current time in seconds
     */
    public function tick(float $now): void
    {
        if ($now < $this->nextDeadline) {
            return;
        }
        $this->nextDeadline = INF;
        foreach ($this->waiters as $correlationId => $waiter) {
            if ($now < $waiter->deadline) {
                $this->nextDeadline = min($this->nextDeadline, $waiter->deadline);
                continue;
            }
            unset($this->waiters[$correlationId]);
            ($this->abandon)($correlationId);
            $this->answer($waiter->request, null, self::TIMEOUT_REASON);
        }
    }

    /**
     * @param DaemonAgentRestartRequestSignalData $request Original page request
     * @param ?string $message Successful restart sentence
     * @param ?string $error Command refusal or wait timeout
     */
    private function answer(DaemonAgentRestartRequestSignalData $request, ?string $message, ?string $error): void
    {
        $this->signals->sendToAgent(
            $request->replyAgentType,
            $request->replyAgentIndex,
            HilosSignalConstants::DAEMON_AGENT_RESTART_DONE,
            new HandoverAnswerSignalData(
                acceptKey: $request->acceptKey,
                requestId: $request->requestId,
                action: HilosSignalConstants::HILOS_DAEMON_AGENT_RESTART,
                successMessage: $message,
                error: $error,
                errorType: null,
                errorDetail: null,
            ),
        );
    }
}

/** One web caller's wait; independent of the lifetime of the restart itself. */
final readonly class PageAgentRestartWaiter
{
    /**
     * @param DaemonAgentRestartRequestSignalData $request Original request and server-owned return address
     * @param float $deadline End of this caller's waiting window
     */
    public function __construct(public DaemonAgentRestartRequestSignalData $request, public float $deadline)
    {
    }
}
