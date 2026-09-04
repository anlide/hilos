<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Agents\Hilos\NotificationsLibraryAgent;
use Demo\Chat\Agents\Hilos\SessionsLibraryAgent;
use Demo\Chat\Agents\Hilos\UsersLibraryAgent;
use Demo\Chat\Database\Database;
use Demo\Chat\Hilos;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\Auth\Session\DTO\SessionRebindSignalData;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\View\Item\Session;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\HilosSessionRotation as StateHilosSessionRotation;
use Hilos\Socket\WebSocket\DTO\HandshakeResponseSignalData;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;
use Random\RandomException;

/**
 * Base class for integration tests.
 *
 * Requires MySQL test container running.
 */
abstract class IntegrationTestCase extends TestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    /** @var bool Whether the database has been initialized for this test process */
    protected static bool $dbInitialized = false;

    /** @var ?UsersLibraryAgent Library the sign-in commands are dispatched on, built on first use */
    private ?UsersLibraryAgent $usersLibrary = null;

    /** @var ?SessionsLibraryAgent Library the sessions themselves live in, built on first use */
    private ?SessionsLibraryAgent $sessionsLibrary = null;

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
        TruthSourceRegistry::register(ChatDbContext::users, true, self::TEST_AGENT_ID);
        TruthSourceRegistry::register(ChatDbContext::events, true, self::TEST_AGENT_ID);
        TruthSourceRegistry::register(ChatDbContext::eventMessages, true, self::TEST_AGENT_ID);
        TruthSourceRegistry::register(ChatDbContext::eventUserRegistrations, true, self::TEST_AGENT_ID);
        TruthSourceRegistry::register(ChatDbContext::eventUserRenames, true, self::TEST_AGENT_ID);
        TruthSourceRegistry::register(ChatDbContext::eventAttachments, true, self::TEST_AGENT_ID);
        TruthSourceRegistry::register(ChatDbContext::bots, true, self::TEST_AGENT_ID);
        TruthSourceRegistry::register(ChatDbContext::moderatorPromptPieces, true, self::TEST_AGENT_ID);
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
        RtTruthSourceRegistry::register(ChatRtContext::userStates, true, self::TEST_AGENT_ID);
        RtTruthSourceRegistry::register(ChatRtContext::attachmentDrafts, true, self::TEST_AGENT_ID);
        // Owned by the framework rather than the project (HIL-582), and written by any case
        // that drives a login, so the harness claims it once for everybody.
        RtTruthSourceRegistry::register(StateHilosSessionRotation::RT_COLLECTION, true, self::TEST_AGENT_ID);
    }

    /**
     * Resolves the session a live connection currently belongs to.
     *
     * The token a case opened its session with is no longer a stable handle: a login
     * rotates the session onto a fresh one (HIL-582), and the pre-login value then names
     * no session at all. The connection row follows the rotation, so asking it is the way
     * to reach "the session this tab is in" whether or not anything rotated.
     *
     * @param string $acceptKey Accept key of the connection to resolve through
     * @return ?Session Session the connection belongs to, or null when it has none
     * @throws HilosException When the runtime or database lookup fails
     */
    protected function sessionOf(string $acceptKey): ?Session
    {
        $sessionToken = Hilos::$rt->connections[$acceptKey]?->sessionToken;

        return $sessionToken === null ? null : Hilos::$db->sessions->findByToken($sessionToken);
    }

    /**
     * Builds the users library the sign-in commands live in, once per case.
     *
     * The commands stopped being page handlers in HIL-622: they belong to an agent of their
     * own, and a case that drives one dispatches it here rather than through the chat's main
     * page. Started on first use because the library resolves
     * the project's seams - which methods an identifier may be offered, which providers are
     * wired - in {@see UsersLibraryAgent::onStart()}, and a command asks for them.
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
     * Builds the sessions library the session set lives in, once per case.
     *
     * The third agent of the sign-in surface since HIL-710, and the one that owns what used
     * to be a trait in the chat agent: a case drives a handshake, a bind or a sign-out here
     * and reads its consequences off the chat agent, which is told in a frame. Started on
     * first use because {@see SessionsLibraryAgent::onStart()} claims the session set and
     * arms the sweeps, and a case driving either needs both.
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
     * Opens one socket's session the way a node does: in the library, then told to the project.
     *
     * The handshake stopped being the chat agent's in HIL-710 - it is addressed to the
     * sessions library, which resolves the cookie and answers with the state frame this
     * hands on. A case calling the chat agent directly would find no connection row at all.
     *
     * @param ChatAgent $holder Agent that holds this project's connections
     * @param WebSocketHandshakeSignalDTO $data Accept key and the daemon-resolved session token
     * @throws HilosException When the handshake or the frame that follows it fails
     * @throws AgentUnknownSignalException When an agent does not know a frame it is handed
     * @throws RandomException When rotating a session token cannot draw from the CSPRNG
     * @throws InvalidArgumentException When a signal put back on the queue has no name
     * @throws InvalidFormatException When a frame's outcome cannot be read back
     */
    protected function deliverHandshake(ChatAgent $holder, WebSocketHandshakeSignalDTO $data): void
    {
        $library = $this->sessionsLibrary();
        $this->underAgent($library, static fn () => $library->onSignalHandshake($data, '', ''));
        $this->deliverLibraryFrames($holder);
    }

    /**
     * Raises one live session to a user, the way a project asks for it.
     *
     * The rebind frame is the only way in since HIL-710: binding a session is the library's
     * write, and what a project may do is name the state the session must reach. Naming a
     * user is a sign-in; {@see self::deauthenticateSession()} names nobody.
     *
     * @param ChatAgent $holder Agent that holds this project's connections
     * @param string $sessionToken Session cookie token to authenticate
     * @param int $userId Durable user id to bind the session to
     * @param ?string $initiatorAcceptKey Accept key of the connection that logged in, or null when there is none
     * @throws HilosException When the bind or the frame that follows it fails
     * @throws AgentUnknownSignalException When an agent does not know a frame it is handed
     * @throws RandomException When rotating a session token cannot draw from the CSPRNG
     * @throws InvalidArgumentException When a signal put back on the queue has no name
     * @throws InvalidFormatException When a frame's outcome cannot be read back
     */
    protected function authenticateSession(
        ChatAgent $holder,
        string $sessionToken,
        int $userId,
        ?string $initiatorAcceptKey,
    ): void {
        $this->rebindSession($holder, new SessionRebindSignalData(
            sessionToken: $sessionToken,
            userId: $userId,
            initiatorAcceptKey: $initiatorAcceptKey,
        ));
    }

    /**
     * Reverts one live session to anonymous - the inverse of {@see self::authenticateSession()}.
     *
     * @param ChatAgent $holder Agent that holds this project's connections
     * @param string $sessionToken Session cookie token to revert to anonymous
     * @throws HilosException When the unbind or the frame that follows it fails
     * @throws AgentUnknownSignalException When an agent does not know a frame it is handed
     * @throws RandomException When rotating a session token cannot draw from the CSPRNG
     * @throws InvalidArgumentException When a signal put back on the queue has no name
     * @throws InvalidFormatException When a frame's outcome cannot be read back
     */
    protected function deauthenticateSession(ChatAgent $holder, string $sessionToken): void
    {
        $this->rebindSession($holder, new SessionRebindSignalData(sessionToken: $sessionToken, userId: null));
    }

    /**
     * Hands one rebind frame to the library and delivers what it answers with.
     *
     * @param ChatAgent $holder Agent that holds this project's connections
     * @param SessionRebindSignalData $frame Session and the state it must reach
     * @throws HilosException When the rebind or the frame that follows it fails
     * @throws AgentUnknownSignalException When an agent does not know a frame it is handed
     * @throws RandomException When rotating a session token cannot draw from the CSPRNG
     * @throws InvalidArgumentException When a signal put back on the queue has no name
     * @throws InvalidFormatException When a frame's outcome cannot be read back
     */
    protected function rebindSession(ChatAgent $holder, SessionRebindSignalData $frame): void
    {
        $library = $this->sessionsLibrary();
        $this->underAgent($library, static fn () => $library->onSignalAgent(
            new AgentSignalData($frame),
            '',
            HilosSignalConstants::HILOS_SESSION_REBIND,
        ));
        $this->deliverLibraryFrames($holder);
    }

    /**
     * Runs every frame the sign-in surface queued through the agent it is addressed to.
     *
     * Three agents share one sign-in in a running node and two hops between them: a command
     * that ends in a signed-in person hands the ending to the sessions library (HIL-622),
     * and the library hands what the session became to the agent holding the sockets
     * (HIL-710). In a node those hops are three workers taking their turn; in a case they
     * are this call, and a case that omits it will find neither the session raised, nor the
     * connection re-pointed, nor the action answered.
     *
     * Everything that is not one of those frames goes back on the queue in the order it was
     * taken off, so a case can still read the converge signals and browser pushes the run
     * produced. Frames an agent queues while handling one are picked up by the same loop,
     * which is what carries a login across both hops in a single call.
     *
     * What comes back is where the surface is sent next - the outcome the state frame
     * carried and the project answered the action with. A command that answered for itself
     * returns it from the dispatch instead and this is null; a case that can be reached
     * either way takes whichever of the two it got.
     *
     * @param ChatAgent $holder Agent that holds this project's connections
     * @return ?AuthFlowOutcome Outcome the last frame handed over, or null when none carried one
     * @throws HilosException When a frame's handler fails
     * @throws AgentUnknownSignalException When an agent does not know a frame it is handed
     * @throws RandomException When rotating a session token cannot draw from the CSPRNG
     * @throws InvalidArgumentException When a signal put back on the queue has no name
     * @throws InvalidFormatException When a frame's outcome cannot be read back
     */
    protected function deliverLibraryFrames(ChatAgent $holder): ?AuthFlowOutcome
    {
        $rest = [];
        $outcome = null;
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) instanceof SignalDTO) {
            $name = $signal->signalName->getName();
            $data = $signal->data;
            if (!$data instanceof AgentSignalData) {
                $rest[] = $signal;

                continue;
            }

            $isStateFrame = $data->data instanceof SessionStateSignalData;
            if (!$isStateFrame && !array_key_exists($name, SessionsLibraryAgent::AGENT_SIGNALS)) {
                $rest[] = $signal;

                continue;
            }

            // Read off the wire form rather than the frame class: a frame that ends a
            // tracked action carries the answer under the same key whichever of the two
            // hops it belongs to, and this is the shape the other process sees. The ones
            // that end nothing - a moved wait, say - carry no such key and hand nothing
            // over. Taking the last non-null is what makes a dispatch driven straight at a
            // library, without a request id behind it, still say where the surface goes.
            $handedOver = $data->data->toArray()['outcome'] ?? null;
            $outcome = is_array($handedOver) ? AuthFlowOutcome::fromArray($handedOver) : $outcome;

            if ($isStateFrame) {
                $holder->onSignalAgent($data, '', $name);
            } else {
                $library = $this->sessionsLibrary();
                $this->underAgent($library, static fn () => $library->onSignalAgent($data, '', $name));
            }
        }

        foreach ($rest as $signal) {
            Hilos::$sr?->queueSignal($signal->signalSource, $signal->signalType, $signal->signalName, $signal->data);
        }

        return $outcome;
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
     * Builds the notifications library the notification tables live in, once per case.
     *
     * An emit stopped being a write in the calling worker in HIL-771: it is a frame addressed
     * to this agent, which persists the row and fans it. So a case that produces a notification
     * - a mention, a moderation refusal, a rename - reads nothing back until the frame has been
     * run through here.
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
     * Runs every notification the case raised through the library that writes it.
     *
     * The sibling of {@see self::deliverLibraryFrames()} for the other hop: in a node the emit
     * frame is a worker taking its turn, in a case it is this call. Everything that is not an
     * emit goes back on the queue in the order it was taken off, so a case can still read the
     * browser pushes the run produced.
     *
     * The loop re-reads the queue rather than walking a snapshot of it, because writing one
     * notification queues the live frame to its recipient - and that frame belongs to the
     * pushes this hands back.
     *
     * @return int Notifications the library persisted
     * @throws HilosException When the emit fails
     * @throws AgentUnknownSignalException When the library does not know a frame it is handed
     * @throws InvalidArgumentException When a signal put back on the queue has no name
     */
    protected function deliverNotificationFrames(): int
    {
        $rest = [];
        $written = 0;
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) instanceof SignalDTO) {
            $name = $signal->signalName->getName();
            if (
                !$signal->data instanceof AgentSignalData
                || $name !== HilosSignalConstants::HILOS_NOTIFICATION_EMIT
            ) {
                $rest[] = $signal;

                continue;
            }

            $this->notificationsLibrary()->onSignalAgent($signal->data, '', $name);
            $written++;
        }

        foreach ($rest as $signal) {
            Hilos::$sr?->queueSignal($signal->signalSource, $signal->signalType, $signal->signalName, $signal->data);
        }

        return $written;
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
        RtTruthSourceRegistry::unregisterAgent(self::TEST_AGENT_ID);
        parent::tearDown();
    }
}
