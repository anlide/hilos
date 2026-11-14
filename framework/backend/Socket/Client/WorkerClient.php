<?php

declare(strict_types=1);

namespace Hilos\Socket\Client;

use Closure;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\EventLoopTimer;
use Hilos\Core\EventLoop\Exception\EventLoopException;
use Hilos\Socket\Exception\SocketTlsHandshakeException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Constants\WorkerConstants;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Core\Agent\AgentId;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Agent\Exception\AgentDaemonNotRegisteredException;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Cluster\Exception\ClusterConfigurationException;
use Hilos\Cluster\Exception\ClusterDisabledException;
use Hilos\Cluster\Peer\PeerLink;
use Hilos\Core\Source\SourceChange;
use Hilos\Database\ReHydrateRound;
use Hilos\Environment\Exception\EnvException;
use Hilos\Files\Upload\UploadMemoryPoolSink;
use Hilos\Files\Upload\UploadMemoryDeliverySink;
use Hilos\Log\LogWriteLevelApplier;
use Hilos\ProtectedMode\ProtectedModeEntryGate;
use Hilos\Runtime\RtSnapshot;
use Hilos\Runtime\RtStaleness;
use Hilos\Runtime\Stream\RtSyncStreamChannel;
use Hilos\Runtime\Stream\RtSyncStreamFrame;
use Hilos\Runtime\Stream\RtSyncStreamOperation;
use Hilos\Socket\Client\Interface\WorkerClientInterface;
use Hilos\Socket\SocketException;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\WebSocket\DTO\HandshakeResponseSignalData;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupSubscribeSignalDTO;
use Hilos\Socket\Worker\DTO\AgentStartDTO;
use Hilos\Socket\Worker\DTO\AgentStopDTO;
use Hilos\Socket\Worker\DTO\ProtectedModeReadyDTO;
use Hilos\Socket\Worker\DTO\ProtectedModeRefusedDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStartedDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStartFailedDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStoppedDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentRestartRequestDTO;
use Hilos\Socket\Worker\DTO\DbReHydrateCompleteDTO;
use Hilos\Socket\Worker\DTO\WorkerDbReHydratedDTO;
use Hilos\Socket\Worker\DTO\WorkerDbReHydrateMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbInterestReadyMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncClearedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncCreatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncDeletedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncUpdatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerGroupJoinDTO;
use Hilos\Socket\Worker\DTO\WorkerGroupLeaveAllDTO;
use Hilos\Socket\Worker\DTO\WorkerLogWriteLevelDTO;
use Hilos\Socket\Worker\DTO\WorkerPageAccessReassessConnectionsMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerPageAccessReassessMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerProtectedModeDisableDTO;
use Hilos\Socket\Worker\DTO\WorkerProtectedModeEnableDTO;
use Hilos\Socket\Worker\DTO\WorkerProtectedModeCircleDTO;
use Hilos\Socket\Worker\DTO\WorkerProtectedModePassDTO;
use Hilos\Socket\Worker\DTO\WorkerProtectedModeProgressDTO;
use Hilos\Socket\Worker\DTO\WorkerProtectedModeRefreezeDTO;
use Hilos\Socket\Worker\DTO\WorkerProtectedModeSettingsDTO;
use Hilos\Socket\Worker\DTO\WorkerProtectedModeVerifyDTO;
use Hilos\Socket\Worker\DTO\WorkerRegisterDTO;
use Hilos\Socket\Worker\DTO\WorkerRegisteredDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSnapshotMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSourceRegisteredDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSourceReleasedDTO;
use Hilos\Socket\Worker\DTO\WorkerRtStalenessMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSyncStreamDTO;
use Hilos\Socket\Worker\DTO\WorkerSourceInterestDTO;
use Hilos\Socket\Worker\DTO\WorkerUploadConsumedDTO;
use Hilos\Socket\Worker\DTO\WorkerUploadSettingsDTO;
use Hilos\Socket\Worker\DTO\WorkerDeliverySettingsDTO;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\Socket\WebSocket\Delivery\DeliverySettingsSink;
use Hilos\Utils\LogLevel;
use Hilos\Utils\Logger;
use Hilos\Socket\Worker\DTO\WorkerTestCronFiredDTO;
use Hilos\Socket\Worker\DTO\WorkerTestCronScheduleResultDTO;
use Hilos\Socket\Worker\DTO\WorkerTestOrmForgottenDTO;
use WeakReference;

/**
 * WorkerClient - Represents a single worker connection.
 *
 * Handles reading messages from workers and writing responses.
 * Created by WorkerServer when accepting new worker connections.
 */
class WorkerClient extends AbstractClient implements WorkerClientInterface
{
    /** Prefix of an agent among the names held frames keep their order under ({@see self::orderKeyOf()}). */
    private const string ORDER_OF_AGENT = 'agent ';

    /** Prefix of a socket among the names held frames keep their order under ({@see self::orderKeyOf()}). */
    private const string ORDER_OF_SOCKET = 'socket ';

    /** @var int Worker index */
    private int $workerIndex = 0;

    /** @var bool Whether worker is monopolistic */
    private bool $isMonopolistic = false;

    /** @var bool Whether worker is registered */
    private bool $isRegistered = false;

    /** @var AgentManagerDaemon Agent manager daemon instance */
    private AgentManagerDaemon $agentManager;

    /** @var float Connection acceptance in monotonic seconds */
    private float $connectTime;

    /** @var float Registration timeout in seconds */
    private float $registrationTimeout = 10.0;

    private ?EventLoopTimer $registrationTimer = null;

    private bool $registrationDue = false;

    /** @var array<string, true> Agents this link was sent agent_stop for, not yet reported stopped by the worker */
    private array $stopsAwaitingReport = [];

    /** The production master supplies the same pool sink to both transport servers. */
    private ?UploadMemoryPoolSink $uploadMemoryPoolSink = null;

    /** Master-owned receiver for delivery window reports. */
    private ?DeliverySettingsSink $deliverySettingsSink = null;
    /** The ordinary RT stream of this link instance */
    private ?RtSyncStreamChannel $rtSyncStream = null;
    /** @var ?Closure(RtSyncStreamChannel): void The master's pass this link's stream is pumped on; null pumps at once */
    private ?Closure $rtSyncStreamWake = null;
    /** @var ?Closure(int, string, list<string>, ?float): void The master's mark of rows this link's stream holds */
    private ?Closure $rtSyncStreamHold = null;
    /**
     * Frames waiting for the stream to read the parts it took, in arrival order, each with the newline that ends it:
     * the signals to agents, the access re-decisions of pages, the agents' command replies, releases of RT sources and
     * stop reports, the responses to browsers' handshakes, and the reports of an agent's start or stop and the answers
     * to a socket behind them ({@see self::waitsForRtSyncStream()})
     */
    private string $heldForRtSyncStream = '';
    /**
     * @var array<string, true> The agents whose start or stop a held frame reports and the sockets a held answer is
     *      addressed to ({@see self::orderKeyOf()}), until the held frames are parsed again. Framework-first:
     *      process-local bookkeeping of this link instance, as the held frames it names are; nothing else reads it,
     *      and it ends with the link.
     */
    private array $heldOrderKeys = [];
    /**
     * @var array<string, int> Frames naming each agent that wait for their turn behind this link's stream, until
     *      written ({@see self::sendInTurn()}); the stream is this link instance's, so the count ends with it
     */
    private array $waitingInTurn = [];
    /**
     * @var array<string, array<string, int>> Rows whose thaw waits for its turn behind this link's stream, by
     *      collection, each with the number of the last thaw naming it ({@see self::sendRtStaleness()}); the
     *      stream is this link instance's, so the record ends with it
     */
    private array $thawsInTurn = [];
    /**
     * Number of the last thaw this link wrote in its turn. Framework-first: the same process-local bookkeeping of this
     * link instance as {@see self::$thawsInTurn}, which it numbers.
     */
    private int $thawSequence = 0;

