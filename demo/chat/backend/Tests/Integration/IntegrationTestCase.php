<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Database\Database;
use Demo\Chat\Database\DbChatContext;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Base class for integration tests. Requires MySQL test container running.
 */
abstract class IntegrationTestCase extends TestCase
{
    private const string TEST_AGENT_ID = 'test-agent';
    protected static bool $dbInitialized = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (!self::$dbInitialized) {
            Database::initialize(initHilos: true);
            self::$dbInitialized = true;
        }
        TruthSourceRegistry::register(DbChatContext::users, true, self::TEST_AGENT_ID);
        TruthSourceRegistry::register(DbChatContext::events, true, self::TEST_AGENT_ID);
        TruthSourceRegistry::register(DbChatContext::bots, true, self::TEST_AGENT_ID);
        TruthSourceRegistry::register(DbChatContext::moderatorPromptPieces, true, self::TEST_AGENT_ID);
    }

    protected function tearDown(): void
    {
        TruthSourceRegistry::unregisterAgent(self::TEST_AGENT_ID);
        parent::tearDown();
    }
}
