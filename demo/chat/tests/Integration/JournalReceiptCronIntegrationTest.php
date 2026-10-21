<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Closure;
use Demo\Chat\Agents\Hilos\UserAgent;
use Demo\Chat\Agents\Hilos\SessionsLibraryAgent;
use Demo\Chat\Agents\Hilos\UsersLibraryAgent;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Database\Database;
use Demo\Chat\Hilos;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Verification\VerificationSweeper;
use Hilos\Auth\Verification\VerificationSweepSettings;
use Hilos\Auth\Verification\VerificationSweepSettingsCatalog;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\AbstractPageFactory;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Page\SignalRouteConfig;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\ChangeLog\JournalReceiptData;
use Hilos\Database\ChangeLog\JournalReceiptScope;
use Hilos\Database\ChangeLog\JournalTriggerFiles;
use Hilos\Database\ChangeLog\JournalTriggerInstaller;
use Hilos\Database\Database as FrameworkDatabase;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Migration;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\HilosException;
use Hilos\Socket\Worker\DTO\AgentStartDTO;
use Hilos\Socket\Worker\DTO\CronSignalDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\WorkerDaemonClient;
use Hilos\Socket\Worker\WorkerDTO;
use LogicException;
use mysqli;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use ReflectionProperty;
use RuntimeException;

/** HIL-1456: one cron attempt across local parts, using the existing setting journal triggers. */
final class JournalReceiptCronIntegrationTest extends IntegrationTestCase
{
    private const string KEY_PREFIX = 'journal.receipt.hil1456.';

    private const array JOURNAL_CLEANUP_ORDER = [
        'hilos_change_log_value', 'hilos_change_log_change', 'hilos_change_log',
        'hilos_change_log_receipt', 'hilos_change_log_field', 'hilos_change_log_table',
    ];

    /** @var array<string, int> Journal high water marks before this case */
    private array $baseline = [];

    /** @var list<array<string, mixed>> Trigger definitions present before this case */
    private array $originalTriggers = [];

    private ?SignalRouter $previousRouter;
    private ?BrowserContext $previousBrowser;
    private ?string $previousAgentId;
    private ?SettingsAccessor $previousSettings;

    /** @var list<SourceChangeSubscriberInterface> Reactions restored after the observer is removed */
    private array $previousReactions;

    /** @var list<int> People whose factors this case changes */
    private array $userIds = [];

    /** Installs the declared journal triggers and remembers the pre-test journal. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousRouter = Hilos::$sr;
        $this->previousBrowser = Hilos::$browser;
        $this->previousAgentId = ExecutionContext::currentAgentId();
        $this->previousSettings = Hilos::$setting;
        $this->previousReactions = new ReflectionProperty(SourceChangeBus::class, 'reactions')->getValue();
        Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Migration::setMigrationListPath(dirname(__DIR__, 2) . '/backend/Database/Migration');
        Migration::setMigrationName('Schema');
        JournalTriggerFiles::setPath(dirname(__DIR__, 2) . '/backend/Database/Migration/Triggers');
        $this->originalTriggers = self::triggerCatalog();
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        foreach (self::JOURNAL_CLEANUP_ORDER as $table) {
            Database::sql("SELECT COALESCE(MAX(`id`), 0) AS `id` FROM {$database}.`{$table}`");
            $this->baseline[$table] = (int) Database::field('id');
        }
        JournalTriggerInstaller::apply();
    }

    /** Removes only this case's fixtures and restores the original trigger catalog. */
    protected function tearDown(): void
    {
        Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Database::setJournalReceiptId(null);
        new ReflectionProperty(SourceChangeBus::class, 'reactions')->setValue(null, $this->previousReactions);
        Hilos::$setting = $this->previousSettings;
        ExecutionContext::setCurrentAgentId($this->previousAgentId);
        Hilos::$sr = $this->previousRouter;
        if ($this->previousBrowser === null) {
            Hilos::resetBrowser();
        } else {
            Hilos::initBrowser($this->previousBrowser);
        }
        foreach ($this->userIds as $id) {
            Database::sqlRun('DELETE FROM `hilos_session` WHERE `user_id` = ?', [$id]);
            Database::sqlRun('DELETE FROM `hilos_access_log` WHERE `user_id` = ?', [$id]);
            Database::sqlRun('DELETE FROM `hilos_second_factor` WHERE `user_id` = ?', [$id]);
            Database::sqlRun('DELETE FROM `hilos_second_factor_reset` WHERE `user_id` = ?', [$id]);
            if (isset(Hilos::$db->users[$id])) {
                Hilos::$db->users[$id]->actions->delete();
            }
        }
        Database::sqlRun('DELETE FROM `hilos_user_verification` WHERE `identifier` = ?', [self::KEY_PREFIX]);
        Database::sqlRun('DELETE FROM `hilos_setting` WHERE `key` LIKE ?', [self::KEY_PREFIX . '%']);
        foreach (self::triggerCatalog() as $row) {
            Database::sql('DROP TRIGGER `' . $row['TRIGGER_NAME'] . '`');
        }
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        foreach (self::JOURNAL_CLEANUP_ORDER as $table) {
            Database::sqlRun("DELETE FROM {$database}.`{$table}` WHERE `id` > ?", [$this->baseline[$table]]);
        }
        foreach ($this->originalTriggers as $row) {
            Database::sql('CREATE TRIGGER `' . $row['TRIGGER_NAME'] . '` ' . $row['ACTION_TIMING']
                . ' ' . $row['EVENT_MANIPULATION'] . ' ON `' . $row['EVENT_OBJECT_TABLE']
                . '` FOR EACH ROW ' . $row['ACTION_STATEMENT']);
        }
        parent::tearDown();
    }

    /** The worker hook, agent and real page dispatch share one receipt, including legacy frames. */
    public function testWorkerCronDispatchIncludesEveryHandlerAndIsolatesJobsAndRetries(): void
    {
        $worker = $this->worker();
        $ids = [];
        foreach (['first-job', 'second-job', 'first-job'] as $index => $job) {
            $handlerIds = [];
            $worker->cronHook = function () use (&$handlerIds, $index): void {
                $handlerIds[] = JournalReceiptScope::currentId();
                $this->writeSetting("hook-{$index}");
            };
            $worker->testAgent()->cronWork = function () use (&$handlerIds, $index): void {
                $handlerIds[] = JournalReceiptScope::currentId();
                $this->writeSetting("agent-{$index}");
            };
            $worker->testAgent()->pageWork = function () use (&$handlerIds, $index): void {
                $handlerIds[] = JournalReceiptScope::currentId();
                $this->writeSetting("page-{$index}");
            };
            $dto = $index === 1 ? CronSignalDTO::fromArray(['cronName' => $job]) : new CronSignalDTO($job, '*/5 * * * *');
            $worker->deliverCron($dto);
            $id = $handlerIds[0];
            self::assertNotNull($id);
            self::assertSame([$id, $id, $id], $handlerIds);
            self::assertNotContains($id, $ids);
            $ids[] = $id;
            $this->assertCronReceipt($id, $job, $dto->cronExpression, JournalReceiptCronTestAgent::AGENT_TYPE);
            self::assertSame(3, $this->logCount($id));
            self::assertSame(0, (int)$this->receipt($id)['open_handovers']);
            self::assertNull(JournalReceiptScope::currentId());
            $this->assertConnectionReceipt(null);
            $this->writeSetting("unrelated-{$index}");
            $this->assertLastLogReceipt(null);
        }
    }