    /**
     * Create worker client with socket and agent manager.
     *
     * @param resource $socket Client socket
     * @param AgentManagerDaemon $agentManager Agent manager daemon instance
     * @param WorkerServer $workerServer Owning worker server
     * @throws EnvException When socket read buffer env value is missing or invalid
     */
    public function __construct(
        $socket,
        AgentManagerDaemon $agentManager,
        private readonly WorkerServer $workerServer,
    ) {
        parent::__construct($socket);
        $this->agentManager = $agentManager;
        $this->connectTime = hrtime(true) / TimeConstants::NS_PER_MILLISECOND / TimeConstants::MS_PER_SECOND;
    }

    /**
     * Binds the original acceptance deadline; rebinding never grants a new registration window.
     *
     * @param EventLoop $loop Owning master's loop
     * @param Closure(): void $wake Existing guarded read/tick/write/drop portion
     * @throws SocketTlsHandshakeException When the transport refuses a native base change
     * @throws EventLoopException When the registration deadline cannot be armed
     */
    public function attachEventLoop(EventLoop $loop, Closure $wake): void
    {
        parent::attachEventLoop($loop, $wake);
        if ($this->socket === null || $this->isRegistered || $this->shouldClose) {
            return;
        }

        $this->registrationTimer = $loop->createTimer(function (): void {
            $this->registrationDue = true;
            $this->markReady();
        });
        $this->registrationTimer->armAt($this->connectTime + $this->registrationTimeout);
    }

    /** Cancels native and already queued expiry before releasing the client binding. */
    public function detachEventLoop(): void
    {
        $this->registrationTimer?->close();
        $this->registrationTimer = null;
        $this->registrationDue = false;
        parent::detachEventLoop();
    }

    /**
     * Set worker index
     *
     * @param int $workerIndex Worker index
     */
    public function setWorkerIndex(int $workerIndex): void
    {
        $this->workerIndex = $workerIndex;
    }

    /**
     * Get worker index
     *
     * @return int Worker index
     */
    public function getWorkerIndex(): int
    {
        return $this->workerIndex;
    }

    /**
     * Get worker ID
     *
     * Worker ID is calculated as: negative = monopolistic, positive = regular
     *
     * @return int Worker ID (negative = monopolistic, positive = regular)
     */
    public function getWorkerId(): int
    {
        return $this->isMonopolistic ? -$this->workerIndex : $this->workerIndex;
    }

    /**
     * Set whether worker is monopolistic
     *
     * @param bool $isMonopolistic True if monopolistic
     */
    public function setIsMonopolistic(bool $isMonopolistic): void
    {
        $this->isMonopolistic = $isMonopolistic;
    }

    /**
     * Check if worker is monopolistic
     *
     * @return bool True if monopolistic
     */
    public function isMonopolistic(): bool
    {
        return $this->isMonopolistic;
    }

    /**
     * Check if worker is registered
     *
     * @return bool True if registered
     */
    public function isRegistered(): bool
    {
        return $this->isRegistered;
    }

    /**
     * @param UploadMemoryPoolSink $sink Master-owned receiver for this channel's upload reports
     */
    public function setUploadMemoryPoolSink(UploadMemoryPoolSink $sink): void
    {
        $this->uploadMemoryPoolSink = $sink;
    }

    /**
     * @param DeliverySettingsSink $sink Master-owned receiver for this channel's delivery settings
     */
    public function setDeliverySettingsSink(DeliverySettingsSink $sink): void
    {
        $this->deliverySettingsSink = $sink;
    }

    /** Delete this departed channel's raw IPC buffers before its owner records queue deletion. */
    public function discardUploadBuffers(): void
    {
        $this->readBuffer = '';
        $this->heldForRtSyncStream = '';
        $this->heldOrderKeys = [];
        $this->writeBuffer = '';
    }

    /**
     * Dispatch a bounded portion of complete worker messages, retaining the FIFO remainder.
     *
     * A signal to an agent, an access re-decision of pages, an agent's command reply, the release of
     * what a stopped agent owned in RT, the report of its stop or the response to a browser's handshake
     * behind a streamed RT part waits until
     * the master has read that part: the write the part closes then joins the router FIFO ahead of it,
     * as a whole write frame did before, and a busy master's effect queue cannot let it overtake the
     * write. It waits aside, unhandled, and the stream
     * tells this link when it has read everything it took ({@see self::waitsForRtSyncStream()}). Every
     * other frame behind the part is handled at once, as on the worker's own sending side
     * ({@see WorkerManager::sendToDaemon()}).
     *
     * @throws SocketException When read buffer or JSON depth exceeds limits
     * @throws InvalidFormatException When a frame does not decode, names no known type,
     *     or lacks a field its DTO needs
     * @throws AgentDaemonCreationFailedException When agent creation fails during message handling
     * @throws HilosException When buffered wire input refuses to become a DTO, or a re-queued
     *     frame carries an empty signal name
     */
    protected function processReadBuffer(): void
    {
        for ($processed = 0; $this->readBuffer !== ''; ++$processed) {
            $message = $this->extractReadBufferJsonMessage($processed);
            if ($message === null) {
                // Incomplete input waits for bytes; a complete remainder already owes a portion.
                break;
            }

            $this->processMessage($message);
        }
    }

