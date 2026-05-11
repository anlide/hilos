<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Database\Database;
use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Runtime\View\Context\RtChatContext;
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
        TruthSourceRegistry::register(DbChatContext::users, true, self::TEST_AGENT_ID);
        TruthSourceRegistry::register(DbChatContext::events, true, self::TEST_AGENT_ID);
        TruthSourceRegistry::register(DbChatContext::eventMessages, true, self::TEST_AGENT_ID);
        TruthSourceRegistry::register(DbChatContext::eventUserRegistrations, true, self::TEST_AGENT_ID);
        TruthSourceRegistry::register(DbChatContext::eventUserRenames, true, self::TEST_AGENT_ID);
        TruthSourceRegistry::register(DbChatContext::eventAttachments, true, self::TEST_AGENT_ID);
        TruthSourceRegistry::register(DbChatContext::bots, true, self::TEST_AGENT_ID);
        TruthSourceRegistry::register(DbChatContext::moderatorPromptPieces, true, self::TEST_AGENT_ID);
        RtTruthSourceRegistry::register(RtChatContext::userStates, true, self::TEST_AGENT_ID);
        RtTruthSourceRegistry::register(RtChatContext::attachmentDrafts, true, self::TEST_AGENT_ID);
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
