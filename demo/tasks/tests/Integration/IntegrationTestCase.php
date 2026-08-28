<?php

declare(strict_types=1);

namespace Demo\Tasks\Tests\Integration;

use Demo\Tasks\Agents\Hilos\SessionsLibraryAgent;
use Demo\Tasks\Agents\TasksAgent;
use Demo\Tasks\Database\Database;
use Demo\Tasks\Database\TasksDbContext;
use Demo\Tasks\Hilos;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\TruthSource\TruthSourceRegistry;
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
        TruthSourceRegistry::register(TasksDbContext::users, true, self::TEST_AGENT_ID);
        TruthSourceRegistry::register(TasksDbContext::userRenames, true, self::TEST_AGENT_ID);
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
     * @param TasksAgent $holder Agent that holds this project's connections
     * @param WebSocketHandshakeSignalDTO $data Accept key and the daemon-resolved session token
     * @throws HilosException When the handshake or the frame that follows it fails
     * @throws AgentUnknownSignalException When an agent does not know a frame it is handed
     * @throws InvalidArgumentException When a signal put back on the queue has no name
     * @throws InvalidFormatException When a frame's outcome cannot be read back
     */
    protected function deliverHandshake(TasksAgent $holder, WebSocketHandshakeSignalDTO $data): void
    {
        $this->sessionsLibrary()->onSignalHandshake($data, '', '');
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
     * @param TasksAgent $holder Agent that holds this project's connections
     * @throws HilosException When a frame's handler fails
     * @throws AgentUnknownSignalException When an agent does not know a frame it is handed
     * @throws InvalidArgumentException When a signal put back on the queue has no name
     * @throws InvalidFormatException When a frame's outcome cannot be read back
     */
    protected function deliverLibraryFrames(TasksAgent $holder): void
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
     * Unregisters test truth-source ownership after each test.
     */
    protected function tearDown(): void
    {
        TruthSourceRegistry::unregisterAgent(self::TEST_AGENT_ID);
        parent::tearDown();
    }
}