    /**
     * Process message from worker. A frame that waits for the parts the RT stream took is set aside
     * unhandled ({@see self::waitsForRtSyncStream()}); the parsing goes on behind it.
     *
     * @param string $message Complete JSON message payload
     * @throws InvalidFormatException When a frame does not decode, names no known type,
     *     or lacks a field its DTO needs
     * @throws InvalidArgumentException When a frame the master re-queues carries an empty signal name
     * @throws AgentDaemonCreationFailedException When agent creation fails during message handling
     * @throws LogicException When the page restart entrance was not wired
     * @throws EnvException When a page restart cannot read the local cluster configuration
     * @throws ClusterConfigurationException When the local cluster identity is invalid
     * @throws ClusterDisabledException When an enabled cluster refuses its identity
     * @throws HilosException When the master upload owner cannot apply a control report
     */
    private function processMessage(string $message): void
    {
        // Parse JSON message and create appropriate DTO
        $workerDTO = WorkerDTO::factoryWorkerDTO($message);
        if ($this->waitsForRtSyncStream($workerDTO)) {
            // With the newline that ends every frame, so it reads again from the head of the buffer.
            $this->heldForRtSyncStream .= $message . "\n";
            $orderKey = self::orderKeyOf($workerDTO);
            if ($orderKey !== null) {
                $this->heldOrderKeys[$orderKey] = true;
            }

            return;
        }

        // Log received message data for debugging
        Logger::debug("Received message from worker: " . $message);

        // Handle different message types using match with instanceof
        match (true) {
            $workerDTO instanceof WorkerRegisterDTO => $this->handleWorkerRegisterMessage($workerDTO),
            $workerDTO instanceof WorkerLogWriteLevelDTO => $this->handleWorkerLogWriteLevelMessage($workerDTO),
            $workerDTO instanceof WorkerProtectedModeSettingsDTO => $this->handleWorkerProtectedModeSettingsMessage($workerDTO),
            $workerDTO instanceof WorkerUploadSettingsDTO => $this->handleWorkerUploadSettingsMessage($workerDTO),
            $workerDTO instanceof WorkerDeliverySettingsDTO => $this->handleWorkerDeliverySettingsMessage($workerDTO),
            $workerDTO instanceof WorkerUploadConsumedDTO => $this->handleWorkerUploadConsumedMessage($workerDTO),
            $workerDTO instanceof WorkerAgentStartedDTO => $this->handleAgentStartedMessage($workerDTO),
            $workerDTO instanceof WorkerAgentStartFailedDTO => $this->handleAgentStartFailedMessage($workerDTO),
            $workerDTO instanceof WorkerAgentStoppedDTO => $this->handleAgentStoppedMessage($workerDTO),
            $workerDTO instanceof WorkerAgentMessageDTO => $this->handleAgentMessageMessage($workerDTO),
            $workerDTO instanceof WorkerDbSyncCreatedMessageDTO => $this->handleWorkerDbSyncCreatedMessage($workerDTO),
            $workerDTO instanceof WorkerDbSyncUpdatedMessageDTO => $this->handleWorkerDbSyncUpdatedMessage($workerDTO),
            $workerDTO instanceof WorkerDbSyncDeletedMessageDTO => $this->handleWorkerDbSyncDeletedMessage($workerDTO),
            $workerDTO instanceof WorkerDbSyncClearedMessageDTO => $this->handleWorkerDbSyncClearedMessage($workerDTO),
            $workerDTO instanceof WorkerDbReHydrateMessageDTO => $this->handleWorkerDbReHydrateMessage($workerDTO),
            $workerDTO instanceof WorkerPageAccessReassessMessageDTO
                => $this->handleWorkerPageAccessReassessMessage($workerDTO),
            $workerDTO instanceof WorkerPageAccessReassessConnectionsMessageDTO
                => $this->handleWorkerPageAccessReassessConnectionsMessage($workerDTO),
            $workerDTO instanceof WorkerTestOrmForgottenDTO && $this->isRegistered
                => $this->workerServer->testOrmForgetDoor()?->acceptOrmForgotten($this, $workerDTO),
            $workerDTO instanceof WorkerTestCronFiredDTO && $this->isRegistered
                => $this->workerServer->cronFireTestDoor()?->acceptCronFired($this, $workerDTO),
            $workerDTO instanceof WorkerTestCronScheduleResultDTO && $this->isRegistered
                => $this->workerServer->cronFireTestDoor()?->acceptCronScheduleResult($this, $workerDTO),
            $workerDTO instanceof WorkerDbReHydratedDTO => $this->handleWorkerDbReHydratedMessage($workerDTO),
            $workerDTO instanceof WorkerRtSyncStreamDTO => $this->rtSyncStream()->receive($workerDTO->frame),
            $workerDTO instanceof WorkerRtSourceRegisteredDTO => $this->handleWorkerRtSourceRegisteredMessage($workerDTO),
            $workerDTO instanceof WorkerRtSourceReleasedDTO => $this->handleWorkerRtSourceReleasedMessage($workerDTO),
            $workerDTO instanceof WorkerSourceInterestDTO => $this->handleWorkerSourceInterestMessage($workerDTO),
            $workerDTO instanceof WorkerGroupJoinDTO => $this->handleGroupJoinMessage($workerDTO),
            $workerDTO instanceof WorkerGroupLeaveAllDTO => $this->handleGroupLeaveAllMessage($workerDTO),
            $workerDTO instanceof WorkerAgentRestartRequestDTO => $this->handleAgentRestartRequestMessage($workerDTO),
            $workerDTO instanceof WorkerProtectedModeEnableDTO => $this->handleProtectedModeEnableMessage($workerDTO),
            $workerDTO instanceof WorkerProtectedModeDisableDTO => $this->handleProtectedModeDisableMessage($workerDTO),
            $workerDTO instanceof WorkerProtectedModeVerifyDTO => $this->handleProtectedModeVerifyMessage($workerDTO),
            $workerDTO instanceof WorkerProtectedModeProgressDTO => $this->handleProtectedModeProgressMessage($workerDTO),
            $workerDTO instanceof WorkerProtectedModePassDTO => $this->handleProtectedModePassMessage($workerDTO),
            $workerDTO instanceof WorkerProtectedModeCircleDTO => $this->handleProtectedModeCircleMessage($workerDTO),
            $workerDTO instanceof WorkerProtectedModeRefreezeDTO => $this->handleProtectedModeRefreezeMessage($workerDTO),
            default => Logger::error("Unknown message type received from worker: " . get_class($workerDTO)),
        };
    }

    /**
     * Whether a frame of this worker waits for the parts its RT stream took. What waits is what the worker writes in
     * its turn behind its writes ({@see WorkerManager::sendToDaemon()}) - a signal to an agent, an access re-decision
     * of pages (by session as well), an agent's command reply and the outcome of a cron rule a console asked to fire,
     * the release of what a stopped agent owned in RT, the report of its stop - while the stream reads, and once one
     * waits, every such frame behind it and every part of an operation written after it: that operation is not the one
     * the held frame waits for. The stop report is the fence the master starts
     * the agent elsewhere behind ({@see self::awaitsStopReport()}), so the old instance's writes are queued here ahead
     * of it; the release lets the master announce them to the peers as the agent's. A report of the
     * start or stop of an agent whose start or stop a held frame reports waits too, as the worker wrote it behind
     * that frame: an agent restarted on this worker is not read as started ahead of its old instance's stop. The
     * response to a browser's handshake waits as well: the browser acts on it with frames judged against the
     * connection row written right before it, and a group's worker reads who the socket is from its RT copy of the
     * row, so the master queues it behind the write. Every answer to the same socket behind it waits with it - the
     * rotation ticket, the answer to the sign-in - as the worker wrote it behind the response
     * ({@see self::orderKeyOf()}). HTTP replies, the other answers to browsers, DB sync, the other lifecycle reports,
     * the stream's answers and the other control frames are handled at once; none of them is read against what the
     * master has not read yet.
     *
     * @param WorkerDTO $workerDTO Frame just parsed
     * @return bool Whether it waits
     */
    private function waitsForRtSyncStream(WorkerDTO $workerDTO): bool
    {
        if ($workerDTO instanceof WorkerRtSyncStreamDTO) {
            return $this->heldForRtSyncStream !== '' && $workerDTO->frame->isPart();
        }
        $orderKey = self::orderKeyOf($workerDTO);
        if ($orderKey !== null && isset($this->heldOrderKeys[$orderKey])) {
            return true;
        }
        $signal = $workerDTO instanceof WorkerAgentMessageDTO ? $workerDTO->signal : null;
        $signalType = $signal?->signalType->getType();
        $answer = $signal?->data;
        $inTurn = $signalType === SignalTypeConstants::AGENT_SIGNAL || $signalType === SignalTypeConstants::COMMAND_REPLY
            || $signalType === SignalTypeConstants::PAGE_ACCESS_REASSESS_SESSION
            || ($signalType === SignalTypeConstants::WS_USER && $answer instanceof WebSocketSignalData
                && $answer->data instanceof HandshakeResponseSignalData)
            || $workerDTO instanceof WorkerAgentStoppedDTO || $workerDTO instanceof WorkerRtSourceReleasedDTO
            || $workerDTO instanceof WorkerPageAccessReassessMessageDTO
            || $workerDTO instanceof WorkerPageAccessReassessConnectionsMessageDTO || $workerDTO instanceof WorkerTestCronFiredDTO;

        return $inTurn && ($this->heldForRtSyncStream !== '' || $this->rtSyncStream?->isReading() === true);
    }

    /**
     * Names what a frame of this worker keeps its order with while a held frame names the same
     * ({@see self::waitsForRtSyncStream()}): the agent whose start or stop it reports
     * ({@see self::lifecycleAgentIdOf()}), or the socket an answer to one browser is addressed to. An agent and a
     * socket are named apart, so neither stands for the other.
     *
     * @param WorkerDTO $workerDTO Frame just parsed
     * @return ?string What the frame keeps its order with, or null when it names no agent's start or stop and no socket
     */
    private static function orderKeyOf(WorkerDTO $workerDTO): ?string
    {
        $agentId = self::lifecycleAgentIdOf($workerDTO);
        if ($agentId !== null) {
            return self::ORDER_OF_AGENT . $agentId;
        }
        if (!$workerDTO instanceof WorkerAgentMessageDTO || $workerDTO->signal->signalType->getType() !== SignalTypeConstants::WS_USER) {
            return null;
        }
        $answer = $workerDTO->signal->data;
        if (!$answer instanceof WebSocketSignalData || $answer->targetAcceptKey === null) {
            return null;
        }

        return self::ORDER_OF_SOCKET . $answer->targetAcceptKey;
    }

