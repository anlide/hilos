<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Agents\Hilos\UsersLibraryAgent;
use Demo\Chat\Database\Database;
use Demo\Chat\Hilos;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\Auth\Session\HilosSessionHostInterface;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\View\Item\Session;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\HilosSessionRotation as StateHilosSessionRotation;
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
     * Runs every sign-in frame the library queued through the session holder.
     *
     * A command that ends in a signed-in person does not write the session itself - it hands
     * the ending to the holder ({@see HilosSessionHostInterface}) and, when the action was
     * tracked, lets the holder answer it. In one running node that hop is the two agents'
     * own workers taking their turn; in a case it is this call, and a case that omits it
     * will find neither the session raised nor the action answered.
     *
     * Everything that is not a holder frame goes back on the queue in the order it was
     * taken off, so a case can still read the converge signals and browser pushes the run
     * produced exactly as it did when the page owned these commands. Frames the holder
     * queues while handling one are picked up by the same loop.
     *
     * What comes back is where the surface is sent next - the outcome the frame carried and
     * the holder answered the action with. A command that answered for itself returns it
     * from the dispatch instead and this is null; a case that can be reached either way
     * takes whichever of the two it got.
     *
     * @param ChatAgent $holder Agent that holds this project's sessions
     * @return ?AuthFlowOutcome Outcome the last frame handed over, or null when none carried one
     * @throws HilosException When a frame's handler fails
     * @throws AgentUnknownSignalException When the holder does not know a frame it is handed
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
            if (
                $signal->data instanceof AgentSignalData
                && array_key_exists($name, HilosSessionHostInterface::SESSION_HOST_SIGNALS)
            ) {
                // Read off the wire form rather than the frame class: a frame that ends a
                // tracked action carries the answer under the same key whichever frame it
                // is, and this is the shape the other process sees. The ones that end
                // nothing - a moved wait, say - carry no such key and hand nothing over.
                $handedOver = $signal->data->data->toArray()['outcome'] ?? null;
                $outcome = is_array($handedOver) ? AuthFlowOutcome::fromArray($handedOver) : $outcome;
                $holder->onSignalAgent($signal->data, '', $name);

                continue;
            }

            $rest[] = $signal;
        }

        foreach ($rest as $signal) {
            Hilos::$sr?->queueSignal($signal->signalSource, $signal->signalType, $signal->signalName, $signal->data);
        }

        return $outcome;
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