    /** An accepted continuation retains the actual dispatch receipt, even without a first write. */
    public function testWorkerCronCanRetainItsReceiptForAnAcceptedLocalContinuation(): void
    {
        $worker = $this->worker();
        $retained = null;
        $worker->testAgent()->cronWork = static function () use (&$retained): void {
            $retained = JournalReceiptScope::retainCurrent();
            self::assertSame(JournalReceiptScope::currentId(), $retained);
        };
        $worker->deliverCron(new CronSignalDTO('continued-dispatch', '* * * * *'));
        self::assertNotNull($retained);
        self::assertSame(1, (int)$this->receipt($retained)['open_handovers']);
        self::assertSame(0, $this->logCount($retained));
        self::assertNull(JournalReceiptScope::currentId());
        self::assertNull(JournalReceiptScope::retainCurrent());
        $this->assertConnectionReceipt(null);
        $this->writeSetting('between-dispatch-parts');
        $this->assertLastLogReceipt(null);
        try {
            JournalReceiptScope::runPart($retained, JournalReceiptCronTestAgent::AGENT_TYPE, function (): void {
                $this->writeSetting('continued-dispatch');
            });
        } finally {
            JournalReceiptScope::finish($retained);
        }
        $this->assertCronReceipt($retained, 'continued-dispatch', '* * * * *', JournalReceiptCronTestAgent::AGENT_TYPE);
        self::assertSame(1, $this->logCount($retained));
        self::assertSame(0, (int)$this->receipt($retained)['open_handovers']);
        $this->assertConnectionReceipt(null);
    }