    /**
     * Names the agent whose start or stop a frame of this worker reports: the report of its start, of a start that
     * did not finish, or of its stop.
     *
     * @param WorkerDTO $workerDTO Frame just parsed
     * @return ?string Id of the agent, or null when the frame reports no agent's start or stop
     */
    private static function lifecycleAgentIdOf(WorkerDTO $workerDTO): ?string
    {
        if ($workerDTO instanceof WorkerAgentStartedDTO || $workerDTO instanceof WorkerAgentStartFailedDTO
            || $workerDTO instanceof WorkerAgentStoppedDTO) {
            return $workerDTO->agentId;
        }

        return null;
    }

    /**
     * Handle worker register message
     *
     * @param WorkerRegisterDTO $dto Signal data
     * @throws LogicException When the active upload ledger cannot bind this process incarnation
     */
    private function handleWorkerRegisterMessage(WorkerRegisterDTO $dto): void
    {
        $this->setWorkerIndex($dto->workerIndex);
        $this->setIsMonopolistic($dto->monopolistic);
        $this->isRegistered = true;
        $this->registrationTimer?->close();
        $this->registrationTimer = null;
        $this->registrationDue = false;
        if ($this->uploadMemoryPoolSink instanceof UploadMemoryDeliverySink) {
            $this->workerServer->registerUploadWorker($this);
        }

        // Log worker registration on daemon side
        $workerType = $dto->monopolistic ? WorkerConstants::TYPE_MONOPOLISTIC : WorkerConstants::TYPE_REGULAR;
        Logger::debug("Worker #{$dto->workerIndex} registered [type={$workerType}]");

        // Send registration confirmation to worker using DTO
        $responseDto = new WorkerRegisteredDTO(
            workerIndex: $dto->workerIndex,
            monopolistic: $dto->monopolistic,
        );
        $this->send($responseDto->toJson());
        $this->workerServer->noteWorkerRegistered($this);
    }

    /**
     * Handle the write level a worker reports.
     *
     * The master cannot read the setting behind this itself - it is forbidden the database - so
     * what a worker tells it is the only word it gets. The applier writes a line only when the
     * value differs from the one in force, which is what keeps a node with several workers from
     * saying the same thing once per worker.
     *
     * A name that is not a level changes nothing: it can only mean the frame was built wrong, and
     * silencing daemon.log on the strength of a malformed frame is worse than ignoring it.
     *
     * @param WorkerLogWriteLevelDTO $dto Level the worker writes from
     */
    private function handleWorkerLogWriteLevelMessage(WorkerLogWriteLevelDTO $dto): void
    {
        $level = LogLevel::fromName($dto->level);
        if ($level === null) {
            Logger::error("Worker reported an unknown log write level: {$dto->level}");

            return;
        }

        LogWriteLevelApplier::applyReported($level, $this->getWorkerIndex());
    }

    /**
     * @param WorkerProtectedModeSettingsDTO $dto Setting reported by this worker
     */
    private function handleWorkerProtectedModeSettingsMessage(WorkerProtectedModeSettingsDTO $dto): void
    {
        Hilos::$cluster?->protectedModeSettingsSink()?->onProtectedModeSettings($dto->manualRestartIsNormal);
    }

    /**
     * @param WorkerDeliverySettingsDTO $dto Effective window reported by this channel
     * @throws InvalidFormatException When the channel is unregistered or the window is invalid
     */
    private function handleWorkerDeliverySettingsMessage(WorkerDeliverySettingsDTO $dto): void
    {
        if (!$this->isRegistered) {
            throw new InvalidFormatException('Delivery settings require a registered worker');
        }

        $this->deliverySettingsSink?->onDeliverySettings($this, $dto->windowMs);
    }

    /**
     * @param WorkerUploadSettingsDTO $dto Effective budget reported by this channel
     * @throws HilosException When the master upload owner cannot apply the report
     * @throws InvalidFormatException When an unregistered connection presents the control frame
     */
    private function handleWorkerUploadSettingsMessage(WorkerUploadSettingsDTO $dto): void
    {
        if (!$this->isRegistered) {
            throw new InvalidFormatException('Upload settings require a registered worker');
        }

        $this->uploadMemoryPoolSink?->onUploadSettings($this, $dto->memoryPoolBytes);
    }

    /**
     * @param WorkerUploadConsumedDTO $dto Terminal receipts reported by this channel
     * @throws HilosException When the master upload owner cannot apply the report
     * @throws InvalidFormatException When an unregistered connection presents the control frame
     */
    private function handleWorkerUploadConsumedMessage(WorkerUploadConsumedDTO $dto): void
    {
        if (!$this->isRegistered) {
            throw new InvalidFormatException('Upload consumption requires a registered worker');
        }

        $this->uploadMemoryPoolSink?->onUploadConsumed($this, $dto->receiptIds);
    }

    /**
     * Handle agent started message
     *
     * A report naming an agent the roster does not have is answered here rather than allowed
     * out, because the guard above this one is the wrong size for it: an exception leaving this
     * method reaches {@see DaemonManager::onClientRead()}, which discards the client - and the
     * client is the whole worker. One stale line about one agent would cost the process and
     * every other agent on it, which is how a freeze came to kill healthy workers.
     *
     * It is a race and not only a bug. A freeze deregisters an agent while its start report is
     * already on the wire, so the report lands in a roster that no longer names it; the stop
     * the freeze sent is on its way down the same link. The stop below is for the other case,
     * where nothing was sent: an agent the master does not know must not go on running,
     * ticking and holding truth sources where nothing will ever address or stop it. Sending it
     * twice is harmless, and saying nothing at all is not.
     *
     * @param WorkerAgentStartedDTO $dto DTO with agent started data
     * @throws AgentDaemonCreationFailedException If agent creation fails
     * @throws RtActionsStateCollectionNullException When the cluster registry is unavailable
     * @throws EventLoopException When a live restart caller deadline cannot be armed or canceled
     */
    private function handleAgentStartedMessage(WorkerAgentStartedDTO $dto): void
    {
        try {
            $this->agentManager->handleAgentStarted($dto);
        } catch (AgentDaemonNotRegisteredException $notRegistered) {
            // Kept loud: the roster missing an agent a worker just started is worth a line
            // whichever of the two cases produced it.
            Logger::error(
                "Worker #{$this->workerIndex} reported a start of '{$dto->agentId}', which this"
                . ' roster does not have; stopping it there: ' . $notRegistered->getMessage(),
            );

            $orphan = AgentId::fromId($dto->agentId);
            $this->sendAgentStop($orphan->type, $orphan->index);
        }

        // Consume this actual roster fact inside the client guard, before the next frame of
        // a burst. Waiting for an outer pass once stretched a five-second hold to sixteen.
        Hilos::$cluster?->protectedModeEntryGate()?->dispatchReady();
    }

    /**
     * Handle agent start failed message
     *
     * @param WorkerAgentStartFailedDTO $dto DTO naming the agent whose start did not finish, and why
     * @throws InvalidArgumentException When the master cannot name an answer it owes a held frame
     * @throws EventLoopException When a live restart caller deadline cannot be armed or canceled
     */
    private function handleAgentStartFailedMessage(WorkerAgentStartFailedDTO $dto): void
    {
        $this->agentManager->handleAgentStartFailed($dto);
        Hilos::$cluster?->protectedModeEntryGate()?->dispatchReady();
    }

