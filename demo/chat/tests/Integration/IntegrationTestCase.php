<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Database\Database;
use Demo\Chat\Hilos;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\View\Item\Session;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\HilosSessionRotation as StateHilosSessionRotation;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;

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
     * Unregisters test truth-source ownership after each test.
     */
    protected function tearDown(): void
    {
        TruthSourceRegistry::unregisterAgent(self::TEST_AGENT_ID);
        RtTruthSourceRegistry::unregisterAgent(self::TEST_AGENT_ID);
        parent::tearDown();
    }
}
