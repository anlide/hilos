<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Core\Analytics\AnalyticsCollector;
use Hilos\Database\Database;
use Hilos\Database\Exception\DatabaseException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Integration coverage for the analytics collector across a protected-mode restore (HIL-910).
 *
 * The collector is in no agent roster, so it answers the freeze itself: it discards its
 * gathered journal batch while the database may be replaced. The master still remembers
 * its live connections during the freeze, then forgets them at re-hydrate because browsers
 * reconnect. A worker keeps its live session keys, whose descriptions travel with every
 * later batch. Each case replaces the schema under a running collector and loads its new
 * records through a fresh writer, so no stale row number can label a fact after restore.
 */
final class AnalyticsDatabaseSwapIntegrationTest extends AnalyticsSchemaIntegrationTestCase
{
    private const string ACCEPT_KEY = 'accept-key-hil-910';

    private const string ACCEPT_KEY_AFTER_SWAP = 'accept-key-hil-910-after-swap';

    private const string ACCEPT_KEY_OF_ANOTHER_PROCESS = 'accept-key-hil-910-another-process';

    private const string ACTION_SEND = 'send';

    private const string ACTION_EDIT = 'edit';

    private const int WORKER_INDEX = 3;

    private const string AGENT_TYPE = 'chat';

    private const string AGENT_INDEX = '7';

    private const string SIGNAL_NAME = 'agent_start';

    /** Pause between an agent's stop under the freeze and the resume, so the two moments read apart */
    private const int HELD_SPAN_MICROSECONDS = 50_000;

    /**
     * @throws DatabaseException When the stub schema cannot be dropped
     */
    protected function tearDown(): void
    {
        Hilos::$rt = null;

        parent::tearDown();
    }

    /**
     * New master actions after a restore are named by fresh keys and loaded by the writer.
     *
     * @throws DatabaseException When the schema cannot be rebuilt or the rows read back
     */
    public function testTheMasterRecordsFreshActionsAfterTheSwap(): void
    {
        $collector = new AnalyticsCollector();
        $collector->openWsConnection(self::ACCEPT_KEY, null);
        $this->assertNotNull($collector->logUserAction(self::ACCEPT_KEY, self::ACTION_SEND, null));
        $this->loadJournal($collector);

        $this->rebuildAnalyticsSchema();
        $collector->forgetReplacedDatabase();

        $collector->openWsConnection(self::ACCEPT_KEY_AFTER_SWAP, null);
        $first = $collector->logUserAction(self::ACCEPT_KEY_AFTER_SWAP, self::ACTION_SEND, null);
        $second = $collector->logUserAction(self::ACCEPT_KEY_AFTER_SWAP, self::ACTION_SEND, null);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->loadJournal($collector);
        $this->assertSame(self::ACTION_SEND, $this->actionNameOf($first));
        $this->assertSame(self::ACTION_SEND, $this->actionNameOf($second));
    }

    /**
     * Another process may give the restored dictionary rows different numbers; each action's
     * own journal key still leads to its right name.
     *
     * @throws DatabaseException When the schema cannot be rebuilt or the rows read back
     */
    public function testRowsFromAnotherProcessDoNotRelabelAnActionAfterTheSwap(): void
    {
        $collector = new AnalyticsCollector();
        $collector->openWsConnection(self::ACCEPT_KEY, null);
        $collector->logUserAction(self::ACCEPT_KEY, self::ACTION_SEND, null);
        $collector->logUserAction(self::ACCEPT_KEY, self::ACTION_EDIT, null);
        $this->loadJournal($collector);

        $this->rebuildAnalyticsSchema();

        // The other process records the names in the opposite order after the schema swap.
        $anotherProcess = new AnalyticsCollector();
        $anotherProcess->openWsConnection(self::ACCEPT_KEY_OF_ANOTHER_PROCESS, null);
        $anotherProcess->logUserAction(self::ACCEPT_KEY_OF_ANOTHER_PROCESS, self::ACTION_EDIT, null);
        $anotherProcess->logUserAction(self::ACCEPT_KEY_OF_ANOTHER_PROCESS, self::ACTION_SEND, null);
        $this->loadJournal($anotherProcess);

        $collector->forgetReplacedDatabase();

        $collector->openWsConnection(self::ACCEPT_KEY_AFTER_SWAP, null);
        $action = $collector->logUserAction(self::ACCEPT_KEY_AFTER_SWAP, self::ACTION_SEND, null);

        $this->assertNotNull($action);
        $this->loadJournal($collector);
        $this->assertSame(self::ACTION_SEND, $this->actionNameOf($action));
    }

