<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Closure;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Database\Database;
use Demo\Chat\Environment\ChatEnvCatalog;
use Demo\Chat\Hilos;
use Hilos\Backup\Agent\BackupAgent;
use Hilos\Backup\Agent\BackupCronJob;
use Hilos\Backup\Agent\BackupRunKind;
use Hilos\Backup\Agent\DTO\BackupCreateSignalData;
use Hilos\Backup\Agent\DTO\BackupRestoreSignalData;
use Hilos\Backup\BackupConstants;
use Hilos\Backup\BackupSchedule;
use Hilos\Backup\BackupScope;
use Hilos\Backup\RestoreEnvDecision;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Page\SignalRouteConfig;
use Hilos\Core\Process;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalNameInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\SignalTypeInterface;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\ChangeLog\JournalReceiptData;
use Hilos\Database\ChangeLog\JournalReceiptScope;
use Hilos\Database\ChangeLog\JournalTriggerFiles;
use Hilos\Database\ChangeLog\JournalTriggerInstaller;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database as FrameworkDatabase;
use Hilos\Database\Entity\Item\ChangeLogReceipt as EntityChangeLogReceipt;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Migration;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Environment\EnvAccessor;
use Hilos\Log\LogAggregatorAgent;
use Hilos\Log\LogStoreAgent;
use Hilos\Socket\Worker\DTO\AgentStartDTO;
use Hilos\Socket\Worker\DTO\CronSignalDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\WorkerDaemonClient;
use Hilos\Socket\Worker\WorkerDTO;
use mysqli;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use ReflectionProperty;
use RuntimeException;

/**
 * HIL-1456: production maintenance supervisors over real journal triggers and temporary files.
 * Process doubles control the in-process interval between parts, which no browser can observe;
 * admission still spawns through the actual backup entry, with a harmless temporary CLI fixture.
 */
final class JournalReceiptMaintenanceIntegrationTest extends IntegrationTestCase
{
    private const string KEY_PREFIX = 'journal.receipt.maintenance.';
    private const string RULE = 'receipt-backup';
    private const string EXPRESSION = '* * * * *';
    private const array JOURNAL_TABLES = [
        'hilos_change_log_value', 'hilos_change_log_change', 'hilos_change_log',
        'hilos_change_log_receipt', 'hilos_change_log_field', 'hilos_change_log_table',
    ];

    /** @var array<string, int> Pre-case journal high water marks */
    private array $baseline = [];
    /** @var list<array<string, mixed>> Pre-case trigger definitions */
    private array $originalTriggers = [];
    private ?EnvAccessor $previousEnv;
    private ?SettingsAccessor $previousSettings;
    private ?SignalRouter $previousRouter;
    private ?BrowserContext $previousBrowser;
    private ?string $previousAgentId;
    private JournalReceiptMaintenanceEnv $environment;
    private JournalReceiptMaintenanceRouter $router;
    private ?JournalReceiptMaintenanceWorker $worker = null;
    private ?BackupAgent $backup = null;
    private string $directory;
    private int $writeCount = 0;

    /** Installs existing declared triggers and isolates the maintenance storage and environment. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousEnv = Hilos::$env;
        $this->previousSettings = Hilos::$setting;
        $this->previousRouter = Hilos::$sr;
        $this->previousBrowser = Hilos::$browser;
        $this->previousAgentId = ExecutionContext::currentAgentId();
        $this->directory = sys_get_temp_dir() . '/hilos-receipt-maintenance-' . uniqid('', true);
        mkdir($this->directory, 0755, true);
        file_put_contents($this->directory . '/cli.php', <<<'PHP'
<?php
declare(strict_types=1);
echo json_encode(['argv' => $argv, 'receiptEnv' => array_values(array_filter(
    array_keys(getenv()), static fn (string $key): bool => str_contains(strtolower($key), 'receipt'),
))], JSON_THROW_ON_ERROR);
PHP);
        Hilos::$env = $this->environment = new JournalReceiptMaintenanceEnv(ChatEnvCatalog::class);
        $this->environment->values = [
            EnvConstants::BACKUP_ENABLED->name => true,
            EnvConstants::BACKUP_DIR->name => $this->directory,
            EnvConstants::BACKUP_CLI_ENTRY->name => $this->directory . '/cli.php',
            EnvConstants::BACKUP_MIN_FREE_BYTES->name => 0,
            EnvConstants::BACKUP_REFUSE_WITHOUT_ESTIMATE->name => false,
            EnvConstants::BACKUP_SHIP_TARGET->name => '',
            EnvConstants::DAEMON_LOG_FILE->name => $this->directory . '/daemon.log',
            EnvConstants::DAEMON_ERROR_LOG_FILE->name => $this->directory . '/daemon-error.log',
            EnvConstants::LOG_ROTATION_MAX_AGE_SECONDS->name => 0,
            EnvConstants::LOG_ROTATION_MAX_LIVE_SIZE_BYTES->name => 0,
            EnvConstants::LOG_ROTATION_CRON->name => '',
        ];
        Hilos::$setting = null;
        Hilos::$sr = $this->router = new JournalReceiptMaintenanceRouter();
        Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Migration::setMigrationListPath(dirname(__DIR__, 2) . '/backend/Database/Migration');
        Migration::setMigrationName('Schema');
        JournalTriggerFiles::setPath(dirname(__DIR__, 2) . '/backend/Database/Migration/Triggers');
        $this->originalTriggers = self::triggerCatalog();
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        foreach (self::JOURNAL_TABLES as $table) {
            Database::sql("SELECT COALESCE(MAX(`id`), 0) AS `id` FROM {$database}.`{$table}`");
            $this->baseline[$table] = (int)Database::field('id');
        }
        JournalTriggerInstaller::apply();
    }

    /** Stops children and watches before removing fixtures and restoring process globals. */
    protected function tearDown(): void
    {
        $this->environment->onRead = null;
        $this->router->onSignal = null;
        if ($this->backup !== null) {
            // A fault-injection double has already proved its failing halt; cleanup must be total.
            $child = self::property($this->backup, 'childProcess');
            if ($child !== null && $child::class !== Process::class) {
                self::setProperty($this->backup, 'childProcess', null);
            }
            self::setProperty($this->backup, 'shipProcess', null);
            self::setProperty($this->backup, 'pendingScope', null);
            $this->underAgent($this->backup, fn () => $this->backup->onStop());
        }
        Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Database::setJournalReceiptId(null);
        Database::sqlRun('DELETE FROM `hilos_setting` WHERE `key` LIKE ?', [self::KEY_PREFIX . '%']);
        foreach (self::triggerCatalog() as $row) {
            Database::sql('DROP TRIGGER `' . $row['TRIGGER_NAME'] . '`');
        }
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        foreach (self::JOURNAL_TABLES as $table) {
            Database::sqlRun("DELETE FROM {$database}.`{$table}` WHERE `id` > ?", [$this->baseline[$table]]);
        }
        foreach ($this->originalTriggers as $row) {
            Database::sql('CREATE TRIGGER `' . $row['TRIGGER_NAME'] . '` ' . $row['ACTION_TIMING']
                . ' ' . $row['EVENT_MANIPULATION'] . ' ON `' . $row['EVENT_OBJECT_TABLE']
                . '` FOR EACH ROW ' . $row['ACTION_STATEMENT']);
        }
        Hilos::$env = $this->previousEnv;
        Hilos::$setting = $this->previousSettings;
        Hilos::$sr = $this->previousRouter;
        ExecutionContext::setCurrentAgentId($this->previousAgentId);
        if ($this->previousBrowser === null) {
            Hilos::resetBrowser();
        } else {
            Hilos::initBrowser($this->previousBrowser);
        }
        $this->removeTree($this->directory);
        parent::tearDown();
    }

