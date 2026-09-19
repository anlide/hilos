<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Core\Analytics\AnalyticsCollector;
use Hilos\Database\Database;
use Hilos\Database\Exception\DatabaseException;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Utils\Logger;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Integration coverage for the analytics collector across a protected-mode restore (HIL-910).
 *
 * The collector is in no agent roster, so the freeze cannot stop it: it has to fall silent
 * on its own while the operation may replace the database, and forget every id of the old
 * database once it has been replaced. The restore is played here the way it treats these
 * tables - the schema is dropped and built again under a collector that is still holding
 * ids - and the freeze is the node's own protected-mode row, mounted in the phase a case
 * needs.
 *
 * The failure this leaf was opened for is the lucky one: a cached id the restored database
 * does not have, rejected by a foreign key, which switched collection off until a restart.
 * The unlucky one is quiet - a restored row takes a number the cache holds for another
 * value, and facts land under the wrong name.
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

    /** Temporary main log file the assertions read the written lines back from */
    private string $logFile = '';

    /**
     * @throws DatabaseException When the stub schema cannot be built
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-analytics-swap');
        Logger::setLogFile($this->logFile);
    }

    /**
     * @throws DatabaseException When the stub schema cannot be dropped
     */
    protected function tearDown(): void
    {
        Hilos::$rt = null;
        Logger::resetLogFile();
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }

        parent::tearDown();
    }

    /**
     * The failure of 05.09: the name was cached against the old database, and the restored
     * one has no row under that number.
     *
     * @throws DatabaseException When the schema cannot be rebuilt or the rows read back
     */
    public function testANameCachedBeforeTheSwapIsWrittenAgainAfterIt(): void
    {
        $collector = new AnalyticsCollector();
        $collector->openWsConnection(self::ACCEPT_KEY, null);
        $this->assertNotNull($collector->logUserAction(self::ACCEPT_KEY, self::ACTION_SEND, null));

        $this->rebuildAnalyticsSchema();
        $collector->forgetReplacedDatabase();

        $collector->openWsConnection(self::ACCEPT_KEY_AFTER_SWAP, null);
        $first = $collector->logUserAction(self::ACCEPT_KEY_AFTER_SWAP, self::ACTION_SEND, null);
        $second = $collector->logUserAction(self::ACCEPT_KEY_AFTER_SWAP, self::ACTION_SEND, null);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame(self::ACTION_SEND, $this->actionNameOf($first));
        $this->assertSame(self::ACTION_SEND, $this->actionNameOf($second));
    }

    /**
     * The quiet failure: another process of the node writes into the restored database first,
     * and its names take the numbers this collector cached for its own.
     *
     * @throws DatabaseException When the schema cannot be rebuilt or the rows read back
     */
    public function testANumberTakenByAnotherNameAfterTheSwapDoesNotRelabelTheAction(): void
    {
        $collector = new AnalyticsCollector();
        $collector->openWsConnection(self::ACCEPT_KEY, null);
        $collector->logUserAction(self::ACCEPT_KEY, self::ACTION_SEND, null);
        $collector->logUserAction(self::ACCEPT_KEY, self::ACTION_EDIT, null);

        $this->rebuildAnalyticsSchema();

        // Written in the opposite order, so each name now holds the number the collector cached for the other.
        $anotherProcess = new AnalyticsCollector();
        $anotherProcess->openWsConnection(self::ACCEPT_KEY_OF_ANOTHER_PROCESS, null);
        $anotherProcess->logUserAction(self::ACCEPT_KEY_OF_ANOTHER_PROCESS, self::ACTION_EDIT, null);
        $anotherProcess->logUserAction(self::ACCEPT_KEY_OF_ANOTHER_PROCESS, self::ACTION_SEND, null);

        $collector->forgetReplacedDatabase();

        $collector->openWsConnection(self::ACCEPT_KEY_AFTER_SWAP, null);
        $action = $collector->logUserAction(self::ACCEPT_KEY_AFTER_SWAP, self::ACTION_SEND, null);

        $this->assertNotNull($action);
        $this->assertSame(self::ACTION_SEND, $this->actionNameOf($action));
    }

    /**
     * What the process owns lived through the swap and is opened anew; what a browser owns is not.
     *
     * @throws DatabaseException When the schema cannot be rebuilt or the rows read back
     */
    public function testTheWorkerAndItsAgentAreOpenedAgainButTheConnectionIsNot(): void
    {
        $collector = new AnalyticsCollector();
        $collector->openWorkerSession(self::WORKER_INDEX, false);
        $collector->openAgentSession(self::AGENT_TYPE, self::AGENT_INDEX);
        $collector->openWsConnection(self::ACCEPT_KEY, null);

        $this->rebuildAnalyticsSchema();
        $collector->forgetReplacedDatabase();
        $collector->tick();

        $workers = $this->rowsOf('SELECT `id`, `worker_index` FROM `hilos_analytics_worker_session`');
        $this->assertCount(1, $workers);
        $this->assertSame(self::WORKER_INDEX, (int)$workers[0]['worker_index']);

        $agents = $this->rowsOf('SELECT `worker_session_id`, `agent_type`, `agent_index` FROM `hilos_analytics_agent_session`');
        $this->assertCount(1, $agents);
        $this->assertSame((int)$workers[0]['id'], (int)$agents[0]['worker_session_id']);
        $this->assertSame(self::AGENT_TYPE, $agents[0]['agent_type']);
        $this->assertSame(self::AGENT_INDEX, $agents[0]['agent_index']);

        $this->assertNull($collector->logUserAction(self::ACCEPT_KEY, self::ACTION_SEND, null));
        $this->assertSame([], $this->rowsOf('SELECT `id` FROM `hilos_analytics_user_action`'));
    }

    /**
     * While the operation may replace the database the collector writes nothing, the flush of
     * a buffer filled before the freeze included; once the phase moves on, that buffer goes out.
     *
     * Activating holds as well as active (HIL-1060): a follower never reaches active in the
     * first operation, and the database may change under it while its row reads activating.
     *
     * The flush is called directly rather than through a due tick: the interval is five
     * seconds of wall clock, and the tick only decides whether to call the same flush.
     *
     * @param string $phase Freeze phase that must hold the collector
     * @throws DatabaseException When the rows cannot be read back
     */
    #[DataProvider('silencingPhases')]
    public function testNothingIsWrittenWhileTheFreezeSilencesTheCollector(string $phase): void
    {
        $collector = new AnalyticsCollector();
        $collector->openWorkerSession(self::WORKER_INDEX, false);
        $collector->logWorkerSystemSignal(self::SIGNAL_NAME, null);

        $this->freeze($phase);
        $this->assertNull($collector->openWsConnection(self::ACCEPT_KEY, null));
        $collector->logWorkerSystemSignal(self::SIGNAL_NAME, null);
        $collector->flush();

        $this->assertSame([], $this->rowsOf('SELECT `id` FROM `hilos_analytics_ws_connection`'));
        $this->assertSame([], $this->rowsOf('SELECT `id` FROM `hilos_analytics_worker_system_signal`'));

        $this->freeze(ProtectedModeRuntime::PHASE_VERIFYING);
        $collector->flush();

        // The signal recorded before the freeze, and not the one dropped under it.
        $this->assertCount(1, $this->rowsOf('SELECT `id` FROM `hilos_analytics_worker_system_signal`'));
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
     * under it is a true fact of that database - stamped with its own moment.
     *
     * @throws DatabaseException When the rows cannot be read back
     */
    public function testAnAgentStoppedUnderTheFreezeIsStampedWithTheMomentItStopped(): void
    {
        $collector = new AnalyticsCollector();
        $collector->openWorkerSession(self::WORKER_INDEX, false);
        $collector->openAgentSession(self::AGENT_TYPE, self::AGENT_INDEX);

        $this->freeze(ProtectedModeRuntime::PHASE_ACTIVE);
        $before = $this->nowMs();
        $collector->closeAgentSession(self::AGENT_TYPE, self::AGENT_INDEX);
        $after = $this->nowMs();
        $this->assertNull($this->rowsOf('SELECT `stopped_ts` FROM `hilos_analytics_agent_session`')[0]['stopped_ts']);

        usleep(self::HELD_SPAN_MICROSECONDS);
        $this->freeze(ProtectedModeRuntime::PHASE_VERIFYING);
        $collector->tick();

        $agents = $this->rowsOf('SELECT `stopped_ts` FROM `hilos_analytics_agent_session`');
        $this->assertCount(1, $agents);
        $this->assertGreaterThanOrEqual($before, (int)$agents[0]['stopped_ts']);
        $this->assertLessThanOrEqual($after, (int)$agents[0]['stopped_ts']);
    }

    /**
     * The swap is a fresh start, as a restart would be: a collector an error switched off
     * comes back on, and says so.
     *
     * @throws DatabaseException When the table cannot be dropped, the schema rebuilt or the rows read back
     */
    public function testACollectorSwitchedOffByAnErrorIsBackOnAfterTheSwap(): void
    {
        $collector = new AnalyticsCollector();
        $collector->openWorkerSession(self::WORKER_INDEX, false);
        Database::sql('DROP TABLE `hilos_analytics_worker_system_signal`');
        $collector->logWorkerSystemSignal(self::SIGNAL_NAME, null);
        $collector->flush();
        $this->assertNull($collector->openWsConnection(self::ACCEPT_KEY, null));

        $this->rebuildAnalyticsSchema();
        $collector->forgetReplacedDatabase();

        $this->assertNotNull($collector->openWsConnection(self::ACCEPT_KEY_AFTER_SWAP, null));
        $this->assertStringContainsString(
            'Analytics collector back on: the database under it was replaced',
            (string)file_get_contents($this->logFile),
        );
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
     * @param int $userActionId User action id
     * @return string Name the action is filed under
     * @throws DatabaseException When the query fails
     */
    private function actionNameOf(int $userActionId): string
    {
        $rows = $this->rowsOf(
            'SELECT `n`.`name` FROM `hilos_analytics_user_action` `a`
             JOIN `hilos_analytics_action_name` `n` ON `n`.`id` = `a`.`action_name_id`
             WHERE `a`.`id` = ?',
            [$userActionId],
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