    /** A no-op or rejected frame leaves no receipt; a failed attempt cannot color its retry. */
    public function testWorkerCronNoopInvalidPayloadAndFailureCloseTheirScopes(): void
    {
        $worker = $this->worker();
        $emptyId = null;
        $worker->cronHook = static function () use (&$emptyId): void {
            $emptyId = JournalReceiptScope::currentId();
        };
        $worker->deliverCron(new CronSignalDTO('empty', '* * * * *'));
        self::assertNotNull($emptyId);
        self::assertNull($this->receipt($emptyId));

        $worker->cronHook = static fn () => throw new LogicException('Invalid payload reached the hook');
        $worker->deliverCron(new SignalData([]));
        $this->assertConnectionReceipt(null);

        $failure = new RuntimeException('Cron business failure');
        $failedId = null;
        $worker->cronHook = null;
        $worker->testAgent()->cronWork = function () use (&$failedId, $failure): void {
            $failedId = JournalReceiptScope::currentId();
            $this->writeSetting('failed-dispatch');
            throw $failure;
        };
        try {
            $worker->deliverCron(new CronSignalDTO('failing-job', '* * * * *'));
            self::fail('The worker must propagate the cron failure');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertNotNull($failedId);
        self::assertSame(0, (int)$this->receipt($failedId)['open_handovers']);
        self::assertNull(JournalReceiptScope::currentId());
        $this->assertConnectionReceipt(null);
        $worker->testAgent()->cronWork = function () use ($failedId): void {
            self::assertNotSame($failedId, JournalReceiptScope::currentId());
            $this->writeSetting('retry-dispatch');
        };
        $worker->deliverCron(new CronSignalDTO('failing-job', '* * * * *'));
    }

    /** Disabling the journal never disables the real cron dispatch. */
    public function testWorkerCronRunsWithoutConfiguredJournal(): void
    {
        $worker = $this->worker();
        $property = new ReflectionProperty(FrameworkDatabase::class, 'configurations');
        $original = $property->getValue();
        $withoutJournal = $original;
        unset($withoutJournal[ChangeLogDatabase::CONNECTION_INDEX]);
        $property->setValue(null, $withoutJournal);
        $calls = 0;
        try {
            $worker->testAgent()->cronWork = function () use (&$calls): void {
                $calls++;
                self::assertNull(JournalReceiptScope::currentId());
                $this->writeSetting('unjournaled-dispatch');
            };
            $worker->deliverCron(new CronSignalDTO('without-journal', '* * * * *'));
            self::assertSame(1, $calls);
            $this->assertLastLogReceipt(null);
        } finally {
            $property->setValue(null, $original);
        }
    }

    /** The library's actual scheduled sweep hands one receipt to the actual person writer. */
    public function testLocalSecondFactorSweepHandsCronToTheRealPersonAgent(): void
    {
        $userId = $this->seedSecondFactorRemoval();
        $worker = $this->worker();
        $worker->handleDaemonMessage(new AgentStartDTO(UsersLibraryAgent::AGENT_TYPE));
        $person = UserAgent::AGENT_TYPE . ':' . $userId;
        $worker->handleDaemonMessage(new AgentStartDTO($person));
        $worker->tickUsers();
        $due = $this->nextAgentFrameNamed(HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_DUE);
        $id = $due->data->receiptId;
        self::assertNotNull($id);
        self::assertSame(0, $this->logCount($id));
        self::assertSame(1, (int)$this->receipt($id)['open_handovers']);
        $this->assertConnectionReceipt(null);
        $worker->handleDaemonMessage(new DaemonAgentMessageDTO($person, $due));
        Database::sql('SELECT COUNT(*) AS `count` FROM `hilos_second_factor` WHERE `user_id` = ?', [$userId]);
        self::assertSame(0, (int)Database::field('count'));
        $this->assertCronReceipt($id, 'hilos_second_factor_reset_sweep', '* * * * *', $person);
        self::assertSame(1, $this->logCount($id));
        $reply = $this->nextAgentFrameNamed(HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_DUE_DONE);
        self::assertSame($id, $reply->data->receiptId);
        $worker->handleDaemonMessage(new DaemonAgentMessageDTO(UsersLibraryAgent::AGENT_TYPE, $reply));
        // Further session/notification work writes no journaled table. Consume its real envelopes
        // through worker join while keeping those unrelated subsystems outside this test's fixture.
        while (($frame = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($frame->data instanceof AgentSignalData && $frame->data->receiptId === $id) {
                $worker->handleDaemonMessage(new DaemonAgentMessageDTO(JournalReceiptCronTestAgent::AGENT_TYPE, $frame));
            }
        }
        self::assertSame(0, (int)$this->receipt($id)['open_handovers']);
        $this->assertCronReceipt($id, 'hilos_second_factor_reset_sweep', '* * * * *', $person);
        self::assertNull(JournalReceiptScope::currentId());
        $this->assertConnectionReceipt(null);
    }

    /** A cancellation won before the due frame; the real reply releases the last empty hold. */
    public function testLocalSecondFactorNoopHandoverDeletesItsEmptyReceipt(): void
    {
        $userId = $this->seedSecondFactorRemoval();
        $worker = $this->worker();
        $worker->handleDaemonMessage(new AgentStartDTO(UsersLibraryAgent::AGENT_TYPE));
        $person = UserAgent::AGENT_TYPE . ':' . $userId;
        $worker->handleDaemonMessage(new AgentStartDTO($person));
        $worker->tickUsers();
        $due = $this->nextAgentFrameNamed(HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_DUE);
        $id = $due->data->receiptId;
        self::assertNotNull($id);
        Database::sqlRun('UPDATE `hilos_second_factor_reset` SET `canceled_at` = NOW() WHERE `user_id` = ?', [$userId]);
        $worker->handleDaemonMessage(new DaemonAgentMessageDTO($person, $due));
        self::assertSame(0, $this->logCount($id));
        self::assertSame(1, (int)$this->receipt($id)['open_handovers']);
        $reply = $this->nextAgentFrameNamed(HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_DUE_DONE);
        self::assertSame($id, $reply->data->receiptId);
        $worker->handleDaemonMessage(new DaemonAgentMessageDTO(UsersLibraryAgent::AGENT_TYPE, $reply));
        self::assertNull($this->receipt($id));
        self::assertNull(JournalReceiptScope::currentId());
        $this->assertConnectionReceipt(null);
    }

    /** Real local batches preserve their first schedule and survive an empty first journal portion. */
    public function testLocalVerificationBatchesKeepTheirReceiptAcrossTicksAndOtherJobs(): void
    {
        $worker = $this->verificationWorker();
        $this->seedVerifications(VerificationSweeper::BATCH + 1);
        $observer = new JournalReceiptCronSweepObserver();
        SourceChangeBus::subscribe($observer);
        $worker->tickUsers();
        self::assertCount(VerificationSweeper::BATCH, $observer->receiptIds);
        $id = $observer->receiptIds[0];
        self::assertNotNull($id);
        self::assertSame([$id], array_values(array_unique($observer->receiptIds)));
        self::assertSame(0, $this->logCount($id));
        self::assertSame(1, (int)$this->receipt($id)['open_handovers']);
        $this->assertConnectionReceipt(null);
        self::assertNull(JournalReceiptScope::currentId());
        $this->writeSetting('between-sweeps');
        $this->assertLastLogReceipt(null);
        $worker->testAgent()->cronWork = function () use ($id): void {
            self::assertNotSame($id, JournalReceiptScope::currentId());
            $this->writeSetting('neighbor-job');
        };
        $worker->deliverCron(new CronSignalDTO('neighbor', '* * * * *'));
        $settings = Hilos::$setting;
        self::assertInstanceOf(JournalReceiptCronSweepSettings::class, $settings);
        $settings->cron = '0-59 * * * *';
        new ReflectionProperty(AbstractUsersLibraryAgent::class, 'verificationSweepCheckedMinute')->setValue($worker->users(), -1);
        $observer->onDelete = function (): void {
            $this->writeSetting('second-sweep-part');
        };
        $worker->tickUsers();
        self::assertCount(VerificationSweeper::BATCH + 1, $observer->receiptIds);
        self::assertSame($id, $observer->receiptIds[VerificationSweeper::BATCH]);
        self::assertSame(1, $this->logCount($id));
        self::assertSame(0, (int)$this->receipt($id)['open_handovers']);
        $this->assertCronReceipt($id, 'hilos_verification_sweep', '* * * * *', UsersLibraryAgent::AGENT_TYPE);
        $this->assertConnectionReceipt(null);
        self::assertNull($this->verificationReceipt($worker));

        $this->seedVerifications(1);
        $this->verificationRule($worker)->lastRun = 0.0;
        $observer->onDelete = function (): void {
            $this->writeSetting('next-sweep-attempt');
        };
        $worker->tickUsers();
        $next = $observer->receiptIds[array_key_last($observer->receiptIds)];
        self::assertNotSame($id, $next);
        $this->assertCronReceipt($next, 'hilos_verification_sweep', '0-59 * * * *', UsersLibraryAgent::AGENT_TYPE);
    }

    /** The library's stop closes the still-empty attempt once and drops its backlog state. */
    public function testLocalVerificationStopReleasesTheUnfinishedEmptyAttempt(): void
    {
        $worker = $this->verificationWorker();
        $this->seedVerifications(VerificationSweeper::BATCH);
        $worker->tickUsers();
        $id = $this->verificationReceipt($worker);
        self::assertNotNull($id);
        self::assertSame(1, (int)$this->receipt($id)['open_handovers']);
        $worker->users()->onStop();
        self::assertNull($this->receipt($id));
        self::assertNull($this->verificationReceipt($worker));
        $worker->users()->onStop();
        $this->assertConnectionReceipt(null);
    }

    /** A failure during a real row removal closes its attempt; the next scheduled pass starts anew. */
    public function testLocalVerificationFailureDoesNotLeakIntoItsNextAttempt(): void
    {
        $worker = $this->verificationWorker();
        $this->seedVerifications(2);
        $observer = new JournalReceiptCronSweepObserver();
        $failure = new RuntimeException('Verification reaction failed');
        $observer->onDelete = function () use ($failure): void {
            $this->writeSetting('failed-local-sweep');
            throw $failure;
        };
        SourceChangeBus::subscribe($observer);
        try {
            $worker->tickUsers();
            self::fail('The real sweep must propagate its failed reaction');
        } catch (SourceChangeSubscriberException $caught) {
            self::assertSame($failure, $caught->getPrevious());
        }
        $failed = $observer->receiptIds[0];
        self::assertNotNull($failed);
        self::assertSame(0, (int)$this->receipt($failed)['open_handovers']);
        self::assertNull($this->verificationReceipt($worker));
        self::assertNull(JournalReceiptScope::currentId());
        $this->assertConnectionReceipt(null);
        $observer->onDelete = function (): void {
            $this->writeSetting('retried-local-sweep');
        };
        $this->verificationRule($worker)->lastRun = 0.0;
        $worker->tickUsers();
        $retry = $observer->receiptIds[array_key_last($observer->receiptIds)];
        self::assertNotSame($failed, $retry);
        self::assertSame(0, (int)$this->receipt($retry)['open_handovers']);
        $this->assertConnectionReceipt(null);
    }

    /** A null receipt without the journal does not mean a full batch stopped its attempt. */
    public function testLocalVerificationBacklogContinuesWithoutConfiguredJournal(): void
    {
        $worker = $this->verificationWorker();
        $this->seedVerifications(VerificationSweeper::BATCH + 1);
        $observer = new JournalReceiptCronSweepObserver();
        SourceChangeBus::subscribe($observer);
        $property = new ReflectionProperty(FrameworkDatabase::class, 'configurations');
        $original = $property->getValue();
        $withoutJournal = $original;
        unset($withoutJournal[ChangeLogDatabase::CONNECTION_INDEX]);
        $property->setValue(null, $withoutJournal);
        try {
            $worker->tickUsers();
            self::assertCount(VerificationSweeper::BATCH, $observer->receiptIds);
            self::assertNull($this->verificationReceipt($worker));
            $worker->tickUsers();
            self::assertCount(VerificationSweeper::BATCH + 1, $observer->receiptIds);
            self::assertSame([null], array_values(array_unique($observer->receiptIds)));
            $this->assertConnectionReceipt(null);
        } finally {
            $worker->users()->onStop();
            $property->setValue(null, $original);
        }
    }

    /** @return array<string, array{bool}> Sweep fixtures alone and beside another person's eligible rows */
    public static function neighboringSweepRows(): array
    {
        return ['own rows' => [false], 'neighboring eligible rows' => [true]];
    }

    /**
     * Two backlogs in one sessions holder retain independent receipts and release their own holds.
     *
     * @param bool $withNeighbor Whether another person's eligible rows precede the fixture
     * @throws HilosException When fixture writes or a sweep fails
     */
    #[DataProvider('neighboringSweepRows')]
    public function testLocalSessionAndAccessLogSweepsDoNotShareAnAttempt(bool $withNeighbor): void
    {
        $neighborSessionId = null;
        $neighborAccessId = null;
        if ($withNeighbor) {
            $neighborId = (int)Hilos::$db->users->actions->createWithName('Cron sweep neighbor')->id;
            $this->userIds[] = $neighborId;
            Database::sql('INSERT INTO `hilos_session` (`token`, `user_id`, `expires_at`)'
                . ' VALUES (?, ?, DATE_SUB(NOW(), INTERVAL 2 DAY))', ['1456ffffffffffffffffffffffffffff', $neighborId]);
            $neighborSessionId = Database::lastInsertId();
            Database::sql('INSERT INTO `hilos_access_log` (`user_id`, `event`, `occurred_at`)'
                . ' VALUES (?, ?, DATE_SUB(NOW(), INTERVAL 3 YEAR))', [$neighborId, 'sign_in']);
            $neighborAccessId = Database::lastInsertId();
        }
        $userId = (int)Hilos::$db->users->actions->createWithName('Cron sessions target')->id;
        $this->userIds[] = $userId;
        $sessionIds = [];
        $accessIds = [];
        for ($index = 0; $index < 501; $index++) {
            Database::sql('INSERT INTO `hilos_session` (`token`, `user_id`, `expires_at`)'
                . ' VALUES (?, ?, DATE_SUB(NOW(), INTERVAL 1 DAY))', ['14560000' . sprintf('%024x', $index), $userId]);
            $sessionIds[] = Database::lastInsertId();
            Database::sql('INSERT INTO `hilos_access_log` (`user_id`, `event`, `occurred_at`)'
                . ' VALUES (?, ?, DATE_SUB(NOW(), INTERVAL 2 YEAR))', [$userId, 'sign_in']);
            $accessIds[] = Database::lastInsertId();
        }
        $worker = $this->worker();
        $worker->handleDaemonMessage(new AgentStartDTO(SessionsLibraryAgent::AGENT_TYPE));
        $holder = $worker->sessions();
        foreach (['sessionSweep' => 'hilos_sessions_expire', 'accessLogSweep' => 'hilos_access_log_expire'] as $field => $name) {
            $rule = new CronRule($name, '* * * * *');
            $rule->lastRun = 0.0;
            new ReflectionProperty(AbstractSessionsLibraryAgent::class, $field . 'Rule')->setValue($holder, $rule);
        }
        $sessions = new JournalReceiptCronSweepObserver(HilosDbContext::sessions);
        $access = new JournalReceiptCronSweepObserver(HilosDbContext::accessLogEntries);
        SourceChangeBus::subscribe($sessions);
        SourceChangeBus::subscribe($access);
        ExecutionContext::setCurrentAgentId(SessionsLibraryAgent::AGENT_TYPE);
        $holder->onTick();
        self::assertCount(500, $sessions->receiptIds);
        self::assertCount(500, $access->receiptIds);
        $sessionReceipt = $sessions->receiptIds[0];
        $accessReceipt = $access->receiptIds[0];
        self::assertNotNull($sessionReceipt);
        self::assertNotNull($accessReceipt);
        self::assertNotSame($sessionReceipt, $accessReceipt);
        $this->assertCronReceipt($sessionReceipt, 'hilos_sessions_expire', '* * * * *', SessionsLibraryAgent::AGENT_TYPE);
        $this->assertCronReceipt($accessReceipt, 'hilos_access_log_expire', '* * * * *', SessionsLibraryAgent::AGENT_TYPE);
        foreach (['sessionSweep' => $sessionReceipt, 'accessLogSweep' => $accessReceipt] as $field => $id) {
            self::assertTrue(new ReflectionProperty(AbstractSessionsLibraryAgent::class, $field . 'Backlog')->getValue($holder));
            self::assertSame($id, new ReflectionProperty(AbstractSessionsLibraryAgent::class, $field . 'ReceiptId')->getValue($holder));
            self::assertGreaterThanOrEqual(1, (int)$this->receipt($id)['open_handovers']);
        }
        foreach (['hilos_session', 'hilos_access_log'] as $table) {
            Database::sql('SELECT COUNT(*) AS `remaining` FROM `' . $table . '` WHERE `user_id` = ?', [$userId]);
            self::assertGreaterThan(0, (int)Database::field('remaining'));
        }
        self::assertNull(JournalReceiptScope::currentId());
        $this->assertConnectionReceipt(null);
        $this->writeSetting('between-holder-parts');
        $this->assertLastLogReceipt(null);
        $holder->onTick();
        $expectedSessions = array_fill_keys($sessionIds, $sessionReceipt);
        $expectedAccess = array_fill_keys($accessIds, $accessReceipt);
        $ownSessions = array_intersect_key($sessions->receiptIdsByRowId, $expectedSessions);
        $ownAccess = array_intersect_key($access->receiptIdsByRowId, $expectedAccess);
        self::assertCount(501, $ownSessions);
        self::assertCount(501, $ownAccess);
        self::assertEquals($expectedSessions, $ownSessions);
        self::assertEquals($expectedAccess, $ownAccess);
        self::assertSame([$sessionReceipt], array_values(array_unique($sessions->receiptIds)));
        self::assertSame([$accessReceipt], array_values(array_unique($access->receiptIds)));
        if ($withNeighbor) {
            self::assertSame($sessionReceipt, $sessions->receiptIdsByRowId[$neighborSessionId]);
            self::assertSame($accessReceipt, $access->receiptIdsByRowId[$neighborAccessId]);
        }
        foreach (['hilos_session', 'hilos_access_log'] as $table) {
            Database::sql('SELECT COUNT(*) AS `remaining` FROM `' . $table . '` WHERE `user_id` = ?', [$userId]);
            self::assertSame(0, (int)Database::field('remaining'));
        }
        foreach (['sessionSweep', 'accessLogSweep'] as $field) {
            self::assertFalse(new ReflectionProperty(AbstractSessionsLibraryAgent::class, $field . 'Backlog')->getValue($holder));
            self::assertNull(new ReflectionProperty(AbstractSessionsLibraryAgent::class, $field . 'ReceiptId')->getValue($holder));
        }
        while (($frame = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($frame->data instanceof AgentSignalData && in_array($frame->data->receiptId, [$sessionReceipt, $accessReceipt], true)) {
                $worker->handleDaemonMessage(new DaemonAgentMessageDTO(JournalReceiptCronTestAgent::AGENT_TYPE, $frame));
            }
        }
        self::assertNull($this->receipt($sessionReceipt));
        self::assertNull($this->receipt($accessReceipt));
        $this->assertConnectionReceipt(null);
    }

    /** Empty and rolled-back parts retain the attempt; unrelated writes have no receipt. */
    public function testPartsShareOneReceiptWithoutHoldingATransactionOrLeakingAttribution(): void
    {
        $id = JournalReceiptScope::start(JournalReceiptData::cron('sweep', '*/5 * * * *', 'initiator'));
        $this->assertNotNull($id);
        $started = $this->receipt($id);
        $this->assertSame([null, null, null, 'cron', 'sweep', 'initiator', 'sweep (*/5 * * * *)'], [
            $started['actor_user_id'], $started['subject_user_id'], $started['session_id'],
            $started['channel'], $started['action'], $started['agent'], $started['source'],
        ]);
        $this->assertNotEmpty($started['created_at']);
        $this->assertSame(1, (int) $started['open_handovers']);
        $this->assertConnectionReceipt(null);
        try {
            $this->assertSame('empty', JournalReceiptScope::runPart($id, 'no-writes', function () use ($id): string {
                $this->assertSame($id, JournalReceiptScope::currentId());
                $this->assertConnectionReceipt($id);
                return 'empty';
            }));
            $this->assertSame('initiator', $this->receipt($id)['agent']);
            $this->assertSame(1, (int) $this->receipt($id)['open_handovers']);

            JournalReceiptScope::runPart($id, 'rolled-back-writer', function (): void {
                Database::transactionStart();
                try {
                    $this->writeSetting('rolled-back');
                } finally {
                    Database::transactionRollback();
                }
            });
            $this->assertSame(0, $this->logCount($id));
            $this->assertSame('initiator', $this->receipt($id)['agent']);

            JournalReceiptScope::runPart($id, 'first-writer', function (): void {
                Database::transactionStart();
                try {
                    $this->writeSetting('first');
                    Database::transactionCommit();
                } catch (DatabaseException $failure) {
                    Database::transactionRollback();
                    throw $failure;
                }
            });
            $this->assertSame('first-writer', $this->receipt($id)['agent']);
            $this->assertNull(JournalReceiptScope::currentId());
            $this->assertNull(JournalReceiptScope::stampHandover());
            $this->assertConnectionReceipt(null);
            $this->writeSetting('unrelated');
            $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
            Database::sql("SELECT `receipt_id` FROM {$database}.`hilos_change_log` ORDER BY `id` DESC LIMIT 1");
            $this->assertNull(Database::field('receipt_id'));

            JournalReceiptScope::runPart($id, 'second-writer', function (): void {
                $this->writeSetting('second');
            });
            JournalReceiptScope::runPart($id, 'empty-after-write', static fn (): string => 'done');
            $this->assertSame(2, $this->logCount($id));
            $this->assertSame('second-writer', $this->receipt($id)['agent']);
            $this->assertSame($started['created_at'], $this->receipt($id)['created_at']);
            $this->assertSame($started['source'], $this->receipt($id)['source']);
            $this->assertSame(1, (int) $this->receipt($id)['open_handovers']);
        } finally {
            JournalReceiptScope::finish($id);
        }
        $this->assertSame(0, (int) $this->receipt($id)['open_handovers']);
        $this->assertConnectionReceipt(null);
    }

    /** Each rule and each new attempt gets a separate id, including after failure. */
    public function testJobsAndRetriesGetSeparateReceipts(): void
    {
        $first = JournalReceiptScope::start(JournalReceiptData::cron('first-rule', '* * * * *', 'initiator'));
        $second = JournalReceiptScope::start(JournalReceiptData::cron('second-rule', null, 'initiator'));
        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $failure = new RuntimeException('Attempt failed');
        try {
            JournalReceiptScope::runPart($first, 'writer', static fn () => throw $failure);
            self::fail('The original business failure must leave runPart');
        } catch (RuntimeException $caught) {
            $this->assertSame($failure, $caught);
        } finally {
            JournalReceiptScope::finish($first);
        }
        $this->assertNull($this->receipt($first));
        $retry = JournalReceiptScope::start(JournalReceiptData::cron('first-rule', '*/2 * * * *', 'initiator'));
        $this->assertNotNull($retry);
        try {
            $this->assertNotSame($first, $second);
            $this->assertNotSame($first, $retry);
            $this->assertNotSame($second, $retry);
            $this->assertSame('second-rule', $this->receipt($second)['source']);
            $this->assertSame('first-rule (*/2 * * * *)', $this->receipt($retry)['source']);
        } finally {
            JournalReceiptScope::finish($second);
            JournalReceiptScope::finish($retry);
        }
        $this->assertNull($this->receipt($second));
        $this->assertNull($this->receipt($retry));
        $this->assertNull(JournalReceiptScope::currentId());
        $this->assertConnectionReceipt(null);
    }

    /** A partial write and its outgoing frame survive a business error without replaying the work. */
    public function testBusinessFailureStillAccountsForWritesAndHandovers(): void
    {
        $id = JournalReceiptScope::start(JournalReceiptData::cron('partial-failure', null, 'initiator'));
        $this->assertNotNull($id);
        $failure = new RuntimeException('Failed after a committed write');
        $calls = 0;
        try {
            JournalReceiptScope::runPart($id, 'partial-writer', function () use ($failure, &$calls): void {
                $calls++;
                $this->writeSetting('partial-failure');
                JournalReceiptScope::stampHandover();
                throw $failure;
            });
            self::fail('The business error must escape unchanged');
        } catch (RuntimeException $caught) {
            $this->assertSame($failure, $caught);
            $this->assertSame(2, (int) $this->receipt($id)['open_handovers']);
            $this->assertSame('partial-writer', $this->receipt($id)['agent']);
        } finally {
            JournalReceiptScope::finish($id);
        }
        $this->assertSame(1, $calls);
        $this->assertSame(1, $this->logCount($id));
        $this->assertSame(1, (int) $this->receipt($id)['open_handovers']);
        JournalReceiptScope::join($id, 'reply-only', static fn (): string => 'done');
        $this->assertSame(0, (int) $this->receipt($id)['open_handovers']);
        $this->assertSame('partial-writer', $this->receipt($id)['agent']);
        $this->assertNull(JournalReceiptScope::currentId());
        $this->assertConnectionReceipt(null);
    }

    /** Local completion and recipient completion may arrive in either order. */
    public function testEmptyReceiptLivesUntilBothLocalAndAgentHoldsAreReleased(): void
    {
        foreach ([false, true] as $finishBeforeJoin) {
            $id = JournalReceiptScope::start(JournalReceiptData::cron('handover', null, 'initiator'));
            $this->assertNotNull($id);
            JournalReceiptScope::runPart($id, 'sender', function () use ($id): void {
                $this->assertSame($id, JournalReceiptScope::stampHandover());
                $this->assertSame($id, JournalReceiptScope::stampHandover());
            });
            $this->assertSame(3, (int) $this->receipt($id)['open_handovers']);
            $this->assertSame('initiator', $this->receipt($id)['agent']);
            JournalReceiptScope::join($id, 'first-recipient', static fn (): string => 'no-op');
            $this->assertSame(2, (int) $this->receipt($id)['open_handovers']);
            if ($finishBeforeJoin) {
                JournalReceiptScope::finish($id);
            } else {
                JournalReceiptScope::join($id, 'last-recipient', static fn (): string => 'no-op');
            }
            $this->assertSame(1, (int) $this->receipt($id)['open_handovers']);
            $this->assertSame('initiator', $this->receipt($id)['agent']);
            if ($finishBeforeJoin) {
                JournalReceiptScope::join($id, 'last-recipient', static fn (): string => 'no-op');
            } else {
                JournalReceiptScope::finish($id);
            }
            $this->assertNull($this->receipt($id));
        }
    }

    /** A recipient that writes becomes the writer while the original cron source stays fixed. */
    public function testWritingHandoverKeepsCronAttributionAfterLocalFinish(): void
    {
        $id = JournalReceiptScope::start(JournalReceiptData::cron('handover-write', '* * * * *', 'initiator'));
        $this->assertNotNull($id);
        JournalReceiptScope::runPart($id, 'sender', static fn (): ?int => JournalReceiptScope::stampHandover());
        JournalReceiptScope::finish($id);
        $this->assertSame(1, (int) $this->receipt($id)['open_handovers']);
        JournalReceiptScope::join($id, 'recipient-writer', function (): void {
            $this->writeSetting('recipient');
            JournalReceiptScope::stampHandover();
        });
        $this->assertSame(1, (int) $this->receipt($id)['open_handovers']);
        JournalReceiptScope::join($id, 'reply-only', static fn (): string => 'done');
        $this->assertSame(0, (int) $this->receipt($id)['open_handovers']);
        $this->assertSame(1, $this->logCount($id));
        $this->assertSame('recipient-writer', $this->receipt($id)['agent']);
        $this->assertSame('handover-write (* * * * *)', $this->receipt($id)['source']);
        $this->assertSame('cron', $this->receipt($id)['channel']);
    }

    /** Nested scopes restore the outer stack and the caller's secondary connection index. */
    public function testNestedPartsRestoreThePreviousReceiptAndConnectionIndex(): void
    {
        $outer = JournalReceiptScope::run(JournalReceiptData::web(11, null, 12, 'outer', 'web-agent'), function (): ?int {
            $outer = JournalReceiptScope::currentId();
            Database::useConnection(ChangeLogDatabase::CONNECTION_INDEX);
            Database::sql('SET @hilos_receipt = NULL');
            $id = JournalReceiptScope::start(JournalReceiptData::cron('nested', null, 'initiator'));
            $this->assertNotNull($id);
            $this->assertSame(ChangeLogDatabase::CONNECTION_INDEX, Database::getCurrentIndex());
            $this->assertSame($outer, JournalReceiptScope::currentId());
            try {
                JournalReceiptScope::runPart($id, 'nested-writer', function () use ($id): void {
                    $this->assertSame(ChangeLogDatabase::CONNECTION_INDEX, Database::getCurrentIndex());
                    $this->assertConnectionReceipt(null);
                    $this->assertSame($id, JournalReceiptScope::currentId());
                    Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
                    $this->assertConnectionReceipt($id);
                    $inner = JournalReceiptScope::run(JournalReceiptData::agent('inner', 'inner-agent'), function (): ?int {
                        $this->writeSetting('inner');
                        return JournalReceiptScope::currentId();
                    });
                    $this->assertNotSame($inner, $id);
                    $this->assertSame($id, JournalReceiptScope::currentId());
                    $this->assertConnectionReceipt($id);
                    $this->assertSame($id, JournalReceiptScope::stampHandover());
                    $this->writeSetting('nested');
                });
                $this->assertSame(ChangeLogDatabase::CONNECTION_INDEX, Database::getCurrentIndex());
                $this->assertConnectionReceipt(null);
                $this->assertSame($outer, JournalReceiptScope::currentId());
                JournalReceiptScope::finish($id);
                $this->assertSame(ChangeLogDatabase::CONNECTION_INDEX, Database::getCurrentIndex());
                JournalReceiptScope::join($id, 'reply', static fn (): string => 'done');
                $this->assertSame($outer, JournalReceiptScope::stampHandover());
            } finally {
                Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
            }
            $this->assertSame(0, (int) $this->receipt($id)['open_handovers']);
            $this->assertConnectionReceipt($outer);
            $this->writeSetting('outer');
            return $outer;
        });
        $this->assertNotNull($outer);
        $this->assertSame(1, (int) $this->receipt($outer)['open_handovers']);
        JournalReceiptScope::join($outer, 'outer-reply', static fn (): string => 'done');
        $this->assertSame(0, (int) $this->receipt($outer)['open_handovers']);
        $this->assertSame(1, $this->logCount($outer));
        $this->assertNull(JournalReceiptScope::currentId());
        $this->assertConnectionReceipt(null);
    }

    /**
     * A failed initial SET and a later reconnect both reinstall the part's receipt before writing.
     * mysqli warns before throwing on the deliberately killed SET link; this case checks its recovery.
     */
    #[WithoutErrorHandler]
    public function testReconnectBeforeAndDuringAPartRestoresItsReceipt(): void
    {
        $id = JournalReceiptScope::start(JournalReceiptData::cron('reconnect', null, 'initiator'));
        $this->assertNotNull($id);
        try {
            $this->killPrimaryConnection();
            JournalReceiptScope::runPart($id, 'writer', function () use ($id): void {
                $this->assertConnectionReceipt($id);
                $this->writeSetting('after-failed-set');
                $this->killPrimaryConnection();
                // Reconnect on a read, so this test does not rely on replaying an ambiguous business INSERT.
                $this->assertConnectionReceipt($id);
                $this->writeSetting('after-reconnect');
            });
            $this->assertSame(2, $this->logCount($id));
            $this->assertSame('writer', $this->receipt($id)['agent']);
            $this->assertConnectionReceipt(null);
        } finally {
            JournalReceiptScope::finish($id);
        }
        $this->assertSame(0, (int) $this->receipt($id)['open_handovers']);
    }

    /**
     * Cleanup failures may leave an orphan, but never replace the business error or leak its id.
     * mysqli warns before throwing on the deliberately killed SET link; this case checks its cleanup.
     */
    #[WithoutErrorHandler]
    public function testFailedRestorationAndFinishPreserveTheOriginalBusinessError(): void
    {
        $id = JournalReceiptScope::start(JournalReceiptData::cron('cleanup-error', null, 'initiator'));
        $this->assertNotNull($id);
        $failure = new RuntimeException('Original business error');
        Database::useConnection(ChangeLogDatabase::CONNECTION_INDEX);
        try {
            try {
                JournalReceiptScope::runPart($id, 'writer', function () use ($failure): void {
                    Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
                    $this->killPrimaryConnection();
                    throw $failure;
                });
            } finally {
                JournalReceiptScope::finish($id);
            }
            self::fail('A business error cannot be swallowed by finalization');
        } catch (RuntimeException $caught) {
            $this->assertSame($failure, $caught);
            $this->assertSame(ChangeLogDatabase::CONNECTION_INDEX, Database::getCurrentIndex());
            $this->assertNull(JournalReceiptScope::currentId());
            $this->assertNull(JournalReceiptScope::stampHandover());
        } finally {
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        }
        $this->assertConnectionReceipt(null);
        $this->writeSetting('after-cleanup-error');
        $this->assertSame(0, $this->logCount($id));
        $this->assertSame(1, (int) $this->receipt($id)['open_handovers']);
    }

    /** A lost response to the receipt INSERT is not retried; the next attempt gets a clean link. */
    public function testLostStartDoesNotReplayTheInsertOrChangeTheCurrentIndex(): void
    {
        $this->killPrimaryConnection();
        Database::useConnection(ChangeLogDatabase::CONNECTION_INDEX);
        try {
            JournalReceiptScope::start(JournalReceiptData::cron('lost-start', null, 'initiator'));
            self::fail('The ambiguous receipt insert must fail');
        } catch (DatabaseException) {
            $this->assertSame(ChangeLogDatabase::CONNECTION_INDEX, Database::getCurrentIndex());
            $this->assertNull(JournalReceiptScope::currentId());
        } finally {
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        }
        $this->assertConnectionReceipt(null);
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        Database::sql("SELECT COUNT(*) AS `count` FROM {$database}.`hilos_change_log_receipt` WHERE `action` = 'lost-start'");
        $this->assertSame(0, (int) Database::field('count'));
        $id = JournalReceiptScope::start(JournalReceiptData::cron('next-start', null, 'initiator'));
        $this->assertNotNull($id);
        JournalReceiptScope::finish($id);
        $this->assertNull($this->receipt($id));
    }

    /** With no journal configured the lifecycle calls the work and leaves both SQL and stack alone. */
    public function testLifecycleWithoutConfiguredJournalRunsTheCallback(): void
    {
        $property = new ReflectionProperty(FrameworkDatabase::class, 'configurations');
        $original = $property->getValue();
        $withoutJournal = $original;
        unset($withoutJournal[ChangeLogDatabase::CONNECTION_INDEX]);
        $property->setValue(null, $withoutJournal);
        try {
            $id = JournalReceiptScope::start(JournalReceiptData::cron('disabled', null, 'initiator'));
            $this->assertNull($id);
            $this->assertSame('worked', JournalReceiptScope::runPart($id, 'writer', function (): string {
                $this->assertNull(JournalReceiptScope::currentId());
                $this->assertNull(JournalReceiptScope::stampHandover());
                $this->writeSetting('without-journal');
                return 'worked';
            }));
            JournalReceiptScope::finish($id);
            $this->assertSame('existing-id', JournalReceiptScope::runPart(123, 'writer', static fn (): string => 'existing-id'));
            JournalReceiptScope::finish(123);
            $this->assertNull(JournalReceiptScope::currentId());
            $this->assertConnectionReceipt(null);
        } finally {
            $property->setValue(null, $original);
        }
    }

    /** @return JournalReceiptCronTestWorker Production dispatch with a local daemon link */
    private function worker(): JournalReceiptCronTestWorker
    {
        Hilos::initBrowser();
        $worker = new JournalReceiptCronTestWorker();
        $worker->handleDaemonMessage(new AgentStartDTO(JournalReceiptCronTestAgent::AGENT_TYPE));
        return $worker;
    }

    /**
     * @param int $id Receipt under test
     * @param string $rule Initiating rule
     * @param ?string $expression Schedule captured at start
     * @param string $writer Actual journal writer
     */
    private function assertCronReceipt(int $id, string $rule, ?string $expression, string $writer): void
    {
        $receipt = $this->receipt($id);
        self::assertNotNull($receipt);
        self::assertSame('cron', $receipt['channel']);
        self::assertSame($rule, $receipt['action']);
        self::assertSame($expression === null ? $rule : $rule . ' (' . $expression . ')', $receipt['source']);
        self::assertSame($writer, $receipt['agent']);
        self::assertNull($receipt['actor_user_id']);
        self::assertNull($receipt['subject_user_id']);
        self::assertNull($receipt['session_id']);
    }

    /** @param ?int $id Receipt expected on the latest actual journal write */
    private function assertLastLogReceipt(?int $id): void
    {
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        Database::sql("SELECT `receipt_id` FROM {$database}.`hilos_change_log` ORDER BY `id` DESC LIMIT 1");
        $actual = Database::field('receipt_id');
        self::assertSame($id, $actual === null ? null : (int)$actual);
    }

    /**
     * @param string $name Queued handover name
     * @return SignalDTO Actual frame emitted by the production sender
     */
    private function nextAgentFrameNamed(string $name): SignalDTO
    {
        while (($frame = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($frame->signalName->getName() === $name && $frame->data instanceof AgentSignalData) {
                return $frame;
            }
        }
        self::fail("The cron handler owes frame {$name}");
    }

    /** @return int Person with an app and an overdue removal request */
    private function seedSecondFactorRemoval(): int
    {
        $userId = (int)Hilos::$db->users->actions->createWithName('Cron factor target')->id;
        $this->userIds[] = $userId;
        Database::sqlRun('INSERT INTO `hilos_second_factor` (`user_id`, `label`, `confirmed_at`) VALUES (?, ?, NOW())',
            [$userId, 'Cron test app']);
        Database::sqlRun('INSERT INTO `hilos_second_factor_reset`'
            . ' (`user_id`, `requested_at`, `effective_at`, `cancel_token`, `notified_at`)'
            . ' VALUES (?, NOW(), DATE_SUB(NOW(), INTERVAL 1 DAY), ?, NOW())', [$userId, 'cron-cancel-token']);
        return $userId;
    }

    /** @return JournalReceiptCronTestWorker A real library with its first verification sweep due */
    private function verificationWorker(): JournalReceiptCronTestWorker
    {
        Hilos::$setting = new JournalReceiptCronSweepSettings(VerificationSweepSettingsCatalog::class);
        $worker = $this->worker();
        $worker->handleDaemonMessage(new AgentStartDTO(UsersLibraryAgent::AGENT_TYPE));
        // The first tick builds the rule; making only its clock due keeps cadence tests deterministic.
        $worker->tickUsers();
        $this->verificationRule($worker)->lastRun = 0.0;
        return $worker;
    }

    /**
     * @param JournalReceiptCronTestWorker $worker Library host
     * @return CronRule Actual live verification rule
     */
    private function verificationRule(JournalReceiptCronTestWorker $worker): CronRule
    {
        return new ReflectionProperty(AbstractUsersLibraryAgent::class, 'verificationSweepRule')->getValue($worker->users());
    }

    /**
     * @param JournalReceiptCronTestWorker $worker Library host
     * @return ?int Local hold, absent after termination or without a journal
     */
    private function verificationReceipt(JournalReceiptCronTestWorker $worker): ?int
    {
        return new ReflectionProperty(AbstractUsersLibraryAgent::class, 'verificationSweepReceiptId')->getValue($worker->users());
    }

    /** @param int $count Expired rows to exercise the actual sweeper and its batch limit */
    private function seedVerifications(int $count): void
    {
        for ($index = 0; $index < $count; $index++) {
            Database::sqlRun('INSERT INTO `hilos_user_verification` (`type`, `identifier`, `created_at`, `expires_at`)'
                . ' VALUES (?, ?, DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY))',
                ['register_confirm', self::KEY_PREFIX]);
        }
    }

    /** @param string $suffix Unique setting fixture key within this test */
    private function writeSetting(string $suffix): void
    {
        Database::sqlRun('INSERT INTO `hilos_setting` (`key`, `type`, `value`) VALUES (?, ?, ?)',
            [self::KEY_PREFIX . $suffix, 'string', 'written'], tryReconnect: false);
    }

    /** @param ?int $id Expected receipt on the currently selected connection */
    private function assertConnectionReceipt(?int $id): void
    {
        Database::sql('SELECT @hilos_receipt AS `receipt`');
        $value = Database::field('receipt');
        $this->assertSame($id, $value === null ? null : (int) $value);
    }

    /**
     * @param int $id Attempt receipt
     * @return ?array<string, mixed> Stored receipt, or null after empty completion
     */
    private function receipt(int $id): ?array
    {
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        Database::sql("SELECT * FROM {$database}.`hilos_change_log_receipt` WHERE `id` = ?", [$id]);
        return Database::row();
    }

    /**
     * @param int $id Attempt receipt
     * @return int Committed journal rows for this attempt
     */
    private function logCount(int $id): int
    {
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        Database::sql("SELECT COUNT(*) AS `count` FROM {$database}.`hilos_change_log` WHERE `receipt_id` = ?", [$id]);
        return (int) Database::field('count');
    }

    /** Drops the server session while leaving the client's link stale for the next operation. */
    private function killPrimaryConnection(): void
    {
        Database::sql('SELECT CONNECTION_ID() AS `id`');
        $connectionId = (int) Database::field('id');
        $config = Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX);
        $killer = new mysqli($config->host, $config->user, $config->password, $config->database, $config->port, $config->socket);
        $killer->query("KILL {$connectionId}");
        $killer->close();
    }

    /** @return list<array<string, mixed>> Primary schema trigger definitions */
    private static function triggerCatalog(): array
    {
        Database::sql('SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, EVENT_MANIPULATION, ACTION_TIMING, ACTION_STATEMENT'
            . ' FROM INFORMATION_SCHEMA.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME');
        return Database::rows();
    }
}


/** Drives production worker dispatch and production local rules without an external daemon. */
final class JournalReceiptCronTestWorker extends WorkerManager
{
    public ?Closure $cronHook = null;

    public function __construct()
    {
        parent::__construct(1);
        $this->daemonClient = new JournalReceiptCronTestClient();
    }

    /** @return JournalReceiptCronTestAgent Test writer registered in the real agent manager */
    public function testAgent(): JournalReceiptCronTestAgent
    {
        return $this->agentManager->getAgent(JournalReceiptCronTestAgent::AGENT_TYPE);
    }

    /** @return UsersLibraryAgent Production library whose local rules the test drives */
    public function users(): UsersLibraryAgent
    {
        return $this->agentManager->getAgent(UsersLibraryAgent::AGENT_TYPE);
    }

    /** @return SessionsLibraryAgent Production holder with two independent local backlogs */
    public function sessions(): SessionsLibraryAgent
    {
        return $this->agentManager->getAgent(SessionsLibraryAgent::AGENT_TYPE);
    }

    public function tickUsers(): void
    {
        ExecutionContext::setCurrentAgentId(UsersLibraryAgent::AGENT_TYPE);
        $this->users()->onTick();
    }

    /** @param SignalDataInterface $payload Cron envelope, or a deliberately invalid payload */
    public function deliverCron(SignalDataInterface $payload): void
    {
        $this->handleDaemonMessage(new DaemonAgentMessageDTO(JournalReceiptCronTestAgent::AGENT_TYPE, new SignalDTO(
            new SignalSource(SignalSource::DAEMON),
            new SignalType(SignalTypeConstants::CRON),
            new SignalName($payload instanceof CronSignalDTO ? $payload->cronName : 'invalid'),
            $payload,
        )));
    }

    /**
     * @param string $cron Fired rule
     * @param CronSignalDTO $signalData Parsed payload
     */
    protected function onCronHandled(string $cron, CronSignalDTO $signalData): void
    {
        ($this->cronHook ?? static fn () => null)();
    }

    /** @return SignalRouter Project routes for real person handovers */
    protected function createSignalRouter(): SignalRouter
    {
        return new ChatSignalRouter();
    }

    /** @return AgentManager Manager that can create the fixture and the actual auth agents */
    protected function createAgentManager(): AgentManager
    {
        return new JournalReceiptCronTestAgentManager();
    }

    /**
     * @param AgentInterface $agent Owning agent
     * @return PageSignalRouter Actual page dispatcher with a test cron page
     */
    protected function createPageSignalRouter(AgentInterface $agent): PageSignalRouter
    {
        return new PageSignalRouter(
            new JournalReceiptCronTestPageFactory($agent),
            new ActionRouteConfig(),
            new SignalRouteConfig($agent instanceof JournalReceiptCronTestAgent ? [SignalTypeConstants::CRON => 'cron-test'] : []),
        );
    }
}

final class JournalReceiptCronTestClient extends WorkerDaemonClient
{
    /** @param WorkerDTO|array $data Frame captured locally rather than sent to an external daemon */
    public function send(WorkerDTO|array $data): void
    {
    }

    /** @return bool The local link is always available */
    public function isConnected(): bool
    {
        return true;
    }
}

final class JournalReceiptCronTestAgentManager extends AgentManager
{
    /**
     * @param string $agentType Agent to start
     * @param ?string $agentIndex Person index when applicable
     * @return AgentInterface Real auth agent or test writer
     */
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        return match ($agentType) {
            SessionsLibraryAgent::AGENT_TYPE => new SessionsLibraryAgent(),
            UsersLibraryAgent::AGENT_TYPE => new UsersLibraryAgent(),
            UserAgent::AGENT_TYPE => new UserAgent($agentIndex ?? throw new LogicException('Person index required')),
            default => new JournalReceiptCronTestAgent(),
        };
    }
}

final class JournalReceiptCronTestPageFactory extends AbstractPageFactory
{
    /**
     * @param string $pageName Test page name
     * @return AbstractPage A page with the normal cron hook
     */
    protected function createPage(string $pageName): AbstractPage
    {
        return new JournalReceiptCronTestPage($this->agent);
    }

    /**
     * @param string $pageName Requested page
     * @return bool Whether this is the fixture page
     */
    public function hasPage(string $pageName): bool
    {
        return $pageName === 'cron-test';
    }
}

final class JournalReceiptCronTestPage extends AbstractPage
{
    /**
     * @param SignalDataInterface $data Cron payload
     * @param string $source Source of the frame
     * @param string $name Fired rule
     */
    public function onSignalCron(SignalDataInterface $data, string $source, string $name): void
    {
        if ($this->agent instanceof JournalReceiptCronTestAgent) {
            ($this->agent->pageWork ?? static fn () => null)();
        }
    }
}

final class JournalReceiptCronTestAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'journal_receipt_cron_test';

    public ?Closure $cronWork = null;
    public ?Closure $pageWork = null;

    public function onStop(): void
    {
    }

    /**
     * @param SignalDataInterface $data Cron payload
     * @param string $source Source of the frame
     * @param string $name Fired rule
     */
    public function onSignalCron(SignalDataInterface $data, string $source, string $name): void
    {
        ($this->cronWork ?? static fn () => null)();
    }

    /**
     * Accepts reply-only handovers without producing another write or frame.
     *
     * @param AgentSignalData $data Real handover envelope
     * @param string $sender Source agent
     * @param string $name Reply name
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
    }
}


/** Observes real deletions; an optional test write uses the existing journaled setting table. */
final class JournalReceiptCronSweepObserver implements SourceChangeSubscriberInterface
{
    /** @var list<?int> Receipts observed inside real sweeper writes */
    public array $receiptIds = [];
    /** @var array<int|string, ?int> Deletion receipts keyed by the actual row identity */
    public array $receiptIdsByRowId = [];
    public ?Closure $onDelete = null;

    /** @param string $sourceKey Collection whose actual deletions are observed */
    public function __construct(private readonly string $sourceKey = HilosDbContext::verifications)
    {
    }

    /**
     * @param SourceChange $change Actual mutation
     * @param SourceChangeProvenance $provenance Writer location
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        if ($change->kind !== SourceChange::KIND_DB || $change->sourceKey !== $this->sourceKey
            || $change->mutationType !== TableMutationType::Delete) {
            return;
        }
        $this->receiptIds[] = JournalReceiptScope::currentId();
        $this->receiptIdsByRowId[$change->sourceId] = JournalReceiptScope::currentId();
        ($this->onDelete ?? static fn () => null)();
    }
}

/** Changes the live schedule between ticks without changing any production catalog or schema. */
final class JournalReceiptCronSweepSettings extends SettingsAccessor
{
    public string $cron = '* * * * *';

    /**
     * @param string $key Requested setting
     * @return mixed Current configured value
     */
    public function effectiveValueFor(string $key): mixed
    {
        return $key === VerificationSweepSettings::SWEEP_CRON_KEY ? $this->cron : parent::effectiveValueFor($key);
    }
}