    /** @return array<string, array{bool}> Both production schedule entrances */
    public static function backupMechanisms(): array
    {
        return ['daemon CRON' => [true], 'local rule' => [false]];
    }

    /** @param bool $daemon Whether to fire through the worker's CRON dispatch */
    #[DataProvider('backupMechanisms')]
    public function testAcceptedBackupKeepsOneReceiptAcrossEmptyAndWritingParts(bool $daemon): void
    {
        $agent = $this->backupAgent();
        $this->fireBackup($daemon);
        $id = self::property($agent, 'cronReceiptId');
        self::assertNotNull($id);
        $this->assertCron($id, self::RULE, self::EXPRESSION, $agent->getId(), 1);
        self::assertSame(0, $this->logCount($id));
        $this->assertClear();

        $part = 0;
        $this->replaceChild(function () use (&$part, $id): void {
            self::assertSame($id, JournalReceiptScope::currentId());
            $this->assertConnection($id);
            if (++$part > 1) {
                $this->writeSetting();
            }
        }, running: true);
        $this->tickBackup();
        self::assertSame(0, $this->logCount($id));
        $this->writeSetting();
        $this->assertLastReceipt(null);
        $this->tickBackup();
        self::assertSame(1, $this->logCount($id));
        $this->replaceChild(static fn () => null, running: true);
        $this->fireBackup(!$daemon);
        self::assertSame($id, self::property($agent, 'cronReceiptId'), 'An overlap cannot replace the accepted attempt');
        self::assertSame(1, $this->newReceiptCount());
        self::assertSame(1, $this->logCount($id));
        $this->assertClear();

        $this->replaceChild(function () use ($id): void {
            self::assertSame($id, JournalReceiptScope::currentId());
            $this->writeSetting();
            $this->backup->handOverDeferredQueue('receipt-maintenance-handover', new SignalData());
        });
        $this->tickBackup();
        self::assertNull(self::property($agent, 'cronReceiptId'));
        self::assertNull(self::property($agent, 'childProcess'));
        $this->assertCron($id, self::RULE, self::EXPRESSION, $agent->getId(), 1);
        $frame = $this->nextFrame('receipt-maintenance-handover');
        self::assertSame($id, $frame->data->receiptId);
        JournalReceiptScope::join($id, 'receipt-maintenance-recipient', static fn () => null);
        $this->assertCron($id, self::RULE, self::EXPRESSION, $agent->getId(), 0);
        $this->assertClear();

        $this->fireBackup($daemon);
        $next = self::property($agent, 'cronReceiptId');
        self::assertNotNull($next);
        self::assertNotSame($id, $next);
        $this->underAgent($agent, fn () => $agent->onStop());
        self::assertNull($this->receipt($next), 'Stopping an empty attempt removes it');
    }

    /** @return iterable<string, array{bool, string}> Every terminal path for each schedule entrance */
    public static function backupTerminations(): iterable
    {
        foreach (self::backupMechanisms() as $name => [$daemon]) {
            foreach (['failed exit', 'timeout', 'poll error', 'stop', 'halt error'] as $outcome) {
                yield $name . ': ' . $outcome => [$daemon, $outcome];
            }
        }
    }