    /**
     * What the process owns lived through the swap and comes back by its description; what a
     * browser owns does not.
     *
     * The writer is stopped by the freeze and starts again with nothing in memory, so the second
     * file is loaded by a fresh loader - and the first event of the agent after the swap carries
     * the descriptions of its worker and of itself (HIL-1154).
     *
     * @throws HilosException When the schema cannot be rebuilt, the journal loaded or the rows read back
     */
    public function testTheWorkerAndItsAgentComeBackByTheirDescriptionsButTheConnectionDoesNot(): void
    {
        $collector = new AnalyticsCollector();
        $collector->openWorkerSession(self::WORKER_INDEX, false);
        $collector->openAgentSession(self::AGENT_TYPE, self::AGENT_INDEX);
        $collector->openWsConnection(self::ACCEPT_KEY, null);
        $this->loadJournal($collector);

        $this->rebuildAnalyticsSchema();
        $collector->forgetReplacedDatabase();
        $collector->logAgentSystemSignal(self::AGENT_TYPE, self::AGENT_INDEX, self::SIGNAL_NAME, null);
        $this->loadJournal($collector);

        $workers = $this->rowsOf('SELECT `id`, `worker_index` FROM `hilos_analytics_worker_session`');
        $this->assertCount(1, $workers);
        $this->assertSame(self::WORKER_INDEX, (int)$workers[0]['worker_index']);

        $agents = $this->rowsOf('SELECT `id`, `worker_session_id`, `agent_type`, `agent_index` FROM `hilos_analytics_agent_session`');
        $this->assertCount(1, $agents);
        $this->assertSame((int)$workers[0]['id'], (int)$agents[0]['worker_session_id']);
        $this->assertSame(self::AGENT_TYPE, $agents[0]['agent_type']);
        $this->assertSame(self::AGENT_INDEX, $agents[0]['agent_index']);
        $signals = $this->rowsOf('SELECT `agent_session_id` FROM `hilos_analytics_agent_system_signal`');
        $this->assertSame([(int)$agents[0]['id']], array_map(static fn(array $row): int => (int)$row['agent_session_id'], $signals));

        $this->assertNull($collector->logUserAction(self::ACCEPT_KEY, self::ACTION_SEND, null));
        $this->assertSame([], $this->rowsOf('SELECT `id` FROM `hilos_analytics_user_action`'));
    }

    /**
     * While the operation may replace the database the collector records nothing and throws away
     * the batch it had gathered: what was recorded before the freeze belongs to a database that
     * may not be there any more. Once the phase moves on, recording resumes.
     *
     * Activating holds as well as active (HIL-1060): a follower reaches active only on its
     * leader's word that every node has stopped (HIL-1128), and the database may change under it
     * while its row reads activating.
     *
     * @param string $phase Freeze phase that must hold the collector
     * @throws HilosException When the journal cannot be loaded or the rows read back
     */
    #[DataProvider('silencingPhases')]
    public function testNothingIsRecordedWhileTheFreezeSilencesTheCollector(string $phase): void
    {
        $collector = new AnalyticsCollector();
        $collector->openWorkerSession(self::WORKER_INDEX, false);
        $collector->logWorkerSystemSignal(self::SIGNAL_NAME, null);

        $this->freeze($phase);
        $collector->openWsConnection(self::ACCEPT_KEY, null);
        $collector->logWorkerSystemSignal(self::SIGNAL_NAME, null);
        $collector->flush();

        $this->assertSame([], $this->queuedJournalLines());
        $this->assertSame([], $this->rowsOf('SELECT `id` FROM `hilos_analytics_ws_connection`'));

        $this->freeze(ProtectedModeRuntime::PHASE_VERIFYING);
        $collector->logWorkerSystemSignal(self::SIGNAL_NAME, null);
        $this->loadJournal($collector);

        // The signal recorded after the freeze, and neither the one thrown away nor the one dropped under it.
        $this->assertCount(1, $this->rowsOf('SELECT `id` FROM `hilos_analytics_worker_system_signal`'));
        $this->assertCount(1, $this->rowsOf('SELECT `id` FROM `hilos_analytics_worker_session`'));
    }

    /**
     * @return array<string, array{string}> Every phase in which the freeze silences the collector
     */
    public static function silencingPhases(): array
    {
        return [
            'activating' => [ProtectedModeRuntime::PHASE_ACTIVATING],
            'active' => [ProtectedModeRuntime::PHASE_ACTIVE],
        ];
    }

