<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Agents\Hilos\SessionsLibraryAgent;
use Demo\EcommerceShop\Agents\EcommerceShopAgent;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Hilos;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\HilosException;
use Hilos\Socket\WebSocket\DTO\HandshakeResponseSignalData;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use PHPUnit\Framework\TestCase;

/**
 * Base class for integration tests.
 *
 * Requires the MySQL test container running and the test DB reset
 * (composer run test:db-reset).
 */
abstract class IntegrationTestCase extends TestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    /** @var bool Whether the database has been initialized for this test process */
    protected static bool $dbInitialized = false;

    /** @var ?SessionsLibraryAgent Library the sessions themselves live in, built on first use */
    private ?SessionsLibraryAgent $sessionsLibrary = null;

    /**
     * Initializes the database once and registers test truth-source ownership.
     */
    protected function setUp(): void
    {
        parent::setUp();
        if (!self::$dbInitialized) {
            Database::initialize(initHilos: true);
            self::$dbInitialized = true;
        }
        TruthSourceRegistry::register(HilosDbContext::users, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        TruthSourceRegistry::register(HilosDbContext::userRenames, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        // Framework tables these cases write through their real writers - a login, a code - and
        // not through the library that owns them. The guard asks on every table since HIL-716,
        // while a test process runs the writers under this harness id rather than under a
        // library's, so the harness claims them once for everybody.
        TruthSourceRegistry::register(HilosDbContext::sessions, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        TruthSourceRegistry::register(HilosDbContext::identities, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        TruthSourceRegistry::register(HilosDbContext::verifications, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        TruthSourceRegistry::register(HilosDbContext::registrationReservations, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        TruthSourceRegistry::register(HilosDbContext::passkeyCredentials, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        TruthSourceRegistry::register(HilosDbContext::authBlocks, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        TruthSourceRegistry::register(HilosDbContext::legalAcceptances, TruthSourceKeys::all(), self::TEST_AGENT_ID);
    }

    /**
     * Builds the sessions library the session set lives in, once per case.
     *
     * The handshake and the operator's admin:create are addressed to it since HIL-710, and
     * what they conclude reaches the project agent in a frame. Started on first use because
     * {@see SessionsLibraryAgent::onStart()} claims the session set and the users table it
     * mints into.
     *
     * @return SessionsLibraryAgent Library under test, started
     * @throws HilosException When the library's own startup fails
     */
    protected function sessionsLibrary(): SessionsLibraryAgent
    {
        if ($this->sessionsLibrary === null) {
            $this->sessionsLibrary = new SessionsLibraryAgent();
            $this->startAgent($this->sessionsLibrary);
        }

        return $this->sessionsLibrary;
    }

    /**
     * Opens one socket's session the way a node does: in the library, then told to the
     * project.
     *
     * A case calling the project agent directly would find no connection row at all - the
     * handshake is no longer its callback (HIL-710).
     *
     * @param EcommerceShopAgent $holder Agent that holds this project's connections
     * @param WebSocketHandshakeSignalDTO $data Accept key and the daemon-resolved session token
     * @throws HilosException When the handshake or the frame that follows it fails
     * @throws AgentUnknownSignalException When an agent does not know a frame it is handed
     * @throws InvalidArgumentException When a signal put back on the queue has no name
     * @throws InvalidFormatException When a frame's outcome cannot be read back
     */
    protected function deliverHandshake(EcommerceShopAgent $holder, WebSocketHandshakeSignalDTO $data): void
    {
        $library = $this->sessionsLibrary();
        $this->underAgent($library, static fn () => $library->onSignalHandshake($data, '', ''));
        $this->deliverLibraryFrames($holder);
    }

    /**
     * Runs every frame the library queued through the agent it is addressed to.
     *
     * In a node the hop is two workers taking their turn; in a case it is this call, and a
     * case that omits it will find no connection registered.
     * Everything that is not a session frame goes back on the queue in the order it was
     * taken off, so a case can still read the command replies and browser pushes the run
     * produced.
     *
     * @param EcommerceShopAgent $holder Agent that holds this project's connections
     * @throws HilosException When a frame's handler fails
     * @throws AgentUnknownSignalException When an agent does not know a frame it is handed
     * @throws InvalidArgumentException When a signal put back on the queue has no name
     * @throws InvalidFormatException When a frame's outcome cannot be read back
     */
    protected function deliverLibraryFrames(EcommerceShopAgent $holder): void
    {
        $rest = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) instanceof SignalDTO) {
            $data = $signal->data;
            if ($data instanceof AgentSignalData && $data->data instanceof SessionStateSignalData) {
                $holder->onSignalAgent($data, '', $signal->signalName->getName());

                continue;
            }

            $rest[] = $signal;
        }

        foreach ($rest as $signal) {
            Hilos::$sr?->queueSignal($signal->signalSource, $signal->signalType, $signal->signalName, $signal->data);
        }
    }

    /**
     * Empties the signal-router queue, so a later read observes only what came after.
     *
     * The setup of a case queues frames of its own - a handshake, a sign-in - and they carry
     * the same shape the case is about to assert on. Without this, the assertion could be
     * answered by the arrangement instead of by the act.
     */
    protected function drainSignals(): void
    {
        while (Hilos::$sr?->getNextQueuedSignal() !== null) {
            // discard
        }
    }

    /**
     * Drains the queue and returns the last handshake response addressed to one connection.
     *
     * The LAST one rather than the first: a single act can re-send the greeting more than once,
     * and what a tab ends up showing is the one that arrived last.
     *
     * @param string $acceptKey Target connection accept key
     * @return ?HandshakeResponseSignalData Last handshake response for the connection, or null when none was sent
     */
    protected function lastHandshakeResponseFor(string $acceptKey): ?HandshakeResponseSignalData
    {
        $found = null;
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $data = $signal->data;
            if ($data instanceof WebSocketSignalData
                && $data->targetAcceptKey === $acceptKey
                && $data->data instanceof HandshakeResponseSignalData) {
                $found = $data->data;
            }
        }

        return $found;
    }

    /**
     * Starts one agent the way a node does: its declared claims first, then the hook.
     *
     * @see OwnershipDeclaration::claimAll()
     *
     * @param AbstractAgent $agent Agent to claim for and start
     * @throws HilosException When the agent's own startup fails
     */
    protected function startAgent(AbstractAgent $agent): void
    {
        OwnershipDeclaration::claimAll($agent);

        $agent->onStart();
    }

    /**
     * Runs one dispatch under the execution context of the agent it is addressed to.
     *
     * A node takes the context from the id the message carries
     * ({@see WorkerManager::handleDaemonMessage()}), and the truth-source guard reads that id
     * to decide who may write. A case calling a handler straight leaves whatever context the
     * previous step set, so the library's own writes were being judged as the holder's - which
     * a running node never does, and which the guard of HIL-716 made visible. The previous id
     * is put back because a case is usually inside one when it calls here.
     *
     * Used on the LIBRARY dispatches only. The holder's own handlers keep running under the
     * harness id, because that is who holds this project's runtime claims in a case; moving
     * them onto the holder's id is a change to the runtime half and belongs with it.
     *
     * @template TReturn
     * @param AgentInterface $agent Agent the dispatch is addressed to
     * @param callable(): TReturn $dispatch The handler call
     * @return TReturn Whatever the handler returned
     * @throws HilosException When the handler fails
     */
    private function underAgent(AgentInterface $agent, callable $dispatch): mixed
    {
        $previous = ExecutionContext::currentAgentId();
        ExecutionContext::setCurrentAgentId($agent->getId());
        try {
            return $dispatch();
        } finally {
            ExecutionContext::setCurrentAgentId($previous);
        }
    }

    /**
     * Unregisters test truth-source ownership after each test.
     */
    protected function tearDown(): void
    {
        $leftOpen = Database::rollBackLeftOpen();
        TruthSourceRegistry::unregisterAgent(self::TEST_AGENT_ID);
        RtTruthSourceRegistry::unregisterAgent(self::TEST_AGENT_ID);
        parent::tearDown();

        if ($leftOpen !== null) {
            self::fail($leftOpen->getMessage());
        }
    }
}
