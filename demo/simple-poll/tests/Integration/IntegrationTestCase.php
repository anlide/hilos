<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Tests\Integration;

use Demo\SimplePoll\Agents\Hilos\NotificationsLibraryAgent;
use Demo\SimplePoll\Agents\Hilos\SessionsLibraryAgent;
use Demo\SimplePoll\Agents\Hilos\UsersLibraryAgent;
use Demo\SimplePoll\Agents\PollAgent;
use Demo\SimplePoll\Database\Database;
use Demo\SimplePoll\Database\PollDbContext;
use Demo\SimplePoll\Hilos;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\HilosException;
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

    /** @var ?UsersLibraryAgent Library the accounts live in, built on first use */
    private ?UsersLibraryAgent $usersLibrary = null;

    /** @var ?NotificationsLibraryAgent Library the notification tables live in, built on first use */
    private ?NotificationsLibraryAgent $notificationsLibrary = null;

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
        TruthSourceRegistry::register(PollDbContext::users, true, self::TEST_AGENT_ID);
        TruthSourceRegistry::register(PollDbContext::userRenames, true, self::TEST_AGENT_ID);
        TruthSourceRegistry::register(PollDbContext::guests, true, self::TEST_AGENT_ID);
        // Framework tables these cases write through their real writers - a login, a code, a
        // notification - and not through the library that owns them. The guard asks on every
        // table since HIL-716, while a test process runs the writers under this harness id
        // rather than under a library's, so the harness claims them once for everybody.
        TruthSourceRegistry::register(HilosDbContext::sessions, true, self::TEST_AGENT_ID);
        TruthSourceRegistry::register(HilosDbContext::identities, true, self::TEST_AGENT_ID);
        TruthSourceRegistry::register(HilosDbContext::verifications, true, self::TEST_AGENT_ID);
        TruthSourceRegistry::register(HilosDbContext::registrationReservations, true, self::TEST_AGENT_ID);
        TruthSourceRegistry::register(HilosDbContext::passkeyCredentials, true, self::TEST_AGENT_ID);
        TruthSourceRegistry::register(HilosDbContext::authBlocks, true, self::TEST_AGENT_ID);
        TruthSourceRegistry::register(HilosDbContext::notifications, true, self::TEST_AGENT_ID);
        TruthSourceRegistry::register(HilosDbContext::notificationDeliveries, true, self::TEST_AGENT_ID);
        TruthSourceRegistry::register(HilosDbContext::notificationPreferences, true, self::TEST_AGENT_ID);
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
            $this->sessionsLibrary->onStart();
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
     * @param PollAgent $holder Agent that holds this project's connections
     * @param WebSocketHandshakeSignalDTO $data Accept key and the daemon-resolved session token
     * @throws HilosException When the handshake or the frame that follows it fails
     * @throws AgentUnknownSignalException When an agent does not know a frame it is handed
     * @throws InvalidArgumentException When a signal put back on the queue has no name
     * @throws InvalidFormatException When a frame's outcome cannot be read back
     */
    protected function deliverHandshake(PollAgent $holder, WebSocketHandshakeSignalDTO $data): void
    {
        $library = $this->sessionsLibrary();
        $this->underAgent($library, static fn () => $library->onSignalHandshake($data, '', ''));
        $this->deliverLibraryFrames($holder);
    }

    /**
     * Runs every frame the library queued through the agent it is addressed to.
     *
     * In a node the hop is two workers taking their turn; in a case it is this call, and a
     * case that omits it will find neither the connection registered nor the visitor named.
     * Everything that is not a session frame goes back on the queue in the order it was
     * taken off, so a case can still read the command replies and browser pushes the run
     * produced.
     *
     * @param PollAgent $holder Agent that holds this project's connections
     * @throws HilosException When a frame's handler fails
     * @throws AgentUnknownSignalException When an agent does not know a frame it is handed
     * @throws InvalidArgumentException When a signal put back on the queue has no name
     * @throws InvalidFormatException When a frame's outcome cannot be read back
     */
    protected function deliverLibraryFrames(PollAgent $holder): void
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
     * Builds the users library the accounts live in, once per case.
     *
     * An admin rename is two steps since HIL-771: the page checks the level and then asks this
     * agent, which owns the row, to make the change. So a case driving that page reads nothing
     * back until the frame has been run through here.
     *
     * @return UsersLibraryAgent Library under test, started
     * @throws HilosException When the library's own startup fails
     */
    protected function usersLibrary(): UsersLibraryAgent
    {
        if ($this->usersLibrary === null) {
            $this->usersLibrary = new UsersLibraryAgent();
            $this->usersLibrary->onStart();
        }

        return $this->usersLibrary;
    }

    /**
     * Builds the notifications library the notification tables live in, once per case.
     *
     * An emit stopped being a write in the calling worker in HIL-771: it is a frame addressed to
     * this agent, which persists the row and fans it.
     *
     * @return NotificationsLibraryAgent Library under test, started
     * @throws HilosException When the library's own startup fails
     */
    protected function notificationsLibrary(): NotificationsLibraryAgent
    {
        if ($this->notificationsLibrary === null) {
            $this->notificationsLibrary = new NotificationsLibraryAgent();
            $this->notificationsLibrary->onStart();
        }

        return $this->notificationsLibrary;
    }

    /**
     * Runs every frame the framework libraries are addressed by through the one it belongs to.
     *
     * The sibling of {@see self::deliverLibraryFrames()} for the hops a page makes into a
     * library that owns a table (HIL-771): an admin rename asks the users library, and writing
     * the row emits a notification, which asks the notifications library in turn. Both hops are
     * a worker taking its turn in a node and this one call in a case.
     *
     * The loop re-reads the queue rather than a snapshot of it, which is what carries that
     * chain: the frame the first library queues is picked up by the same pass. Everything else
     * goes back in the order it was taken off, so a case can still read the answer the page was
     * sent and the browser pushes the run produced.
     *
     * @throws HilosException When a frame's handler fails
     * @throws AgentUnknownSignalException When a library does not know a frame it is handed
     * @throws InvalidArgumentException When a signal put back on the queue has no name
     */
    protected function deliverHilosLibraryFrames(): void
    {
        $rest = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) instanceof SignalDTO) {
            $name = $signal->signalName->getName();
            $data = $signal->data;
            if (!$data instanceof AgentSignalData) {
                $rest[] = $signal;

                continue;
            }

            if ($name === HilosSignalConstants::HILOS_USER_ADMIN_RENAME) {
                $this->usersLibrary()->onSignalAgent($data, '', $name);
            } elseif ($name === HilosSignalConstants::HILOS_NOTIFICATION_EMIT) {
                $this->notificationsLibrary()->onSignalAgent($data, '', $name);
            } else {
                $rest[] = $signal;
            }
        }

        foreach ($rest as $signal) {
            Hilos::$sr?->queueSignal($signal->signalSource, $signal->signalType, $signal->signalName, $signal->data);
        }
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
        TruthSourceRegistry::unregisterAgent(self::TEST_AGENT_ID);
        parent::tearDown();
    }
}
