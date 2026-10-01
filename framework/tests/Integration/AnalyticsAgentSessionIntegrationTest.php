<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Core\Agent\AgentId;
use Hilos\Core\Analytics\AnalyticsCollector;
use Hilos\Database\Database;
use Hilos\Database\Exception\DatabaseException;
use Hilos\HilosException;

/**
 * Integration coverage for the agent analytics session key (HIL-549), through the journal (HIL-1154).
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
 * handed in directly. The events go the way a worker's do - into a batch for the
 * journal, then into the tables by the writer's loader - so the two sessions are
 * two keys and two rows.
 */
final class AnalyticsAgentSessionIntegrationTest extends AnalyticsSchemaIntegrationTestCase
{
    private const string AGENT_TYPE = 'unit_analytics_agent';

    private const string AGENT_INDEX = '3';

    private const string SIGNAL_NAME = 'unit_analytics_signal';

    private const int WORKER_INDEX = 1;

    /**
     * @throws HilosException When the journal cannot be loaded
     */
    public function testTrailingSeparatorNamesNoIndexAndKeepsTheSingletonSessionApart(): void
    {
        $this->assertNull(AgentId::fromId(self::AGENT_TYPE . ':')->index);

        $collector = new AnalyticsCollector();
        $collector->openWorkerSession(self::WORKER_INDEX, false);

        $singleton = AgentId::fromId(self::AGENT_TYPE . ':');
        $indexed = AgentId::fromId(self::AGENT_TYPE . ':' . self::AGENT_INDEX);
        $collector->openAgentSession($singleton->type, $singleton->index);
        $collector->openAgentSession($indexed->type, $indexed->index);

        $this->loadJournal($collector);

        $this->assertSame([[null], [self::AGENT_INDEX]], $this->rows(
            'SELECT `agent_index` FROM `hilos_analytics_agent_session` WHERE `stopped_ts` IS NULL ORDER BY `id`',
        ));
        $this->assertSame([['2']], $this->rows(
            'SELECT COUNT(DISTINCT `session_key`) FROM `hilos_analytics_agent_session`',
        ));
    }

    /**
     * @throws HilosException When the journal cannot be loaded
     */
    public function testStoppingOneAgentLeavesTheOtherLogging(): void
    {
        $collector = new AnalyticsCollector();
        $collector->openWorkerSession(self::WORKER_INDEX, false);

        // The empty index handed in directly, which is what the parse used to produce.
        $collector->openAgentSession(self::AGENT_TYPE, null);
        $collector->openAgentSession(self::AGENT_TYPE, '');
        $collector->closeAgentSession(self::AGENT_TYPE, null);
        $collector->logAgentUserAction(self::AGENT_TYPE, '', null, self::SIGNAL_NAME, null);

        $this->loadJournal($collector);

        $this->assertSame([['']], $this->rows(
            'SELECT s.`agent_index` FROM `hilos_analytics_agent_user_action` f
             JOIN `hilos_analytics_agent_session` s ON s.`id` = f.`agent_session_id`',
        ));
        $this->assertSame([['']], $this->rows(
            'SELECT `agent_index` FROM `hilos_analytics_agent_session` WHERE `stopped_ts` IS NULL',
        ));
        $this->assertSame([[null]], $this->rows(
            'SELECT `agent_index` FROM `hilos_analytics_agent_session` WHERE `stopped_ts` IS NOT NULL',
        ));
    }

    /**
     * @param string $sql Query
     * @return list<list<?string>> Every row as a list of its values
     * @throws DatabaseException When the query fails
     */
    private function rows(string $sql): array
    {
        Database::sql($sql);

        $rows = [];
        foreach (Database::rows() as $row) {
            $rows[] = array_map(static fn(mixed $value): ?string => $value === null ? null : (string)$value, array_values($row));
        }

        return $rows;
    }
}