    /**
     * @param bool $daemon Schedule entrance
     * @param string $outcome Terminal event injected through the process boundary
     */
    #[DataProvider('backupTerminations')]
    public function testBackupTerminationReleasesItsHoldAndAllowsANewAttempt(bool $daemon, string $outcome): void
    {
        $agent = $this->backupAgent();
        $this->fireBackup($daemon);
        $id = self::property($agent, 'cronReceiptId');
        self::assertNotNull($id);
        $failure = new RuntimeException('maintenance process failed');
        $child = $this->replaceChild(function () use ($outcome, $failure, $id): void {
            self::assertSame($id, JournalReceiptScope::currentId());
            $this->writeSetting();
            if ($outcome === 'poll error') {
                throw $failure;
            }
        }, running: $outcome === 'timeout', exitCode: 1);
        if ($outcome === 'timeout') {
            self::setProperty($agent, 'timeoutSeconds', 0.0);
        }
        if ($outcome === 'stop' || $outcome === 'halt error') {
            $child->method('halt')->willReturnCallback(function () use ($outcome, $failure, $id): void {
                self::assertSame($id, JournalReceiptScope::currentId());
                $this->writeSetting();
                if ($outcome === 'halt error') {
                    throw $failure;
                }
            });
        }
        try {
            if ($outcome === 'stop' || $outcome === 'halt error') {
                $this->underAgent($agent, fn () => $agent->onStop());
            } else {
                $this->tickBackup();
            }
            self::assertNotContains($outcome, ['poll error', 'halt error']);
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertNull(self::property($agent, 'cronReceiptId'));
        $this->assertCron($id, self::RULE, self::EXPRESSION, $agent->getId(), 0);
        self::assertSame(1, $this->logCount($id));
        $this->assertClear();
        self::setProperty($agent, 'childProcess', null);
        $this->underAgent($agent, fn () => $agent->onStop());
        $this->fireBackup($daemon);
        self::assertNotNull(self::property($agent, 'cronReceiptId'));
        self::assertNotSame($id, self::property($agent, 'cronReceiptId'));
    }

    /** @return iterable<string, array{bool, string, bool, bool}> Continuations of the same child after a failed tick */
    public static function backupContinuations(): iterable
    {
        foreach (self::backupMechanisms() as $name => [$daemon]) {
            foreach (['poll', 'progress'] as $failureAt) {
                foreach ([false, true] as $writeBeforeFailure) {
                    foreach ([false, true] as $stop) {
                        yield $name . ': ' . $failureAt . ', write=' . (int)$writeBeforeFailure . ', stop=' . (int)$stop
                            => [$daemon, $failureAt, $writeBeforeFailure, $stop];
                    }
                }
            }
        }
    }

    /**
     * The worker keeps an agent after a thrown tick; the next tick must continue its existing child.
     *
     * @param bool $daemon Schedule entrance
     * @param string $failureAt Process operation that throws once
     * @param bool $writeBeforeFailure Whether the failed part already committed a journaled write
     * @param bool $stop Whether shutdown closes the resumed attempt instead of a successful child exit
     */
    #[DataProvider('backupContinuations')]
    public function testBackupContinuationAfterTickFailureKeepsCronOrigin(
        bool $daemon,
        string $failureAt,
        bool $writeBeforeFailure,
        bool $stop,
    ): void
    {
        $agent = $this->backupAgent();
        $this->fireBackup($daemon);
        $firstId = self::property($agent, 'cronReceiptId');
        self::assertNotNull($firstId);
        self::property($agent, 'childProcess')->halt();
        $failure = new RuntimeException('one supervisor tick failed');
        $polls = 0;
        $resumedId = null;
        $child = $this->createMock(Process::class);
        $child->expects(self::exactly($stop ? 2 : 3))->method('tick')->willReturnCallback(
            function () use (&$polls, &$resumedId, $firstId, $failure, $failureAt, $writeBeforeFailure, $agent): void {
                $polls++;
                if ($polls === 1) {
                    self::assertSame($firstId, JournalReceiptScope::currentId());
                    if ($writeBeforeFailure) {
                        $this->writeSetting();
                    }
                    if ($failureAt === 'poll') {
                        throw $failure;
                    }
                } elseif ($polls === 2) {
                    $resumedId = JournalReceiptScope::currentId();
                    $this->assertConnection($resumedId);
                    $this->writeSetting();
                    $agent->handOverDeferredQueue('receipt-resumed-backup', new SignalData());
                } else {
                    self::assertSame($resumedId, JournalReceiptScope::currentId());
                }
            },
        );
        $child->method('getStdOut')->willReturnCallback(static function () use (&$polls, $failureAt, $failure): string {
            if ($polls === 1 && $failureAt === 'progress') {
                throw $failure;
            }
            return '';
        });
        $child->method('getStdErr')->willReturn('');
        $child->method('getStatus')->willReturnCallback(static function () use (&$polls): array {
            return [Process::STATUS_RUNNING => $polls < 3];
        });
        $child->method('getExitCode')->willReturn(0);
        $child->expects(self::never())->method('stop');
        $child->expects($stop ? self::once() : self::never())->method('halt')->willReturnCallback(
            static function () use (&$resumedId): void {
                self::assertSame($resumedId, JournalReceiptScope::currentId());
            },
        );
        self::setProperty($agent, 'childProcess', $child);

        try {
            $this->tickBackup();
            self::fail('The injected supervisor failure must escape the tick');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertSame(1, $polls);
        self::assertSame($child, self::property($agent, 'childProcess'));
        self::assertSame(BackupRunKind::CREATE, self::property($agent, 'runKind'));
        self::assertNull(self::property($agent, 'cronReceiptId'));
        if ($writeBeforeFailure) {
            $this->assertCron($firstId, self::RULE, self::EXPRESSION, $agent->getId(), 0);
        } else {
            self::assertNull($this->receipt($firstId));
        }
        self::assertSame((int)$writeBeforeFailure, $this->logCount($firstId));
        $this->assertClear();
        $this->writeSetting();
        $this->assertLastReceipt(null);

        $this->tickBackup();
        self::assertSame(2, $polls);
        self::assertSame($child, self::property($agent, 'childProcess'));
        self::assertNotNull($resumedId, 'The next actual tick of the same child must retain its cron origin');
        self::assertNotSame($firstId, $resumedId);
        self::assertSame($resumedId, self::property($agent, 'cronReceiptId'));
        $this->assertLastReceipt($resumedId);
        $this->assertCron($resumedId, self::RULE, self::EXPRESSION, $agent->getId(), 2);
        self::assertSame(1, $this->logCount($resumedId));
        self::assertSame((int)$writeBeforeFailure, $this->logCount($firstId), 'An earlier write must not be replayed');
        $this->assertClear();
        self::assertSame($resumedId, $this->nextFrame('receipt-resumed-backup')->data->receiptId);
        JournalReceiptScope::join($resumedId, 'receipt-resumed-recipient', fn () => $this->writeSetting());
        $this->assertCron($resumedId, self::RULE, self::EXPRESSION, 'receipt-resumed-recipient', 1);
        $this->assertClear();

        if ($stop) {
            $this->underAgent($agent, fn () => $agent->onStop());
        } else {
            $this->tickBackup();
        }
        self::assertNull(self::property($agent, 'childProcess'));
        self::assertNull(self::property($agent, 'cronReceiptId'));
        $this->assertCron($resumedId, self::RULE, self::EXPRESSION, 'receipt-resumed-recipient', 0);
        $this->assertClear();

        $this->underAgent($agent, fn () => $agent->startBackup(BackupScope::FULL));
        self::assertNotNull(self::property($agent, 'childProcess'));
        $this->replaceChild(fn () => $this->writeSetting());
        $this->tickBackup();
        $this->assertLastReceipt(null);
        $this->assertClear();
    }

    /** @param bool $daemon Schedule entrance whose continuation must wait for its own receipt */
    #[DataProvider('backupMechanisms')]
    public function testBackupContinuationDoesNotPollUnderAnotherScopeWhenItsNewReceiptFails(bool $daemon): void
    {
        $agent = $this->backupAgent();
        $this->fireBackup($daemon);
        $firstId = self::property($agent, 'cronReceiptId');
        $polls = 0;
        $failure = new RuntimeException('first poll failed');
        $resumedId = null;
        $child = $this->replaceChild(function () use (&$polls, &$resumedId, $failure): void {
            if (++$polls === 1) {
                throw $failure;
            }
            $resumedId = JournalReceiptScope::currentId();
            $this->writeSetting();
        });
        try {
            $this->tickBackup();
            self::fail('The first poll must fail');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertNull($this->receipt($firstId));
        $this->assertClear();

        JournalReceiptScope::run(JournalReceiptData::agent('other-operation', 'other-agent'), function () use ($agent, $child, &$polls): void {
            $outerId = JournalReceiptScope::currentId();
            Database::sql('SELECT CONNECTION_ID() AS `id`');
            $connectionId = (int)Database::field('id');
            $config = Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX);
            $killer = new mysqli($config->host, $config->user, $config->password, $config->database, $config->port, $config->socket);
            $killer->query("KILL {$connectionId}");
            $killer->close();
            try {
                $this->tickBackup();
                self::fail('Creating the new receipt on a lost link must fail without polling');
            } catch (DatabaseException) {
                self::assertSame(1, $polls);
                self::assertSame($child, self::property($agent, 'childProcess'));
                self::assertNull(self::property($agent, 'cronReceiptId'));
                self::assertSame($outerId, JournalReceiptScope::currentId());
                $this->assertConnection($outerId);
                self::assertSame(0, $this->logCount($outerId));
            }
        });
        $this->assertClear();
        self::assertSame(0, $this->newReceiptCount());

        $this->tickBackup();
        self::assertSame(2, $polls);
        self::assertNotNull($resumedId);
        self::assertNotSame($firstId, $resumedId);
        $this->assertLastReceipt($resumedId);
        $this->assertCron($resumedId, self::RULE, self::EXPRESSION, $agent->getId(), 0);
        self::assertNull(self::property($agent, 'childProcess'));
        self::assertNull(self::property($agent, 'cronReceiptId'));
        $this->assertClear();
    }

    /** @param bool $daemon Schedule entrance whose run ends before its final publication throws */
    #[DataProvider('backupMechanisms')]
    public function testBackupFailureAfterResetDoesNotStartAnotherCronAttempt(bool $daemon): void
    {
        $agent = $this->backupAgent();
        $this->fireBackup($daemon);
        $id = self::property($agent, 'cronReceiptId');
        $child = $this->replaceChild(fn () => $this->writeSetting());
        $child->expects(self::once())->method('tick');
        $failure = new RuntimeException('terminal runtime publication failed');
        $this->router->onSignal = static function (SignalNameInterface $name, SignalTypeInterface $type) use ($agent, $failure): void {
            if ($type->getType() === SignalTypeConstants::RT_SYNC_UPDATED && self::property($agent, 'childProcess') === null) {
                throw $failure;
            }
        };
        try {
            $this->tickBackup();
            self::fail('The runtime publication after reset must fail');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        } finally {
            $this->router->onSignal = null;
        }
        self::assertNull(self::property($agent, 'childProcess'));
        self::assertNull(self::property($agent, 'runKind'));
        self::assertNull(self::property($agent, 'cronReceiptId'));
        $this->assertCron($id, self::RULE, self::EXPRESSION, $agent->getId(), 0);
        $this->assertClear();

        $this->tickBackup();
        self::assertSame(1, $this->newReceiptCount());
        self::assertSame(1, $this->logCount($id));
        self::assertNull(self::property($agent, 'cronReceiptId'));
        $this->assertClear();
        $this->underAgent($agent, fn () => $agent->startBackup(BackupScope::FULL));
        $this->replaceChild(fn () => $this->writeSetting());
        $this->tickBackup();
        $this->assertLastReceipt(null);
        $this->assertClear();
    }

    /** @return iterable<string, array{bool, bool}> Both schedule entrances and terminal paths */
    public static function emptyBackupTerminations(): iterable
    {
        foreach (self::backupMechanisms() as $name => [$daemon]) {
            yield $name . ': completion' => [$daemon, false];
            yield $name . ': stop' => [$daemon, true];
        }
    }

    /**
     * @param bool $daemon Schedule entrance
     * @param bool $stop Whether the final handover leaves during stop instead of completion
     */
    #[DataProvider('emptyBackupTerminations')]
    public function testEmptyBackupReleasesItsHoldOnlyAfterTheFinalPartPublishesItsHandover(bool $daemon, bool $stop): void
    {
        $agent = $this->backupAgent();
        $this->fireBackup($daemon);
        $id = self::property($agent, 'cronReceiptId');
        $handover = function () use ($agent, $id): void {
            self::assertSame($id, JournalReceiptScope::currentId());
            $agent->handOverDeferredQueue('receipt-empty-final-handover', new SignalData());
        };
        $child = $this->replaceChild($handover);
        if ($stop) {
            $child->method('halt')->willReturnCallback($handover);
            $this->underAgent($agent, fn () => $agent->onStop());
        } else {
            $this->tickBackup();
        }
        self::assertNull(self::property($agent, 'cronReceiptId'));
        $this->assertCron($id, self::RULE, self::EXPRESSION, $agent->getId(), 1);
        self::assertSame(0, $this->logCount($id));
        self::assertSame($id, $this->nextFrame('receipt-empty-final-handover')->data->receiptId);
        JournalReceiptScope::join($id, 'receipt-empty-recipient', static fn () => null);
        self::assertNull($this->receipt($id));
        $this->assertClear();
    }

    /** @param bool $daemon Schedule entrance whose refusal must not attach to other work */
    #[DataProvider('backupMechanisms')]
    public function testRefusedScheduledBackupDoesNotRetainAReceipt(bool $daemon): void
    {
        $agent = $this->backupAgent();
        foreach ([EnvConstants::BACKUP_ENABLED->name => false, EnvConstants::BACKUP_CLI_ENTRY->name => ''] as $key => $value) {
            $previous = $this->environment->values[$key];
            $this->environment->values[$key] = $value;
            $this->fireBackup($daemon);
            self::assertNull(self::property($agent, 'childProcess'));
            self::assertNull(self::property($agent, 'cronReceiptId'));
            self::assertSame(0, $this->newReceiptCount());
            $this->assertClear();
            $this->environment->values[$key] = $previous;
        }
        $this->underAgent($agent, fn () => $agent->startBackup(BackupScope::FULL));
        $this->fireBackup($daemon);
        self::assertNull(self::property($agent, 'cronReceiptId'), 'An overlap cannot adopt a manual CREATE');
        $this->replaceChild(fn () => $this->writeSetting());
        $this->tickBackup();
        $this->assertLastReceipt(null);
        self::assertSame(0, $this->newReceiptCount());
    }

    /** @param bool $daemon Schedule entrance of the run preceding a queued manual CREATE */
    #[DataProvider('backupMechanisms')]
    public function testQueuedManualCreateStartsAfterTheCronScopeAndHoldClose(bool $daemon): void
    {
        $agent = $this->backupAgent();
        $this->fireBackup($daemon);
        $id = self::property($agent, 'cronReceiptId');
        $this->underAgent($agent, fn () => $agent->onSignalAgent(
            new AgentSignalData(new BackupCreateSignalData(BackupScope::SCHEMA_ONLY->value)),
            'backup-page', HilosSignalConstants::BACKUP_AGENT_CREATE,
        ));
        self::assertSame(BackupScope::SCHEMA_ONLY, self::property($agent, 'pendingScope'));
        $this->replaceChild(fn () => $this->writeSetting());
        $manualStarts = 0;
        $this->environment->onRead = function (string $key) use (&$manualStarts, $id): void {
            if ($key !== EnvConstants::BACKUP_CLI_ENTRY->name) {
                return;
            }
            $manualStarts++;
            $this->assertClear();
            self::assertSame(0, (int)$this->receipt($id)['open_handovers']);
            $this->writeSetting();
            $this->assertLastReceipt(null);
        };
        $this->tickBackup();
        $this->environment->onRead = null;
        self::assertSame(1, $manualStarts);
        self::assertSame(BackupScope::SCHEMA_ONLY, self::property($agent, 'currentScope'));
        self::assertNull(self::property($agent, 'pendingScope'));
        self::assertNull(self::property($agent, 'cronReceiptId'));
        $this->replaceChild(fn () => $this->writeSetting());
        $this->tickBackup();
        $this->assertLastReceipt(null);
        self::assertSame(1, $this->logCount($id));
    }

    /** Shipping, an operator reply and a due directory scan on the same tick use no backup receipt. */
    public function testBackupTickKeepsShippingScanAndOperatorWorkOutsideItsCronPart(): void
    {
        $agent = $this->backupAgent();
        $this->fireBackup(true);
        $id = self::property($agent, 'cronReceiptId');
        $this->replaceChild(function () use ($id): void {
            self::assertSame($id, JournalReceiptScope::currentId());
            $this->writeSetting();
        }, running: true);
        $shipping = $this->createMock(Process::class);
        $shipping->expects(self::once())->method('tick')->willReturnCallback(function (): void {
            $this->assertClear();
            $this->writeSetting();
            $this->assertLastReceipt(null);
        });
        $shipping->method('getStatus')->willReturn([Process::STATUS_RUNNING => true]);
        self::setProperty($agent, 'shipProcess', $shipping);
        self::setProperty($agent, 'shipStartedAt', microtime(true));
        self::property($agent, 'directoryRescanSchedule')->noteScan(0.0);
        $scanReads = 0;
        $this->environment->onRead = function (string $key) use (&$scanReads): void {
            if ($key === EnvConstants::BACKUP_DIR->name) {
                $scanReads++;
                $this->assertClear();
                $this->writeSetting();
                $this->assertLastReceipt(null);
            }
        };
        self::setProperty($agent, 'protectedModeOperatorCorrelationId', 'receipt-operator');
        self::setProperty($agent, 'protectedModeOperatorSince', 0.0);
        $replies = 0;
        $this->router->onSignal = function (SignalNameInterface $name, SignalTypeInterface $type) use (&$replies): void {
            if ($type->getType() === SignalTypeConstants::COMMAND_REPLY) {
                $replies++;
                $this->assertClear();
                $this->writeSetting();
                $this->assertLastReceipt(null);
            }
        };
        $this->tickBackup();
        self::assertGreaterThan(0, $scanReads);
        self::assertSame(1, $replies);
        self::assertSame(1, $this->logCount($id));
        $this->assertCron($id, self::RULE, self::EXPRESSION, $agent->getId(), 1);
        $this->assertClear();
    }

    /** A manual restore admitted after cron completion stays outside cron, including schedule overlaps. */
    public function testManualRestoreAndItsPendingFreezeCannotAdoptACronAttempt(): void
    {
        $agent = $this->backupAgent();
        $this->fireBackup(true);
        $id = self::property($agent, 'cronReceiptId');
        $this->replaceChild(fn () => $this->writeSetting());
        $this->tickBackup();
        $this->underAgent($agent, fn () => $agent->onSignalAgent(
            new AgentSignalData(new BackupRestoreSignalData('receipt-restore', BackupScope::FULL->value, RestoreEnvDecision::ALLOW->value)),
            'backup-page', HilosSignalConstants::BACKUP_AGENT_RESTORE,
        ));
        self::assertSame('receipt-restore', self::property($agent, 'pendingRestoreId'));
        self::assertNull(self::property($agent, 'childProcess'));
        $this->fireBackup(true);
        $this->fireBackup(false);
        self::assertNull(self::property($agent, 'cronReceiptId'));
        self::assertNull(self::property($agent, 'childProcess'));
        $this->underAgent($agent, fn () => $agent->onProtectedModeReady());
        self::assertSame(BackupRunKind::RESTORE, self::property($agent, 'runKind'));
        self::assertInstanceOf(Process::class, self::property($agent, 'childProcess'));
        $this->replaceChild(function (): void {
            $this->assertClear();
            $this->writeSetting();
            $this->assertLastReceipt(null);
        }, running: true);
        $this->fireBackup(true);
        $this->tickBackup();
        self::assertSame(1, $this->newReceiptCount());
        $this->assertCron($id, self::RULE, self::EXPRESSION, $agent->getId(), 0);
        self::assertSame(1, $this->logCount($id));
        self::assertNull(self::property($agent, 'cronReceiptId'));
        $this->assertClear();
        // The harmless fixture imported nothing, so finish through the pre-import refusal path.
        self::setProperty($agent, 'childProcess', null);
        $this->underAgent($agent, fn () => $agent->onProtectedModeRefused('fixture canceled before import'));
    }

    /** @param bool $daemon Schedule entrance when the optional journal connection is absent */
    #[DataProvider('backupMechanisms')]
    public function testScheduledBackupStillStartsAndCompletesWithoutConfiguredJournal(bool $daemon): void
    {
        $agent = $this->backupAgent();
        $property = new ReflectionProperty(FrameworkDatabase::class, 'configurations');
        $original = $property->getValue();
        $configurations = $original;
        unset($configurations[ChangeLogDatabase::CONNECTION_INDEX]);
        $property->setValue(null, $configurations);
        try {
            $this->fireBackup($daemon);
            self::assertInstanceOf(Process::class, self::property($agent, 'childProcess'));
            self::assertNull(self::property($agent, 'cronReceiptId'));
            $polls = 0;
            $failure = new RuntimeException('no-journal supervisor tick failed');
            $child = $this->replaceChild(function () use (&$polls, $failure): void {
                $this->assertClear();
                if (++$polls === 1) {
                    throw $failure;
                }
                $this->writeSetting();
            });
            try {
                $this->tickBackup();
                self::fail('The first poll must fail without a journal too');
            } catch (RuntimeException $caught) {
                self::assertSame($failure, $caught);
            }
            self::assertSame(1, $polls);
            self::assertSame($child, self::property($agent, 'childProcess'));
            self::assertNull(self::property($agent, 'cronReceiptId'));
            $this->assertClear();
            $this->tickBackup();
            self::assertSame(2, $polls);
            self::assertNull(self::property($agent, 'childProcess'));
            $this->assertLastReceipt(null);
            $this->assertClear();
        } finally {
            $property->setValue(null, $original);
        }
    }

    /** The real spawn carries only the existing CLI arguments and adds no receipt environment. */
    public function testScheduledBackupChildKeepsItsExistingArgumentsAndEnvironment(): void
    {
        $agent = $this->backupAgent();
        $this->fireBackup(true);
        $child = self::property($agent, 'childProcess');
        $output = '';
        $deadline = microtime(true) + 2.0;
        do {
            $child->tick();
            $output .= $child->getStdOut();
            if (!$child->getStatus()[Process::STATUS_RUNNING]) {
                break;
            }
            usleep(1000);
        } while (microtime(true) < $deadline);
        self::assertFalse($child->getStatus()[Process::STATUS_RUNNING]);
        self::assertSame(0, $child->getExitCode());
        $snapshot = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([
            $this->directory . '/cli.php', BackupConstants::RUN_COMMAND,
            self::property($agent, 'currentBackupId'), '--scope=full',
        ], $snapshot['argv']);
        self::assertSame(array_values(array_filter(
            array_keys(getenv()), static fn (string $key): bool => str_contains(strtolower($key), 'receipt'),
        )), $snapshot['receiptEnv']);
        $id = self::property($agent, 'cronReceiptId');
        $this->tickBackup();
        self::assertNull($this->receipt($id));
    }

    /** @return array<string, array{string, int, int, bool}> Cron and numeric axes, including coincidence */
    public static function rotationAxes(): array
    {
        $later = '0 ' . (((int)date('G') + 12) % 24) . ' * * *';
        return [
            'cron' => [self::EXPRESSION, 0, 0, true],
            'size' => ['', 0, 1, false],
            'age' => ['', 1, 0, false],
            'all axes' => [self::EXPRESSION, 1, 1, true],
            'size before cron is due' => [$later, 0, 1, false],
            'age before cron is due' => [$later, 1, 0, false],
        ];
    }

    /**
     * @param string $expression Configured cron axis
     * @param int $age Age threshold
     * @param int $size Size threshold
     * @param bool $cron Expected attribution
     */
    #[DataProvider('rotationAxes')]
    public function testRotationAttributesOnlyItsCronAxis(string $expression, int $age, int $size, bool $cron): void
    {
        $agent = $this->logStore($expression, $age, $size);
        file_put_contents($this->directory . '/daemon.log', "line\n");
        $now = microtime(true) + 10;
        $agent->walkStore((int)$now);
        $observed = [];
        $this->router->onSignal = function (SignalNameInterface $name) use (&$observed): void {
            if ($name->getName() === HilosSignalConstants::LOGS_NODE_INDEX_REPORT) {
                $observed[] = JournalReceiptScope::currentId();
                $this->assertConnection(JournalReceiptScope::currentId());
                $this->writeSetting();
            }
        };
        $agent->rotateIfDue($now);
        self::assertCount(1, $observed, 'Coincident axes still perform exactly one rotation');
        self::assertFileDoesNotExist($this->directory . '/daemon.log');
        self::assertSame($now, self::property($agent, 'lastRotationAt'));
        self::assertSame($now, self::property($agent, 'lastFullScanAt'));
        self::assertSame($now, self::property($agent, 'lastLiveScanAt'));
        if ($cron) {
            self::assertNotNull($observed[0]);
            $this->assertCron($observed[0], 'log-rotation', self::EXPRESSION, $agent->getId(), 1);
        } else {
            self::assertNull($observed[0]);
            self::assertSame(0, $this->newReceiptCount());
        }
        $this->assertLastReceipt($observed[0]);
        $this->consumeLogIndex();
        if ($cron) {
            $this->assertCron($observed[0], 'log-rotation', self::EXPRESSION, $agent->getId(), 0);
        }
        $agent->rotateIfDue($now);
        self::assertCount(1, $observed, 'The same minute and empty live store do not rotate twice');
        $this->assertClear();
    }

    /** A rotation and its real index handover write no journal rows unless a project actually writes. */
    public function testFileOnlyAndEmptyCronRotationLeaveNoVisibleReceipt(): void
    {
        $agent = $this->logStore(self::EXPRESSION);
        file_put_contents($this->directory . '/daemon.log', "line\n");
        $agent->walkStore(time());
        $agent->rotateIfDue(microtime(true));
        $frame = $this->consumeLogIndex();
        self::assertNotNull($frame->data->receiptId);
        $handed = $frame->data->receiptId;
        self::assertNull($this->receipt($handed));
        self::assertSame(0, $this->newReceiptCount());
        $this->assertReceiptUpdated($handed, 1, 0);
        $this->assertReceiptUpdated($handed, 0, 1);
        $this->assertReceiptStored(SignalConstants::DB_SYNC_DELETED, $handed);
        self::assertNull($this->router->getNextQueuedSignal());
        self::property($agent, 'cronRule')->lastRun = 0.0;
        $now = microtime(true) + 10;
        $agent->rotateIfDue($now);
        self::assertSame($now, self::property($agent, 'lastRotationAt'), 'An empty attempt still resets the age baseline');
        self::assertSame(0, $this->newReceiptCount());
        $emptyId = $this->assertReceiptStored(SignalConstants::DB_SYNC_CREATED, null);
        $this->assertReceiptStored(SignalConstants::DB_SYNC_DELETED, $emptyId);
        self::assertNull($this->router->getNextQueuedSignal());
        $this->assertClear();
    }

    /** A failed frame leaves its committed write attributed, and clears both SQL and the scope stack. */
    public function testRotationFailureRestoresAttributionBeforeTheNextWriter(): void
    {
        $agent = $this->logStore(self::EXPRESSION);
        file_put_contents($this->directory . '/daemon.log', "line\n");
        $agent->walkStore(time());
        $id = null;
        $failure = new RuntimeException('index delivery failed');
        $this->router->onSignal = function (SignalNameInterface $name) use (&$id, $failure): void {
            if ($name->getName() !== HilosSignalConstants::LOGS_NODE_INDEX_REPORT) {
                return;
            }
            $id = JournalReceiptScope::currentId();
            $this->writeSetting();
            throw $failure;
        };
        try {
            $agent->rotateIfDue(microtime(true));
            self::fail('The injected delivery failure must leave the handler');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertNotNull($id);
        $this->assertCron($id, 'log-rotation', self::EXPRESSION, $agent->getId(), 0);
        $this->assertClear();
        $this->writeSetting();
        $this->assertLastReceipt(null);
    }

    /** The deliberately blocked staging path warns before the rotator converts it to its domain error. */
    #[WithoutErrorHandler]
    public function testFileRotationErrorClosesItsEmptyCronReceiptAndResetsTheAgeBaseline(): void
    {
        $agent = $this->logStore(self::EXPRESSION);
        file_put_contents($this->directory . '/daemon.log', "line\n");
        file_put_contents($this->directory . '/staging', 'not a directory');
        $agent->walkStore(time());
        $now = microtime(true) + 10;
        $agent->rotateIfDue($now);
        self::assertFileExists($this->directory . '/daemon.log');
        self::assertSame($now, self::property($agent, 'lastRotationAt'));
        self::assertSame(0, $this->newReceiptCount());
        $this->assertClear();
    }

    /** @return BackupAgent Real agent registered and started by the real worker dispatch */
    private function backupAgent(): BackupAgent
    {
        Hilos::initBrowser();
        $this->worker = new JournalReceiptMaintenanceWorker();
        Hilos::$sr = $this->router;
        $this->worker->handleDaemonMessage(new AgentStartDTO(BackupAgent::AGENT_TYPE));
        $this->backup = $this->worker->backup();
        self::setProperty($this->backup, 'agentSchedule', []);
        self::setProperty($this->backup, 'schedule', BackupSchedule::fromArray([[
            BackupConstants::SCHEDULE_NAME => self::RULE,
            BackupConstants::SCHEDULE_CRON => self::EXPRESSION,
            BackupConstants::SCHEDULE_SCOPE => BackupScope::FULL->value,
            BackupConstants::SCHEDULE_MECHANISM => 'daemon',
        ]]));
        return $this->backup;
    }

    /** @param bool $daemon Whether to dispatch a real CRON frame or fire the agent's own rule */
    private function fireBackup(bool $daemon): void
    {
        if ($daemon) {
            $this->worker->handleDaemonMessage(new DaemonAgentMessageDTO(BackupAgent::AGENT_TYPE, new SignalDTO(
                new SignalSource(SignalSource::DAEMON), new SignalType(SignalTypeConstants::CRON),
                new SignalName(self::RULE), new CronSignalDTO(self::RULE, self::EXPRESSION),
            )));
        } else {
            $rule = new CronRule(self::RULE, self::EXPRESSION);
            $rule->lastRun = 0.0;
            self::setProperty($this->backup, 'agentSchedule', [new BackupCronJob($rule, BackupScope::FULL)]);
            $this->tickBackup();
            self::setProperty($this->backup, 'agentSchedule', []);
        }
    }

    /** Runs the actual tick under the production agent's ownership. */
    private function tickBackup(): void
    {
        $this->underAgent($this->backup, fn () => $this->backup->onTick());
    }

    /**
     * @param Closure $tick Work observed inside the supervisor's process poll
     * @param bool $running Child status after that poll
     * @param int $exitCode Child result when it has stopped
     * @return Process Controlled process after the actual admission spawned its child
     */
    private function replaceChild(Closure $tick, bool $running = false, int $exitCode = 0): Process
    {
        $previous = self::property($this->backup, 'childProcess');
        if ($previous !== null && $previous::class === Process::class) {
            $previous->halt();
        }
        $child = $this->createMock(Process::class);
        $child->method('tick')->willReturnCallback($tick);
        $child->method('getStdOut')->willReturn('');
        $child->method('getStdErr')->willReturn('');
        $child->method('getStatus')->willReturn([Process::STATUS_RUNNING => $running]);
        $child->method('getExitCode')->willReturn($exitCode);
        self::setProperty($this->backup, 'childProcess', $child);
        return $child;
    }

    /**
     * @param string $expression Cron policy
     * @param int $age Age threshold
     * @param int $size Size threshold
     * @return LogStoreAgent Started real owner with a deterministic due rule
     */
    private function logStore(string $expression, int $age = 0, int $size = 0): LogStoreAgent
    {
        $this->environment->values[EnvConstants::LOG_ROTATION_CRON->name] = $expression;
        $this->environment->values[EnvConstants::LOG_ROTATION_MAX_AGE_SECONDS->name] = $age;
        $this->environment->values[EnvConstants::LOG_ROTATION_MAX_LIVE_SIZE_BYTES->name] = $size;
        $agent = new LogStoreAgent();
        $agent->onStart();
        while ($this->router->getNextQueuedSignal() !== null) {
        }
        $rule = self::property($agent, 'cronRule');
        if ($rule !== null) {
            $rule->lastRun = 0.0;
        }
        return $agent;
    }

    /** @return SignalDTO Index consumed by the real aggregator under the outgoing receipt */
    private function consumeLogIndex(): SignalDTO
    {
        $frame = $this->nextFrame(HilosSignalConstants::LOGS_NODE_INDEX_REPORT);
        $aggregator = new LogAggregatorAgent();
        $aggregator->onStart();
        $work = fn () => $aggregator->onSignalAgent($frame->data, LogStoreAgent::AGENT_TYPE, $frame->signalName->getName());
        if ($frame->data->receiptId === null) {
            $work();
        } else {
            JournalReceiptScope::join($frame->data->receiptId, $aggregator->getId(), $work);
        }
        return $frame;
    }

    /**
     * @param string $name Expected queued signal
     * @return SignalDTO Actual outgoing frame
     */
    private function nextFrame(string $name): SignalDTO
    {
        while (($frame = $this->router->getNextQueuedSignal()) !== null) {
            if ($frame->signalName->getName() === $name) {
                return $frame;
            }
        }
        self::fail("Missing frame {$name}");
    }

    /** Writes only an existing declared journaled table, without changing product policy. */
    private function writeSetting(): void
    {
        Database::sqlRun('INSERT INTO `hilos_setting` (`key`, `type`, `value`) VALUES (?, ?, ?)',
            [self::KEY_PREFIX . ++$this->writeCount, 'string', 'written'], tryReconnect: false);
    }

    /** Asserts that another handler cannot inherit a maintenance receipt. */
    private function assertClear(): void
    {
        self::assertNull(JournalReceiptScope::currentId());
        self::assertNull(JournalReceiptScope::stampHandover());
        $this->assertConnection(null);
    }

    /** @param ?int $id Expected receipt on the primary SQL connection */
    private function assertConnection(?int $id): void
    {
        Database::sql('SELECT @hilos_receipt AS `receipt`');
        $actual = Database::field('receipt');
        self::assertSame($id, $actual === null ? null : (int)$actual);
    }

    /** @param ?int $id Expected receipt of the newest real journal row */
    private function assertLastReceipt(?int $id): void
    {
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        Database::sql("SELECT `receipt_id` FROM {$database}.`hilos_change_log` ORDER BY `id` DESC LIMIT 1");
        $actual = Database::field('receipt_id');
        self::assertSame($id, $actual === null ? null : (int)$actual);
    }

    /**
     * @param string $name Created or deleted receipt frame
     * @param ?int $id Receipt row, or null when the create frame is the one that names it
     * @return int Receipt row the frame named
     */
    private function assertReceiptStored(string $name, ?int $id): int
    {
        $frame = $this->router->getNextQueuedSignal();
        self::assertInstanceOf(SignalDTO::class, $frame);
        self::assertSame($name, $frame->signalType->getType());
        self::assertSame($name, $frame->signalName->getName());
        self::assertInstanceOf(match ($name) {
            SignalConstants::DB_SYNC_CREATED => DbSyncCreatedSignalData::class,
            SignalConstants::DB_SYNC_DELETED => DbSyncDeletedSignalData::class,
        }, $frame->data);
        self::assertSame(HilosDbContext::changeLogReceipts, $frame->data->collectionKey);
        self::assertNull($frame->data->receiptId);
        self::assertNull($frame->data->writerAgentId);
        $rowId = (int) $frame->data->idString;
        if ($id !== null) {
            self::assertSame($id, $rowId);
        }
        self::assertSame($rowId, $frame->data->row[EntityChangeLogReceipt::id]);
        self::assertSame('cron', $frame->data->row[EntityChangeLogReceipt::channel]);
        self::assertSame('log-rotation', $frame->data->row[EntityChangeLogReceipt::action]);
        self::assertSame(0, $frame->data->row[EntityChangeLogReceipt::open_handovers]);

        return $rowId;
    }

    /**
     * @param int $id Receipt row
     * @param int $holds Counter after the bookkeeping update
     * @param int $before Counter before that update
     */
    private function assertReceiptUpdated(int $id, int $holds, int $before): void
    {
        $frame = $this->router->getNextQueuedSignal();
        self::assertInstanceOf(SignalDTO::class, $frame);
        self::assertSame(SignalConstants::DB_SYNC_UPDATED, $frame->signalType->getType());
        self::assertSame(SignalConstants::DB_SYNC_UPDATED, $frame->signalName->getName());
        self::assertInstanceOf(DbSyncUpdatedSignalData::class, $frame->data);
        self::assertSame(HilosDbContext::changeLogReceipts, $frame->data->collectionKey);
        self::assertSame((string) $id, $frame->data->idString);
        self::assertNull($frame->data->receiptId);
        self::assertNull($frame->data->writerAgentId);
        self::assertSame([EntityChangeLogReceipt::open_handovers => $holds], $frame->data->row);
        self::assertSame([EntityChangeLogReceipt::open_handovers => $before], $frame->data->previous);
    }

    /**
     * @param int $id Receipt number
     * @return ?array<string, mixed> Persisted attribution and counter
     */
    private function receipt(int $id): ?array
    {
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        Database::sql("SELECT * FROM {$database}.`hilos_change_log_receipt` WHERE `id` = ?", [$id]);
        return Database::row();
    }

    /**
     * @param int $id Receipt number
     * @param string $rule Fired rule
     * @param string $expression Schedule captured at admission
     * @param string $agent Actual writer or empty attempt's initiator
     * @param int $holds Local and agent holds still outstanding
     */
    private function assertCron(int $id, string $rule, string $expression, string $agent, int $holds): void
    {
        $row = $this->receipt($id);
        self::assertNotNull($row);
        self::assertSame('cron', $row['channel']);
        self::assertSame($rule, $row['action']);
        self::assertSame($rule . ' (' . $expression . ')', $row['source']);
        self::assertSame($agent, $row['agent']);
        self::assertSame($holds, (int)$row['open_handovers']);
        self::assertNull($row['actor_user_id']);
        self::assertNull($row['subject_user_id']);
        self::assertNull($row['session_id']);
    }

    /**
     * @param int $id Receipt number
     * @return int Real journal writes under this receipt
     */
    private function logCount(int $id): int
    {
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        Database::sql("SELECT COUNT(*) AS `count` FROM {$database}.`hilos_change_log` WHERE `receipt_id` = ?", [$id]);
        return (int)Database::field('count');
    }

    /** @return int Receipts still stored from this case */
    private function newReceiptCount(): int
    {
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        Database::sql("SELECT COUNT(*) AS `count` FROM {$database}.`hilos_change_log_receipt` WHERE `id` > ?",
            [$this->baseline['hilos_change_log_receipt']]);
        return (int)Database::field('count');
    }

    /**
     * @param object $object Fixture owner
     * @param string $name Existing private field
     * @return mixed Current field value
     */
    private static function property(object $object, string $name): mixed
    {
        return new ReflectionProperty($object, $name)->getValue($object);
    }

    /**
     * @param object $object Fixture owner
     * @param string $name Existing private seam
     * @param mixed $value Controlled fixture state
     */
    private static function setProperty(object $object, string $name, mixed $value): void
    {
        new ReflectionProperty($object, $name)->setValue($object, $value);
    }

    /** @return list<array<string, mixed>> Triggers restored after this case */
    private static function triggerCatalog(): array
    {
        Database::sql('SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, EVENT_MANIPULATION, ACTION_TIMING, ACTION_STATEMENT'
            . ' FROM INFORMATION_SCHEMA.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME');
        return Database::rows();
    }

    /** @param string $path Temporary fixture path */
    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            unlink($path);
            return;
        }
        foreach (scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}

/** Production worker with the backup owner and a local daemon link. */
final class JournalReceiptMaintenanceWorker extends WorkerManager
{
    /** Starts the worker machinery without opening a daemon socket. */
    public function __construct()
    {
        parent::__construct(1);
        $this->daemonClient = new JournalReceiptMaintenanceClient();
    }

    /** @return BackupAgent Production backup supervisor */
    public function backup(): BackupAgent
    {
        return $this->agentManager->getAgent(BackupAgent::AGENT_TYPE);
    }

    /** @return AgentManager Manager creating the production backup supervisor */
    protected function createAgentManager(): AgentManager
    {
        return new JournalReceiptMaintenanceAgentManager();
    }

    /** @return SignalRouter Normal project routes */
    protected function createSignalRouter(): SignalRouter
    {
        return new ChatSignalRouter();
    }

    /**
     * @param AgentInterface $agent Backup owner, with no cron page routes
     * @return PageSignalRouter Production dispatcher over the empty page route set
     */
    protected function createPageSignalRouter(AgentInterface $agent): PageSignalRouter
    {
        return new PageSignalRouter(new HilosPageFactory($agent, Hilos::class), new ActionRouteConfig(), new SignalRouteConfig());
    }
}

final class JournalReceiptMaintenanceAgentManager extends AgentManager
{
    /**
     * @param string $agentType Agent addressed by the test
     * @param ?string $agentIndex Unused singleton index
     * @return AgentInterface Real backup owner
     */
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        return new BackupAgent();
    }
}

final class JournalReceiptMaintenanceClient extends WorkerDaemonClient
{
    /** @param WorkerDTO|array $data Outgoing IPC captured by the local harness */
    public function send(WorkerDTO|array $data): void
    {
    }

    /** @return bool Local harness link availability */
    public function isConnected(): bool
    {
        return true;
    }
}

/** Observes frames before the production router stamps and queues them. */
final class JournalReceiptMaintenanceRouter extends SignalRouter
{
    public ?Closure $onSignal = null;

    /**
     * @param SignalSourceInterface $signalSource Sender
     * @param SignalTypeInterface $signalType Signal channel
     * @param SignalNameInterface $signalName Wire name
     * @param SignalDataInterface $signalData Unmodified production payload
     */
    public function queueSignal(
        SignalSourceInterface $signalSource,
        SignalTypeInterface $signalType,
        SignalNameInterface $signalName,
        SignalDataInterface $signalData,
    ): void {
        ($this->onSignal ?? static fn () => null)($signalName, $signalType, $signalData);
        parent::queueSignal($signalSource, $signalType, $signalName, $signalData);
    }
}

/** Per-case values without mutating the environment inherited by backup children. */
final class JournalReceiptMaintenanceEnv extends EnvAccessor
{
    /** @var array<string, mixed> Explicit environment overrides */
    public array $values = [];
    public ?Closure $onRead = null;

    /**
     * @param string $key Environment key
     * @return mixed Fixture override or the normal catalog value
     */
    public function effectiveValueFor(string $key): mixed
    {
        ($this->onRead ?? static fn () => null)($key);
        return $this->values[$key] ?? parent::effectiveValueFor($key);
    }
}
