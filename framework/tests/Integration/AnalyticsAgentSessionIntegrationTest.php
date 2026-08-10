<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Core\Agent\AgentId;
use Hilos\Core\Analytics\AnalyticsCollector;
use Hilos\Database\Database;
use Hilos\Database\Exception\DatabaseException;

/**
 * Integration coverage for the agent analytics session key (HIL-549).
 *
 * A singleton agent and an agent whose index is the empty string used to key onto
 * one cache entry, and the damage was silent: the second `openAgentSession()`
 * overwrote the first, every later `logAgent*` of both agents landed under one
 * `agent_session_id`, and whichever stopped first stamped the other's row and
 * cleared the key — after which the survivor logged nothing at all and the first
 * agent's row stayed open forever.
 *
 * Both halves of the cure are played here, because either alone leaves the state
 * reachable: `AgentId::fromId()` reads a trailing separator back as no index, and
 * the key no longer collapses "no index" onto "empty index" even when one is
 * handed in directly.
 *
 * The analytics schema ships as a stub for projects to copy, so this test builds
 * it from that very file rather than from a second copy of the DDL.
 */
final class AnalyticsAgentSessionIntegrationTest extends FrameworkIntegrationTestCase
{
    private const string AGENT_TYPE = 'unit_analytics_agent';

    private const string AGENT_INDEX = '3';

    private const string SIGNAL_NAME = 'unit_analytics_signal';

    private const int WORKER_INDEX = 1;

    /**
     * @throws DatabaseException When the stub schema cannot be built
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->dropAnalyticsSchema();
        foreach ($this->analyticsSchemaStatements() as $statement) {
            Database::sql($statement);
        }
    }

    /**
     * @throws DatabaseException When the stub schema cannot be dropped
     */
    protected function tearDown(): void
    {
        $this->dropAnalyticsSchema();

        parent::tearDown();
    }

    /**
     * @throws DatabaseException When reading back the recorded sessions fails
     */
    public function testTrailingSeparatorNamesNoIndexAndKeepsTheSingletonSessionApart(): void
    {
        $this->assertNull(AgentId::fromId(self::AGENT_TYPE . ':')->index);

        $collector = new AnalyticsCollector();
        $collector->openWorkerSession(self::WORKER_INDEX, false);

        $singleton = AgentId::fromId(self::AGENT_TYPE . ':');
        $indexed = AgentId::fromId(self::AGENT_TYPE . ':' . self::AGENT_INDEX);

        $singletonSessionId = $collector->openAgentSession($singleton->type, $singleton->index);
        $indexedSessionId = $collector->openAgentSession($indexed->type, $indexed->index);

        $this->assertNotNull($singletonSessionId);
        $this->assertNotNull($indexedSessionId);
        $this->assertNotSame($singletonSessionId, $indexedSessionId);
        $this->assertCount(2, $this->openAgentSessionIds());
    }

    /**
     * @throws DatabaseException When reading back the recorded rows fails
     */
    public function testStoppingOneAgentLeavesTheOtherLogging(): void
    {
        $collector = new AnalyticsCollector();
        $collector->openWorkerSession(self::WORKER_INDEX, false);

        // The empty index handed in directly, which is what the parse used to produce.
        $singletonSessionId = $collector->openAgentSession(self::AGENT_TYPE, null);
        $indexedSessionId = $collector->openAgentSession(self::AGENT_TYPE, '');
        $this->assertNotNull($indexedSessionId);

        $collector->closeAgentSession(self::AGENT_TYPE, null);

        $collector->logAgentUserAction(self::AGENT_TYPE, '', null, self::SIGNAL_NAME, null);
        $collector->flush();

        $this->assertSame([$indexedSessionId], $this->loggedAgentSessionIds());
        $this->assertSame([$indexedSessionId], $this->openAgentSessionIds());
        $this->assertNotSame($singletonSessionId, $indexedSessionId);
    }

    /**
     * @return list<int> Ids of the agent sessions still open, ordered by id
     * @throws DatabaseException When the query fails
     */
    private function openAgentSessionIds(): array
    {
        return $this->columnOfInts(
            'SELECT `id` FROM `hilos_analytics_agent_session` WHERE `stopped_ts` IS NULL ORDER BY `id`',
            'id',
        );
    }

    /**
     * @return list<int> Agent session ids carried by the logged actions, ordered by row id
     * @throws DatabaseException When the query fails
     */
    private function loggedAgentSessionIds(): array
    {
        return $this->columnOfInts(
            'SELECT `agent_session_id` FROM `hilos_analytics_agent_user_action` ORDER BY `id`',
            'agent_session_id',
        );
    }

    /**
     * @param string $sql Query returning one column
     * @param string $column Column name to read
     * @return list<int> Column values as integers
     * @throws DatabaseException When the query fails
     */
    private function columnOfInts(string $sql, string $column): array
    {
        Database::sql($sql);

        $values = [];
        while (($row = Database::row()) !== null) {
            $values[] = (int)$row[$column];
        }

        return $values;
    }

    /**
     * Reads the project-facing stub migration and splits it into statements.
     *
     * @return list<string> CREATE TABLE statements, in file order
     */
    private function analyticsSchemaStatements(): array
    {
        $sql = (string)file_get_contents(
            dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_hilos_analytics.sql',
        );

        $statements = [];
        foreach (explode(';', $sql) as $statement) {
            $trimmed = trim($statement);
            if (str_contains($trimmed, 'CREATE TABLE')) {
                $statements[] = $trimmed;
            }
        }

        return $statements;
    }

    /**
     * Drops the stub tables child-first, so the foreign keys never block the drop.
     *
     * @throws DatabaseException When a drop fails
     */
    private function dropAnalyticsSchema(): void
    {
        $tables = [];
        foreach ($this->analyticsSchemaStatements() as $statement) {
            if (preg_match('/CREATE TABLE `(\w+)`/', $statement, $found) === 1) {
                $tables[] = $found[1];
            }
        }

        foreach (array_reverse($tables) as $table) {
            Database::sql("DROP TABLE IF EXISTS `{$table}`");
        }
    }
}
