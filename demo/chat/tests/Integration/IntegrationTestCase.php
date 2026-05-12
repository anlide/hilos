<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Database\Database;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Core\TruthSource\TruthSourceRegistry;
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