    /**
     * A freeze that ends with no swap leaves the old rows standing, and the stop that happened
     * under it is a true fact of that database - handed over once the freeze lets go, stamped
     * with its own moment.
     *
     * @throws HilosException When the journal cannot be loaded or the rows read back
     */
    public function testAnAgentStoppedUnderTheFreezeIsStampedWithTheMomentItStopped(): void
    {
        $collector = new AnalyticsCollector();
        $collector->openWorkerSession(self::WORKER_INDEX, false);
        $collector->openAgentSession(self::AGENT_TYPE, self::AGENT_INDEX);
        $this->loadJournal($collector);

        $this->freeze(ProtectedModeRuntime::PHASE_ACTIVE);
        $before = $this->nowMs();
        $collector->closeAgentSession(self::AGENT_TYPE, self::AGENT_INDEX);
        $after = $this->nowMs();
        $collector->flush();
        $this->assertSame([], $this->queuedJournalLines());

        usleep(self::HELD_SPAN_MICROSECONDS);
        $this->freeze(ProtectedModeRuntime::PHASE_VERIFYING);
        $collector->tick();
        $this->loadJournal($collector);

        $agents = $this->rowsOf('SELECT `stopped_ts` FROM `hilos_analytics_agent_session`');
        $this->assertCount(1, $agents);
        $this->assertGreaterThanOrEqual($before, (int)$agents[0]['stopped_ts']);
        $this->assertLessThanOrEqual($after, (int)$agents[0]['stopped_ts']);
    }

    /**
     * A database swap drops the master's remembered connections and its old batch.
     *
     * @throws HilosException When the schema cannot be rebuilt or the journal loaded
     */
    public function testTheMasterForgetsConnectionsFromBeforeTheSwap(): void
    {
        $collector = new AnalyticsCollector();
        $collector->openWsConnection(self::ACCEPT_KEY, '203.0.113.7');
        $this->loadJournal($collector);

        $this->rebuildAnalyticsSchema();
        $collector->forgetReplacedDatabase();
        $this->assertNull($collector->logUserAction(self::ACCEPT_KEY, self::ACTION_SEND, null));

        $collector->openWsConnection(self::ACCEPT_KEY_AFTER_SWAP, null);
        $action = $collector->logUserAction(self::ACCEPT_KEY_AFTER_SWAP, self::ACTION_SEND, null);
        $this->assertNotNull($action);
        $this->loadJournal($collector);
        $this->assertSame(self::ACTION_SEND, $this->actionNameOf($action));
    }

    /**
     * Mounts this node's freeze row in the phase the case needs.
     *
     * Built through the deserialization path an inbound RT sync uses, as the protected-mode
     * gate tests do: only the phase matters to the collector.
     *
     * @param string $phase Freeze phase to mount
     */
    private function freeze(string $phase): void
    {
        Hilos::$rt = new AnalyticsDatabaseSwapTestRtContext();
        Hilos::$rt->mountFeatureItem(ProtectedModeRuntime::RT_ITEM, ProtectedModeRuntime::fromRow([
            ProtectedModeRuntime::phase => $phase,
            ProtectedModeRuntime::passHashes => [],
            ProtectedModeRuntime::admittedSessionTokenHashes => [],
            ProtectedModeRuntime::circleSessionTokenHashes => [],
            ProtectedModeRuntime::circleNamedCount => 0,
        ]));
    }

    /**
     * @param string $userActionKey User action key
     * @return string Name the action is filed under
     * @throws DatabaseException When the query fails
     */
    private function actionNameOf(string $userActionKey): string
    {
        $rows = $this->rowsOf(
            'SELECT `n`.`name` FROM `hilos_analytics_user_action` `a`
             JOIN `hilos_analytics_action_name` `n` ON `n`.`id` = `a`.`action_name_id`
             WHERE `a`.`action_key` = UNHEX(?)',
            [$userActionKey],
        );
        $this->assertCount(1, $rows);

        return (string)$rows[0]['name'];
    }

    /**
     * @param string $sql Query to run
     * @param list<int|string> $params Query parameters
     * @return list<array<string, mixed>> Rows of the result
     * @throws DatabaseException When the query fails
     */
    private function rowsOf(string $sql, array $params = []): array
    {
        Database::sql($sql, $params);

        return Database::rows();
    }

    /**
     * @return int Current wall-clock time in milliseconds, on the collector's own scale
     */
    private function nowMs(): int
    {
        return (int)floor(microtime(true) * 1000);
    }
}

/**
 * Runtime context that registers no project state: the framework mount supplies the freeze row.
 */
final class AnalyticsDatabaseSwapTestRtContext extends RtContext
{
    public function configure(): void
    {
    }
}