    /**
     * Confirm an awaited stop even when its roster entry was already removed.
     * Reports still on the roster are logged by the manager; a consumed wait cannot log twice.
     *
     * @param WorkerAgentStoppedDTO $dto Stop reported by this worker connection
     * @throws InvalidFormatException When the start sink cannot resolve a pending page recovery
     * @throws EventLoopException When the held freeze's settle deadline cannot be canceled
     */
    private function handleAgentStoppedMessage(WorkerAgentStoppedDTO $dto): void
    {
        $awaited = $this->awaitsStopReport($dto->agentId);
        unset($this->stopsAwaitingReport[$dto->agentId]);
        if ($awaited) {
            $this->workerServer->noteWorkerStopReport($this, $dto->agentId);
        }
        if ($awaited && !$this->agentManager->hasAgent($dto->agentId)) {
            Logger::info("Agent '{$dto->agentId}' stopped on worker #{$this->workerIndex}");
        }
        $this->agentManager->handleAgentStopped($dto);
        Hilos::$cluster?->protectedModeEntryGate()?->dispatchReady();
    }

    /**
     * Handle agent_message (worker signal) message from worker
     *
     * Forwards to AgentManagerDaemon which queues the signal in daemon's SignalRouter.
     *
     * @param WorkerAgentMessageDTO $dto DTO with agent message data
     */
    private function handleAgentMessageMessage(WorkerAgentMessageDTO $dto): void
    {
        $this->agentManager->handleAgentMessage($dto);
    }

    /**
     * Handle DB sync created message from worker (worker-level broadcast).
     *
     * @param WorkerDbSyncCreatedMessageDTO $dto DTO with sync created data
     */
    private function handleWorkerDbSyncCreatedMessage(WorkerDbSyncCreatedMessageDTO $dto): void
    {
        $this->agentManager->handleWorkerDbSyncCreated($dto);
    }

    /**
     * Handle DB sync updated message from worker (worker-level broadcast).
     *
     * @param WorkerDbSyncUpdatedMessageDTO $dto DTO with sync updated data
     */
    private function handleWorkerDbSyncUpdatedMessage(WorkerDbSyncUpdatedMessageDTO $dto): void
    {
        $this->agentManager->handleWorkerDbSyncUpdated($dto);
    }

    /**
     * Handle DB sync deleted message from worker (worker-level broadcast).
     *
     * @param WorkerDbSyncDeletedMessageDTO $dto DTO with sync deleted data
     */
    private function handleWorkerDbSyncDeletedMessage(WorkerDbSyncDeletedMessageDTO $dto): void
    {
        $this->agentManager->handleWorkerDbSyncDeleted($dto);
    }

    /**
     * Handle DB sync cleared message from worker (worker-level broadcast).
     *
     * @param WorkerDbSyncClearedMessageDTO $dto DTO with cleared collection data
     */
    private function handleWorkerDbSyncClearedMessage(WorkerDbSyncClearedMessageDTO $dto): void
    {
        $this->agentManager->handleWorkerDbSyncCleared($dto);
    }

    /**
     * Handle the whole-database re-hydrate announcement from a worker (HIL-479).
     *
     * The frame carries nothing but its announcer, so nothing is unwrapped here beyond that: the
     * daemon has to learn that the database was replaced, pass the fact on, and remember who to
     * report back to once every process has re-read (HIL-436).
     *
     * @param WorkerDbReHydrateMessageDTO $dto Announcement naming the agent that replaced the database, if any
     */
    private function handleWorkerDbReHydrateMessage(WorkerDbReHydrateMessageDTO $dto): void
    {
        $this->agentManager->handleWorkerDbReHydrate($dto->agentId);
    }

    /**
     * Handle the access re-decision announcement from a worker (HIL-644).
     *
     * The frame names a person and no page, because the announcing worker holds neither the
     * other workers' subscriptions nor an answer to who is behind a connection. Nothing is
     * decided here: the master queues the fact and fans it back out to every worker of the
     * node, each of which sweeps its own mirror.
     *
     * @param WorkerPageAccessReassessMessageDTO $dto Announcement naming the user whose rights changed
     * @throws InvalidArgumentException When the queued announcement carries an empty name
     */
    private function handleWorkerPageAccessReassessMessage(WorkerPageAccessReassessMessageDTO $dto): void
    {
        $this->agentManager->handleWorkerPageAccessReassess($dto);
    }

    /**
     * Handle the by-connection re-decision announcement from a worker (HIL-652).
     *
     * The frame names sockets and no page, and unlike its twin it names nobody at all - that is
     * what lets it outlive the write it follows. Nothing is decided here: the master queues the
     * fact and fans it back out to every worker of the node.
     *
     * @param WorkerPageAccessReassessConnectionsMessageDTO $dto Announcement naming the connections that lost their person
     * @throws InvalidArgumentException When the queued announcement carries an empty name
     */
    private function handleWorkerPageAccessReassessConnectionsMessage(
        WorkerPageAccessReassessConnectionsMessageDTO $dto,
    ): void {
        $this->agentManager->handleWorkerPageAccessReassessConnections($dto);
    }

    /**
     * Handle one worker's answer to the re-hydrate announcement (HIL-436).
     *
     * The answering worker is identified by the link the frame arrived on, not by the frame, so
     * a worker cannot answer on somebody else's behalf.
     *
     * @param WorkerDbReHydratedDTO $dto That worker's verdict on re-reading its collections
     */
    private function handleWorkerDbReHydratedMessage(WorkerDbReHydratedDTO $dto): void
    {
        $this->agentManager->handleWorkerDbReHydrated($this->workerIndex, $dto);
    }

    /**
     * Send the aggregated re-hydrate verdict to the worker hosting the announcing agent (HIL-436), as a
     * frame naming that agent: behind a signal to it still waiting for its turn, as it stood before.
     *
     * @param DbReHydrateCompleteDTO $dto Verdict addressed to the agent that announced the swap
     */
    public function sendDbReHydrateComplete(DbReHydrateCompleteDTO $dto): void
    {
        Logger::debug(
            "Sending db_rehydrate_complete signal to worker [agentId={$dto->agentId}]"
            . " [complete=" . ($dto->complete ? 'true' : 'false') . "] [workerIndex={$this->workerIndex}]",
        );

        if ($dto->agentId === null) {
            $this->send($dto->toJson());

            return;
        }
        $this->sendNamingAgent($dto->agentId, $dto->toJson());
    }

    /**
     * The ordinary RT stream of this worker link, both directions: the worker's own writes in,
     * and this node's writes out to it. Opened on first use, so the link instance is its epoch.
     * Once it has read every part it took, the frames set aside behind them go back to the head of the
     * buffer and are parsed again, in their order, ahead of what arrived after them. A row it holds
     * after refusing the worker's write is marked by the master, which tells the other workers that
     * read the row; without a master's mark it is marked in this process alone.
     *
     * @return RtSyncStreamChannel Stream of this link
     */
    public function rtSyncStream(): RtSyncStreamChannel
    {
        if ($this->rtSyncStream === null) {
            $link = WeakReference::create($this);
            $hold = $this->rtSyncStreamHold;
            $this->rtSyncStream = new RtSyncStreamChannel(
                static function (RtSyncStreamFrame $frame) use ($link): void {
                    $link->get()?->send((new WorkerRtSyncStreamDTO($frame))->toJson());
                },
                static function (RtSyncStreamOperation $operation, Closure $settled) use ($link): void {
                    $client = $link->get();
                    if ($client === null) {
                        $settled(false);

                        return;
                    }
                    $client->agentManager->handleWorkerRtSyncStream($operation, $settled);
                },
                $this->rtSyncStreamWake,
                static function () use ($link): void {
                    $client = $link->get();
                    if ($client === null || $client->heldForRtSyncStream === '') {
                        return;
                    }
                    // A real release is one new parsing opportunity, even without another worker write.
                    $client->readBuffer = $client->heldForRtSyncStream . $client->readBuffer;
                    $client->heldForRtSyncStream = '';
                    $client->heldOrderKeys = [];
                    $client->continueReadBuffer();
                },
                staleness: $hold === null ? null : static function (
                    string $collectionKey,
                    array $stateIds,
                    ?float $since,
                ) use ($link, $hold): void {
                    $client = $link->get();
                    if ($client !== null) {
                        $hold($client->workerIndex, $collectionKey, $stateIds, $since);
                    }
                },
            );
        }

        return $this->rtSyncStream;
    }

    /**
     * @param ?Closure(RtSyncStreamChannel): void $wake The master's pass this link's stream is pumped on
     */
    public function setRtSyncStreamWake(?Closure $wake): void
    {
        $this->rtSyncStreamWake = $wake;
    }

    /**
     * @param ?Closure(int, string, list<string>, ?float): void $hold The master's mark of rows this link's stream holds,
     *        given this link's worker
     */
    public function setRtSyncStreamHold(?Closure $hold): void
    {
        $this->rtSyncStreamHold = $hold;
    }

    /**
     * Handle the RT collections a started agent owns, reported by its worker.
     *
     * @param WorkerRtSourceRegisteredDTO $dto DTO with the agent and the collections it took
     */
    private function handleWorkerRtSourceRegisteredMessage(WorkerRtSourceRegisteredDTO $dto): void
    {
        $this->agentManager->handleRtSourceRegistered($dto);
    }

    /**
     * Handle the release of the RT collections a stopped agent owned.
     *
     * @param WorkerRtSourceReleasedDTO $dto DTO with the agent that stopped
     */
    private function handleWorkerRtSourceReleasedMessage(WorkerRtSourceReleasedDTO $dto): void
    {
        $this->agentManager->handleRtSourceReleased($dto);
    }

    /**
     * Handle everything one worker reads, and answer the collections it has just taken up.
     *
     * The two steps are one and nothing may be written to this worker between them: the map
     * entry is what starts addressing deltas here, and a delta reaching the worker before the
     * answer would land on a collection it holds nothing of. Sending the answer second is what
     * makes that impossible - by the time the frame after it can be produced, the state it
     * applies to is already queued ahead of it on this same socket.
     *
     * What the answer carries is where the two kinds part (HIL-750). An RT collection is sent
     * its rows, because the master's replica is the only copy the worker can be given. A DB
     * collection is sent a bare confirmation: its rows are in the database the worker reads for
     * itself, so the only thing it is missing is the word that it is in the map.
     *
     * @param WorkerSourceInterestDTO $dto DTO with every RT and DB collection that worker reads
     */
    private function handleWorkerSourceInterestMessage(WorkerSourceInterestDTO $dto): void
    {
        $added = $this->agentManager->handleSourceInterest($dto, $this->workerIndex);

        foreach ($added[SourceChange::KIND_RT] as $collectionKey) {
            $this->send((new WorkerRtSnapshotMessageDTO(
                collectionKey: $collectionKey,
                rows: RtSnapshot::rows($collectionKey),
                staleRows: RtStaleness::staleRows($collectionKey),
            ))->toJson());
        }

        foreach ($added[SourceChange::KIND_DB] as $collectionKey) {
            $this->send((new WorkerDbInterestReadyMessageDTO(collectionKey: $collectionKey))->toJson());
        }
    }

    /**
     * Records a group membership the worker admitted.
     *
     * The master keeps the registry every fan-out is resolved against, but it stopped writing
     * to it off the client frame: a join is judged in the worker that owns the group, and only
     * that side knows who is behind the socket and what full name their identity builds. So
     * this is the master doing as it is told, and the frame arrives only for a join that has
     * already passed the group's own admission.
     *
     * @param WorkerGroupJoinDTO $dto DTO with the full group name, the connection and the join params
     */
    private function handleGroupJoinMessage(WorkerGroupJoinDTO $dto): void
    {
        Hilos::$sr?->subscribeToGroup($dto->data->group, new WebSocketGroupSubscribeSignalDTO(
            acceptKey: $dto->data->acceptKey,
            group: $dto->data->group,
            params: $dto->data->params,
        ));
    }

    /**
     * Drops every group membership of connections whose person changed, then has it fanned out (HIL-1284).
     *
     * Applied to the master's registry at receipt, as {@see handleGroupJoinMessage()} records a
     * join, so the two take effect in the order the worker sent them. Queued instead, the leave
     * would be overtaken by the join the same worker sends right after it - the block notice's
     * export group - and would then erase that join on its way through. Only the fan-out to the
     * other workers and the other nodes is queued, naming this worker as the one to skip.
     *
     * @param WorkerGroupLeaveAllDTO $dto Connections leaving every group
     * @throws InvalidArgumentException When the queued fan-out carries an empty name
     */
    private function handleGroupLeaveAllMessage(WorkerGroupLeaveAllDTO $dto): void
    {
        foreach ($dto->data->acceptKeys as $acceptKey) {
            Hilos::$sr?->unsubscribeFromAllGroups($acceptKey);
        }

        $this->agentManager->handleWorkerGroupLeaveAll($dto, $this->getWorkerIndex());
    }

    /**
     * @param WorkerAgentRestartRequestDTO $dto Page-admitted restart request
     * @throws InvalidFormatException When an unregistered connection presents this control frame
     * @throws LogicException When its server has no page restart door
     * @throws EnvException When the master cannot read the local cluster configuration
     * @throws ClusterConfigurationException When the local cluster identity is invalid
     * @throws ClusterDisabledException When an enabled cluster refuses its identity
     */
    private function handleAgentRestartRequestMessage(WorkerAgentRestartRequestDTO $dto): void
    {
        if (!$this->isRegistered) {
            throw new InvalidFormatException('Agent restart request requires a registered worker');
        }
        $this->workerServer->getPageAgentRestartDoor()->requestPageAgentRestart($dto->data);
    }

    /**
     * Handle protected-mode enable request from an initiator worker.
     *
     * Hands the carried initiator identity to this node's freeze switch, which freezes the single
     * node on the spot, or enables locally when this node leads a cluster and forwards the request
     * to the current leader otherwise. A node without a switch ignores the request.
     *
     * It goes through {@see ProtectedModeEntryGate} rather than straight to the switch, so a
     * freeze asked for while the lift before it is still bringing agents back waits for them
     * instead of taking the roster down mid-start (HIL-1000). A node that registered no gate is
     * handed to the switch as before - the gate is the daemon's, and a process without one has no
     * roster to protect either.
     *
     * @param WorkerProtectedModeEnableDTO $dto DTO with the initiator identity and operation
     * @throws EventLoopException When the held freeze's settle deadline cannot be armed or canceled
     */
    private function handleProtectedModeEnableMessage(WorkerProtectedModeEnableDTO $dto): void
    {
        $gate = Hilos::$cluster?->protectedModeEntryGate();
        if ($gate === null) {
            Hilos::$cluster?->protectedMode()?->requestEnable($dto->data);

            return;
        }

        $gate->requestEnter($dto->data);
    }

    /**
     * Handle protected-mode disable request from an initiator worker.
     *
     * Hands the carried initiator identity to this node's freeze switch, which authorizes the
     * release against the recorded initiator on a single node, or lifts locally when this node
     * leads and forwards to the current leader otherwise. A node without a switch ignores it.
     *
     * @param WorkerProtectedModeDisableDTO $dto DTO with the identity of the agent asking for the release
     */
    private function handleProtectedModeDisableMessage(WorkerProtectedModeDisableDTO $dto): void
    {
        Hilos::$cluster?->protectedMode()?->requestDisable($dto->data);
    }

    /**
     * Handle protected-mode verify request from an initiator worker.
     *
     * The frame an initiator sends when its destructive operation ends: the freeze moves to its
     * verification window rather than lifting, so the system opens to pass holders only. Routed
     * and authorized exactly like the disable above.
     *
     * @param WorkerProtectedModeVerifyDTO $dto DTO with the identity of the agent asking for the window
     */
    private function handleProtectedModeVerifyMessage(WorkerProtectedModeVerifyDTO $dto): void
    {
        Hilos::$cluster?->protectedMode()?->requestVerify($dto->data);
    }

    /**
     * Handle a protected-mode progress mark from an initiator worker.
     *
     * The one frame of the set that asks for nothing: it stamps the freeze row so a watchdog can
     * tell an operation that is legitimately long from one that hung. Routed and authorized
     * exactly like the requests around it, and dropped just as quietly when this node holds no
     * freeze - a mark under no freeze is a report to nobody, not an error.
     *
     * @param WorkerProtectedModeProgressDTO $dto DTO with the identity of the agent reporting the progress
     */
    private function handleProtectedModeProgressMessage(WorkerProtectedModeProgressDTO $dto): void
    {
        Hilos::$cluster?->protectedMode()?->requestProgress($dto->data);
    }

    /**
     * Handle protected-mode pass request from an initiator worker.
     *
     * Records one more minted pass on the freeze row. Only its hash travels here; the clear key
     * never leaves the operator's terminal.
     *
     * @param WorkerProtectedModePassDTO $dto DTO with the minting agent identity and the pass hash
     */
    private function handleProtectedModePassMessage(WorkerProtectedModePassDTO $dto): void
    {
        Hilos::$cluster?->protectedMode()?->requestPass($dto->data);
    }

    /**
     * Handle the verifier circle an initiator worker photographed under the freeze.
     *
     * Writes the photograph on the freeze row, whole: the circle is read once, in the worker that
     * may reach the database, and the row that decides admission belongs to this master. What
     * arrives is already hashes and a count - no address of anybody named ever reaches this side.
     *
     * @param WorkerProtectedModeCircleDTO $dto DTO with the photographing agent identity and the circle it saw
     */
    private function handleProtectedModeCircleMessage(WorkerProtectedModeCircleDTO $dto): void
    {
        Hilos::$cluster?->protectedMode()?->requestCircle($dto->data);
    }

    /**
     * Handle protected-mode refreeze request from an initiator worker.
     *
     * The other exit from the verification window: the system closes back to a full freeze, every
     * pass is void, and another destructive operation may run.
     *
     * @param WorkerProtectedModeRefreezeDTO $dto DTO with the identity of the agent asking to close back
     */
    private function handleProtectedModeRefreezeMessage(WorkerProtectedModeRefreezeDTO $dto): void
    {
        Hilos::$cluster?->protectedMode()?->requestRefreeze($dto->data);
    }

    /**
     * Send message to worker, at once: the stream's own parts and answers, the agents' lifecycle,
     * readiness of a source interest, snapshots, sync relays and control frames alike. Only what the
     * worker hands to an agent or to its pages to read against the RT copy waits for the writes
     * streamed before it ({@see self::sendInTurn()}), and so does a frame naming an agent such a
     * frame still waits for ({@see self::sendNamingAgent()}).
     *
     * @param string $message Message to send
     */
    public function send(string $message): void
    {
        $this->queueWrite($message . "\n");
    }

    /**
     * Send a message the worker hands to an agent or to its pages to read against its RT copy - a
     * signal to an agent ({@see WorkerServer::sendSignalToAgent()}), an access re-decision of pages
     * ({@see DaemonManager::walkWorkerFrame()}). Once this link's RT stream is open, it goes in its
     * turn among the stream's operations ({@see RtSyncStreamChannel::writeInTurn()}): a write streamed
     * to the worker before it is out whole first, so the worker reads, say, the signal an agent sent
     * right after its RT write only behind that write - and holds it until the write is applied. Its
     * turn come, it is written by {@see self::send()}; as on a peer link ({@see PeerLink::sendFrame()}),
     * nothing else waits but a frame naming an agent a waiting frame names: a stop written right after
     * a signal to the same agent goes behind it ({@see self::sendNamingAgent()}), as the worker holds
     * it on receipt ({@see WorkerManager::nextDaemonMessage()}). The agent counts as waited for until
     * the frame naming it is written; the stream writes every frame waiting in it in the end, behind an
     * operation dropped or refused as well.
     *
     * @param string $message Message to send
     * @param ?string $agentId Agent the message names, or null when it names none
     */
    public function sendInTurn(string $message, ?string $agentId = null): void
    {
        if ($this->rtSyncStream === null) {
            $this->send($message);

            return;
        }
        if ($agentId !== null) {
            $this->waitingInTurn[$agentId] = ($this->waitingInTurn[$agentId] ?? 0) + 1;
        }
        $link = WeakReference::create($this);
        $this->rtSyncStream->writeInTurn(static function () use ($link, $message, $agentId): void {
            $client = $link->get();
            if ($client === null) {
                return;
            }
            if ($agentId !== null && --$client->waitingInTurn[$agentId] === 0) {
                unset($client->waitingInTurn[$agentId]);
            }
            $client->send($message);
        });
    }

    /**
     * Send a frame naming an agent - its stop, the answer to its protected-mode entry, the verdict on
     * its re-hydrate announcement: at once, unless a frame naming the same agent waits for its turn behind this link's stream
     * ({@see self::sendInTurn()}); then it goes behind that frame, so the worker handles a stop after
     * a signal sent to the agent before it, or after its start, and not with no agent left for the signal.
     *
     * @param string $agentId Agent the frame names
     * @param string $message Message to send
     */
    private function sendNamingAgent(string $agentId, string $message): void
    {
        if (isset($this->waitingInTurn[$agentId])) {
            $this->sendInTurn($message, $agentId);

            return;
        }
        $this->send($message);
    }

    /**
     * Send a change of rows' freshness on the master's copy ({@see WorkerRtStalenessMessageDTO}). A thaw
     * goes in its turn behind the writes streamed to the worker before it ({@see self::sendInTurn()}): the
     * write that lifted the mark is out whole first, so the worker never reads a row as current ahead of
     * the write that made it so, and the worker holds the thaw until its stream applied what it read
     * ({@see WorkerManager::nextDaemonMessage()}). A freeze goes at once, and the rows it freezes leave a
     * thaw still waiting here: written after the freeze, that thaw would undo it on the worker's copy. A
     * later thaw of a row takes it over from an earlier one, so the row thaws behind the later write.
     *
     * @param WorkerRtStalenessMessageDTO $staleness Rows that froze, or thawed
     * @param string $message The same frame packed
     */
    public function sendRtStaleness(WorkerRtStalenessMessageDTO $staleness, string $message): void
    {
        $collectionKey = $staleness->collectionKey;
        if ($staleness->since !== null || $this->rtSyncStream === null) {
            foreach ($staleness->stateIds as $stateId) {
                unset($this->thawsInTurn[$collectionKey][$stateId]);
            }
            if (($this->thawsInTurn[$collectionKey] ?? null) === []) {
                unset($this->thawsInTurn[$collectionKey]);
            }
            $this->send($message);

            return;
        }
        $thaw = ++$this->thawSequence;
        foreach ($staleness->stateIds as $stateId) {
            $this->thawsInTurn[$collectionKey][$stateId] = $thaw;
        }
        $link = WeakReference::create($this);
        $this->rtSyncStream->writeInTurn(static function () use ($link, $staleness, $message, $thaw): void {
            $client = $link->get();
            if ($client === null) {
                return;
            }
            $thawed = [];
            foreach ($staleness->stateIds as $stateId) {
                if (($client->thawsInTurn[$staleness->collectionKey][$stateId] ?? null) === $thaw) {
                    unset($client->thawsInTurn[$staleness->collectionKey][$stateId]);
                    $thawed[] = $stateId;
                }
            }
            if (($client->thawsInTurn[$staleness->collectionKey] ?? null) === []) {
                unset($client->thawsInTurn[$staleness->collectionKey]);
            }
            if ($thawed !== []) {
                $client->send($thawed === $staleness->stateIds
                    ? $message : new WorkerRtStalenessMessageDTO($staleness->collectionKey, $thawed, null)->toJson());
            }
        });
    }

    /**
     * Send agent_start signal to worker, in its turn behind the writes streamed to the worker before it
     * ({@see self::sendInTurn()}): the new instance reads and writes the rows an old one wrote, and the
     * worker applies a streamed write to a row whoever holds it, so the old instance's write must not
     * reach the worker after the start - the new one would read the row without it, and its own write
     * would be overwritten on the worker's copy. The worker holds the start the same way until its
     * stream applied what it read ({@see WorkerManager::nextDaemonMessage()}), and a frame naming the
     * agent goes behind the start ({@see self::sendNamingAgent()}).
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (optional)
     * @param list<string> $liveAcceptKeys Accept keys of the node's live sockets, for the roster reconcile (HIL-664)
     */
    public function sendAgentStart(string $agentType, ?string $agentIndex = null, array $liveAcceptKeys = []): void
    {
        // external-boundary: the neutral element of the agent id — a singleton is the bare type
        $agentId = $agentType . ($agentIndex !== null ? ":{$agentIndex}" : '');
        Logger::debug("Sending agent_start signal to worker [agentId={$agentId}] [agentType={$agentType}]"
            . ' [agentIndex=' . ($agentIndex ?? 'null') . "] [workerIndex={$this->workerIndex}]");

        $dto = new AgentStartDTO(
            agentId: $agentId,
            liveAcceptKeys: $liveAcceptKeys,
        );

        $this->sendInTurn($dto->toJson(), $agentId);
    }

    /**
     * Send agent_stop signal to worker
     * The link remembers the stop until the worker reports it ({@see self::awaitsStopReport()}).
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (optional)
     */
    public function sendAgentStop(string $agentType, ?string $agentIndex = null): void
    {
        // external-boundary: the neutral element of the agent id — a singleton is the bare type
        $agentId = $agentType . ($agentIndex !== null ? ":{$agentIndex}" : '');
        $this->stopsAwaitingReport[$agentId] = true;
        Logger::debug("Sending agent_stop signal to worker [agentId={$agentId}] [workerIndex={$this->workerIndex}]");

        $dto = new AgentStopDTO(
            agentId: $agentId,
        );

        $this->sendNamingAgent($agentId, $dto->toJson());
    }

    /**
     * Whether this link was sent agent_stop for the agent and has not heard the worker report it stopped yet.
     * A worker handles its frames in order, so the report also means everything sent to that agent
     * before the stop has been handled (HIL-1314).
     *
     * @param string $agentId Agent whose stop report is awaited
     * @return bool True while the worker has not reported the requested stop
     */
    public function awaitsStopReport(string $agentId): bool
    {
        return isset($this->stopsAwaitingReport[$agentId]);
    }

    /**
     * Send protected-mode ready relay to worker for a specific initiator agent.
     *
     * @param string $agentType Initiator agent type
     * @param ?string $agentIndex Initiator agent index, or null for a singleton agent
     */
    public function sendProtectedModeReady(string $agentType, ?string $agentIndex = null): void
    {
        // external-boundary: the neutral element of the agent id — a singleton is the bare type
        $agentId = $agentType . ($agentIndex !== null ? ":{$agentIndex}" : '');
        Logger::debug("Sending protected_mode_ready signal to worker [agentId={$agentId}] [workerIndex={$this->workerIndex}]");

        $dto = new ProtectedModeReadyDTO(
            agentId: $agentId,
        );

        $this->sendNamingAgent($agentId, $dto->toJson());
    }

    /**
     * Send protected-mode refused relay to worker for a specific initiator agent.
     *
     * @param string $agentType Initiator agent type
     * @param ?string $agentIndex Initiator agent index, or null for a singleton agent
     * @param string $reason Human-readable operator-facing refusal message
     */
    public function sendProtectedModeRefused(string $agentType, ?string $agentIndex, string $reason): void
    {
        // external-boundary: the neutral element of the agent id — a singleton is the bare type
        $agentId = $agentType . ($agentIndex !== null ? ":{$agentIndex}" : '');
        Logger::debug("Sending protected_mode_refused signal to worker [agentId={$agentId}] [workerIndex={$this->workerIndex}]");

        $dto = new ProtectedModeRefusedDTO(
            agentId: $agentId,
            reason: $reason,
        );

        $this->sendNamingAgent($agentId, $dto->toJson());
    }

    /**
     * Consumes a due registration fact under the client's existing owner guard.
     * Unbound fixtures retain the direct check; bound traffic never checks the clock.
     */
    public function onTick(): void
    {
        // Skip if already registered or already closing
        if ($this->isRegistered || $this->shouldClose) {
            return;
        }

        if ($this->registrationTimer !== null && !$this->registrationDue) {
            return;
        }

        $this->registrationDue = false;
        $currentTime = hrtime(true) / TimeConstants::NS_PER_MILLISECOND / TimeConstants::MS_PER_SECOND;
        if (($currentTime - $this->connectTime) >= $this->registrationTimeout) {
            Logger::error("Worker client registration timeout exceeded, disconnecting");
            $this->markShouldClose();
        }
    }

    /**
     * Called when socket connection is successfully closed.
     *
     * A worker that leaves mid-round is taken off the re-hydrate barrier rather than waited for
     * (HIL-436): it cannot answer with a fiction, and whatever starts in its place opens the
     * database that is already in place. Waiting would cost the initiator the full deadline and
     * then close the node over a process that no longer exists.
     *
     * What its agents owned in RT is given back here for the same reason (HIL-586): the release
     * a stopping agent sends never comes from a process that died, and an ownership claim left
     * behind would have this node refusing replicas for a collection nobody here writes.
     *
     * The agents themselves are forgotten last, for the third time that same reason: no stop
     * report is coming, so without it the roster keeps them alive on a client that is gone, and
     * an agent the roster claims is one the master will not restart when it is next addressed.
     * Last, because the two releases above find their work by walking the roster for this
     * worker - emptying it first would leave them nothing to hand back. The guard is the one the
     * log line uses: an index of zero is a client that never registered, and every real worker
     * counts from one, so an unregistered client must not match agents by a zero it shares
     * with nobody.
     *
     * Forgetting them also answers whoever was waiting on one of them mid-start, which is what
     * the raise below is about: a page or a command owed a refusal that cannot be named.
     *
     * @throws InvalidArgumentException When a refusal owed to a frame held for a lost agent cannot be named
     * @throws InvalidFormatException When a tracked delivery has an invalid completion address
     * @throws LogicException When an internal reader-union comparison precedes complete preparation
     * @throws EventLoopException When a live restart caller deadline cannot be armed or canceled
     */
    protected function onClose(): void
    {
        $this->workerServer->testOrmForgetDoor()?->ormForgetWorkerGone($this);
        $this->workerServer->cronFireTestDoor()?->cronFireWorkerGone($this);
        $this->workerServer->daemonNodeReportTestDoor()?->nodeReportWorkerGone($this);

        // No upload receipt is released here: EOF does not prove the process has exited.
        // Delete transport payloads before WorkerServer records the queue-deletion fact.
        $this->discardUploadBuffers();

        // Log worker disconnection on daemon side
        if ($this->workerIndex > 0) {
            $workerType = $this->isMonopolistic ? WorkerConstants::TYPE_MONOPOLISTIC : WorkerConstants::TYPE_REGULAR;
            Logger::debug("Worker #{$this->workerIndex} disconnected [type={$workerType}]");
        }

        $this->agentManager->dropReHydrateParticipant(ReHydrateRound::workerParticipant($this->workerIndex));
        $this->agentManager->releaseRtSourcesOfWorker($this->workerIndex, $this->isMonopolistic);
        $this->agentManager->releaseReaderInterestOfWorker($this->workerIndex);

        if ($this->workerIndex > 0) {
            $lost = $this->agentManager->forgetAgentsOfWorker($this->workerIndex, $this->isMonopolistic);
            if ($lost !== []) {
                Logger::info(
                    "Worker #{$this->workerIndex} died hosting " . count($lost) . ' agent(s): '
                    . implode(', ', $lost),
                );
            }
        }
    }
}
