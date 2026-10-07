<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Database\Database;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\AbstractPageFactory;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\Exception\PageNotFoundException;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\ChangeLog\JournalReceiptData;
use Hilos\Database\ChangeLog\JournalReceiptScope;
use Hilos\Database\ChangeLog\JournalTriggerFiles;
use Hilos\Database\ChangeLog\JournalTriggerInstaller;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Migration;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\RandomHelper;
use mysqli;
use RuntimeException;

/** Real journal triggers and migration files under the receipt scope. */
final class JournalReceiptIntegrationTest extends IntegrationTestCase
{
    private const string KEY_PREFIX = 'journal.receipt.hil1449.';

    private const array JOURNAL_CLEANUP_ORDER = [
        'hilos_change_log_value', 'hilos_change_log_change', 'hilos_change_log',
        'hilos_change_log_receipt', 'hilos_change_log_field', 'hilos_change_log_table',
    ];

    /** @var array<string, int> Journal high water marks before the case */
    private array $baseline = [];

    /** @var list<array<string, mixed>> Trigger definitions present before this case */
    private array $originalTriggers = [];

    private ?string $migrationRoot = null;

    /** @var list<int> Test people, removed after their sessions */
    private array $userIds = [];

    /** @var list<int> Test sessions */
    private array $sessionIds = [];

    private ?BrowserContext $previousBrowser = null;

    private ?SignalRouter $previousRouter = null;

    protected function setUp(): void
    {
        parent::setUp();
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

    protected function tearDown(): void
    {
        Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Database::setJournalReceiptId(null);
        if ($this->sessionIds !== []) {
            Hilos::$rt->connections->actions->clear();
            if ($this->previousBrowser === null) {
                Hilos::resetBrowser();
            } else {
                Hilos::initBrowser($this->previousBrowser);
            }
            Hilos::$sr = $this->previousRouter;
            foreach ($this->sessionIds as $id) {
                Hilos::$db->sessions[$id]?->actions->delete();
            }
            foreach ($this->userIds as $id) {
                Hilos::$db->users[$id]?->actions->delete();
            }
        }
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
        if ($this->migrationRoot !== null) {
            foreach (glob($this->migrationRoot . '/Schema/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->migrationRoot . '/Schema');
            rmdir($this->migrationRoot);
            Migration::setMigrationListPath(dirname(__DIR__, 2) . '/backend/Database/Migration');
            Migration::setMigrationName('Schema');
        }
        parent::tearDown();
    }

    public function testWebReceiptCarriesBothPeopleAndDoesNotLeakToTheNextAction(): void
    {
        $firstId = JournalReceiptScope::run(
            JournalReceiptData::web(11, 22, 31, 'setting.first', 'test-agent'),
            function (): ?int {
                Database::sqlRun('INSERT INTO `hilos_setting` (`key`, `type`, `value`) VALUES (?, ?, ?)',
                    [self::KEY_PREFIX . 'first', 'string', 'first']);
                return JournalReceiptScope::currentId();
            },
        );
        $this->assertNotNull($firstId);
        $this->assertNull(JournalReceiptScope::currentId());

        $secondId = JournalReceiptScope::run(
            JournalReceiptData::web(44, null, 32, 'setting.second', 'test-agent'),
            function (): ?int {
                Database::sqlRun('INSERT INTO `hilos_setting` (`key`, `type`, `value`) VALUES (?, ?, ?)',
                    [self::KEY_PREFIX . 'second', 'string', 'second']);
                return JournalReceiptScope::currentId();
            },
        );
        $this->assertNotNull($secondId);
        $this->assertNotSame($firstId, $secondId);
        $this->assertSame(['11', '22', '31', 'web', 'setting.first', 'test-agent', 'session #31'],
            $this->receiptFields($firstId));
        $this->assertSame(['44', null, '32', 'web', 'setting.second', 'test-agent', 'session #32'],
            $this->receiptFields($secondId));
        $this->assertSame(1, $this->logCount($firstId));
        $this->assertSame(1, $this->logCount($secondId));
        Database::sql('SELECT @hilos_receipt AS `id`');
        $this->assertNull(Database::field('id'));
    }

    public function testRouterAttributesImpersonationAndLeavesRefusalsAndNoOpsEmpty(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), 'test-agent');
        $this->previousBrowser = Hilos::$browser;
        $this->previousRouter = Hilos::$sr;
        $administratorId = $this->newUser();
        $subjectId = $this->newUser();
        $otherId = $this->newUser();
        $firstSession = $this->newSession('receipt-ak-one', $subjectId, $administratorId);
        $secondSession = $this->newSession('receipt-ak-two', $otherId);
        Hilos::initBrowser();
        Hilos::initSignalRouter(new ChatSignalRouter());

        $router = new PageSignalRouter(
            new JournalReceiptTestPageFactory(new JournalReceiptTestAgent()),
            new ActionRouteConfig([
                JournalReceiptTestPage::WRITE_ACTION => JournalReceiptTestPage::PAGE,
                JournalReceiptTestPage::NOOP_ACTION => JournalReceiptTestPage::PAGE,
                JournalReceiptTestPage::FAIL_ACTION => JournalReceiptTestPage::PAGE,
            ]),
        );
        $previousAgent = ExecutionContext::currentAgentId();
        ExecutionContext::setCurrentAgentId('receipt-agent');
        try {
            foreach (['receipt-ak-one', 'receipt-ak-two'] as $acceptKey) {
                $router->dispatchAction(new WebSocketActionSignalDTO(
                    $acceptKey, JournalReceiptTestPage::WRITE_ACTION, [], 'request-' . $acceptKey,
                ), 'websocket');
            }
            $router->dispatchAction(new WebSocketActionSignalDTO(
                'receipt-ak-one', JournalReceiptTestPage::NOOP_ACTION, [], 'request-noop',
            ), 'websocket');
            $router->dispatchAction(new WebSocketActionSignalDTO(
                'receipt-ak-one', JournalReceiptTestPage::FAIL_ACTION, [], 'request-fail',
            ), 'websocket');
            $router->dispatchAction(new WebSocketActionSignalDTO(
                'receipt-ak-guest', JournalReceiptTestPage::WRITE_ACTION, [], 'request-denied',
            ), 'websocket');
        } finally {
            ExecutionContext::setCurrentAgentId($previousAgent);
        }

        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        Database::sql("SELECT `id` FROM {$database}.`hilos_change_log_receipt` WHERE `id` > ? ORDER BY `id`",
            [$this->baseline['hilos_change_log_receipt']]);
        $rows = Database::rows();
        $this->assertCount(2, $rows);
        $this->assertSame([
            (string) $administratorId, (string) $subjectId, (string) $firstSession,
            'web', JournalReceiptTestPage::WRITE_ACTION, 'receipt-agent', 'session #' . $firstSession,
        ], $this->receiptFields((int) $rows[0]['id']));
        $this->assertSame([
            (string) $otherId, null, (string) $secondSession,
            'web', JournalReceiptTestPage::WRITE_ACTION, 'receipt-agent', 'session #' . $secondSession,
        ], $this->receiptFields((int) $rows[1]['id']));
        $this->assertSame(1, $this->logCount((int) $rows[0]['id']));
        $this->assertSame(1, $this->logCount((int) $rows[1]['id']));
    }

    public function testNoOpAndErrorLeaveOnlyReceiptsWithJournalRows(): void
    {
        Database::sql('INSERT INTO `hilos_setting` (`key`, `type`, `value`) VALUES (?, ?, ?)',
            [self::KEY_PREFIX . 'noop', 'string', 'unchanged']);
        $emptyId = JournalReceiptScope::run(
            JournalReceiptData::web(11, null, 31, 'setting.noop', null),
            static function (): ?int {
                Database::sql('UPDATE `hilos_setting` SET `value` = `value` WHERE `key` = ?',
                    [self::KEY_PREFIX . 'noop']);
                return JournalReceiptScope::currentId();
            },
        );
        $this->assertNotNull($emptyId);
        $this->assertNull($this->receiptFields($emptyId));

        $writtenId = null;
        try {
            JournalReceiptScope::run(
                JournalReceiptData::web(11, null, 31, 'setting.failed', null),
                static function () use (&$writtenId): void {
                    $writtenId = JournalReceiptScope::currentId();
                    Database::sqlRun('INSERT INTO `hilos_setting` (`key`, `type`, `value`) VALUES (?, ?, ?)',
                        [self::KEY_PREFIX . 'failed', 'string', 'written']);
                    throw new RuntimeException('handler failed after its write');
                },
            );
            self::fail('The handler must fail');
        } catch (RuntimeException $failure) {
            $this->assertSame('handler failed after its write', $failure->getMessage());
        }
        $this->assertNotNull($writtenId);
        $this->assertSame(1, $this->logCount($writtenId));
        $this->assertNotNull($this->receiptFields($writtenId));
        Database::sql('SELECT @hilos_receipt AS `id`');
        $this->assertNull(Database::field('id'));
    }

    public function testNestedScopeRestoresTheOuterReceipt(): void
    {
        $outerId = JournalReceiptScope::run(
            JournalReceiptData::web(61, null, 62, 'setting.outer', null),
            function (): ?int {
                $outerId = JournalReceiptScope::currentId();
                $innerId = JournalReceiptScope::run(
                    JournalReceiptData::web(63, null, 64, 'setting.inner', null),
                    static fn (): ?int => JournalReceiptScope::currentId(),
                );
                $this->assertNotSame($outerId, $innerId);
                $this->assertNull($this->receiptFields($innerId));
                $this->assertSame($outerId, JournalReceiptScope::currentId());
                Database::sql('INSERT INTO `hilos_setting` (`key`, `type`, `value`) VALUES (?, ?, ?)',
                    [self::KEY_PREFIX . 'outer', 'string', 'written']);
                return $outerId;
            },
        );
        $this->assertNotNull($outerId);
        $this->assertSame(1, $this->logCount($outerId));
    }

    public function testScopeRestoresTheActiveIndexWithoutAttributingTheJournalLink(): void
    {
        Database::useConnection(ChangeLogDatabase::CONNECTION_INDEX);
        try {
            Database::sql('SET @hilos_receipt = NULL');
            $id = JournalReceiptScope::run(
                JournalReceiptData::web(null, null, null, 'setting.secondary', null),
                function (): ?int {
                    $this->assertSame(ChangeLogDatabase::CONNECTION_INDEX, Database::getCurrentIndex());
                    Database::sql('SELECT @hilos_receipt AS `id`');
                    $this->assertNull(Database::field('id'));
                    return JournalReceiptScope::currentId();
                },
            );
            $this->assertSame(ChangeLogDatabase::CONNECTION_INDEX, Database::getCurrentIndex());
        } finally {
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        }
        $this->assertNotNull($id);
        $this->assertNull($this->receiptFields($id));
    }

    public function testLostPrimaryLinkRestoresReceiptBeforeTheNextWrite(): void
    {
        $receiptId = JournalReceiptScope::run(
            JournalReceiptData::web(51, null, 52, 'setting.reconnected', null),
            function (): ?int {
                $id = JournalReceiptScope::currentId();
                $this->killPrimaryConnection();

                Database::sql('INSERT INTO `hilos_setting` (`key`, `type`, `value`) VALUES (?, ?, ?)',
                    [self::KEY_PREFIX . 'reconnected', 'string', 'written']);
                return $id;
            },
        );
        $this->assertNotNull($receiptId);
        $this->assertSame(1, $this->logCount($receiptId));
    }

    public function testLostReceiptInsertIsNotReplayed(): void
    {
        $this->killPrimaryConnection();
        try {
            JournalReceiptScope::run(
                JournalReceiptData::web(53, null, 54, 'setting.lost-insert', null),
                static function (): void {
                    self::fail('The handler must not run after a lost receipt insert');
                },
            );
            self::fail('The lost receipt insert must fail');
        } catch (DatabaseException) {
            // The outcome of the INSERT is ambiguous; no automatic replay is allowed.
        }

        $nextId = JournalReceiptScope::run(
            JournalReceiptData::web(55, null, 56, 'setting.next', null),
            static fn (): ?int => JournalReceiptScope::currentId(),
        );
        $this->assertNotNull($nextId);
        $this->assertNull($this->receiptFields($nextId));
        Database::sql('SELECT @hilos_receipt AS `id`');
        $this->assertNull(Database::field('id'));
    }

    public function testMigrationUpDownAndRetryUseTheFileNameAndSeparateReceipts(): void
    {
        $current = Migration::getCurrentIndex();
        $index = $current + 1;
        $this->migrationRoot = sys_get_temp_dir() . '/hil-1449-migration-' . bin2hex(random_bytes(6));
        mkdir($this->migrationRoot . '/Schema', recursive: true);
        $upName = "{$index}_receipt_probe.sql";
        $downName = "{$index}_receipt_probe_down.sql";
        $upFile = $this->migrationRoot . '/Schema/' . $upName;
        $downFile = $this->migrationRoot . '/Schema/' . $downName;
        file_put_contents($upFile, "INSERT INTO `hilos_setting` (`key`, `type`, `value`) VALUES ('"
            . self::KEY_PREFIX . "migration', 'string', 'first');\n");
        file_put_contents($downFile, "DELETE FROM `hilos_setting` WHERE `key` = '"
            . self::KEY_PREFIX . "migration';\n");
        Migration::setMigrationListPath($this->migrationRoot);
        Migration::setMigrationName('Schema');

        $this->assertSame(1, Migration::migrateUp($index));
        $upReceipt = $this->newestReceiptId();
        $this->assertSame([null, null, null, 'migration', "migration.up.{$index}", null, $upName],
            $this->receiptFields($upReceipt));
        $this->assertSame(1, Migration::migrateDown($current));
        $downReceipt = $this->newestReceiptId();
        $this->assertNotSame($upReceipt, $downReceipt);
        $this->assertSame([null, null, null, 'migration', "migration.down.{$index}", null, $downName],
            $this->receiptFields($downReceipt));

        file_put_contents($upFile, 'SELECT * FROM `hil1449_missing`;');
        try {
            Migration::migrateUp($index);
            self::fail('The missing table must fail before any journaled write');
        } catch (DatabaseException) {
            $this->assertSame($downReceipt, $this->newestReceiptId());
        }

        file_put_contents($upFile, "INSERT INTO `hilos_setting` (`key`, `type`, `value`) VALUES ('"
            . self::KEY_PREFIX . "migration', 'string', 'partial');\nSELECT * FROM `hil1449_missing`;\n");
        try {
            Migration::retryFailed($index);
            self::fail('The second statement must fail');
        } catch (DatabaseException) {
            $partialReceipt = $this->newestReceiptId();
            $this->assertSame(1, $this->logCount($partialReceipt));
        }
        file_put_contents($upFile, "UPDATE `hilos_setting` SET `value` = 'retried' WHERE `key` = '"
            . self::KEY_PREFIX . "migration';\n");
        Migration::retryFailed($index);
        $retryReceipt = $this->newestReceiptId();
        $this->assertNotSame($partialReceipt, $retryReceipt);
        $this->assertSame(1, $this->logCount($retryReceipt));
        $this->assertSame(1, Migration::migrateDown($current));
    }

    public function testMigrationBeforeReceiptTableExistsRunsWithoutOne(): void
    {
        $current = Migration::getCurrentIndex();
        $index = $current + 1;
        $this->migrationRoot = sys_get_temp_dir() . '/hil-1449-early-' . bin2hex(random_bytes(6));
        mkdir($this->migrationRoot . '/Schema', recursive: true);
        file_put_contents($this->migrationRoot . "/Schema/{$index}_early.sql", 'SELECT 1;');
        file_put_contents($this->migrationRoot . "/Schema/{$index}_early_down.sql", 'SELECT 1;');
        Migration::setMigrationListPath($this->migrationRoot);
        Migration::setMigrationName('Schema');

        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        Database::sql("RENAME TABLE {$database}.`hilos_change_log_receipt` "
            . "TO {$database}.`hilos_change_log_receipt_hil1449_hold`");
        try {
            $this->assertSame(1, Migration::migrateUp($index));
            $this->assertSame(1, Migration::migrateDown($current));
        } finally {
            Database::sql("RENAME TABLE {$database}.`hilos_change_log_receipt_hil1449_hold` "
                . "TO {$database}.`hilos_change_log_receipt`");
        }

        $this->assertSame($this->baseline['hilos_change_log_receipt'], $this->newestReceiptId());
    }

    public function testMigrationCreatingReceiptTableDoesNotOpenItsOwnReceipt(): void
    {
        $current = Migration::getCurrentIndex();
        $index = $current + 1;
        $this->migrationRoot = sys_get_temp_dir() . '/hil-1449-boundary-' . bin2hex(random_bytes(6));
        mkdir($this->migrationRoot . '/Schema', recursive: true);
        file_put_contents($this->migrationRoot . "/Schema/{$index}_boundary.sql",
            'CREATE TABLE IF NOT EXISTS {{change_log_database}}.`hilos_change_log_receipt` (`id` INT);');
        file_put_contents($this->migrationRoot . "/Schema/{$index}_boundary_down.sql", 'SELECT 1;');
        Migration::setMigrationListPath($this->migrationRoot);
        Migration::setMigrationName('Schema');

        $this->assertSame(1, Migration::migrateUp($index));
        $this->assertSame($this->baseline['hilos_change_log_receipt'], $this->newestReceiptId());
        $this->assertSame(1, Migration::migrateDown($current));
    }

    /** @return ?list<?string> Scalar receipt fields in stored order, or null when it was deleted */
    private function receiptFields(int $id): ?array
    {
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        Database::sql("SELECT `actor_user_id`, `subject_user_id`, `session_id`, `channel`, `action`, `agent`, `source` "
            . "FROM {$database}.`hilos_change_log_receipt` WHERE `id` = ?", [$id]);
        $row = Database::row();
        return $row === null ? null : array_values($row);
    }

    private function logCount(int $id): int
    {
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        Database::sql("SELECT COUNT(*) AS `count` FROM {$database}.`hilos_change_log` WHERE `receipt_id` = ?", [$id]);
        return (int) Database::field('count');
    }

    private function newestReceiptId(): int
    {
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        Database::sql("SELECT MAX(`id`) AS `id` FROM {$database}.`hilos_change_log_receipt`");
        return (int) Database::field('id');
    }

    private function newUser(): int
    {
        $id = (int) Hilos::$db->users->actions->createWithName('Receipt fixture')->id;
        $this->userIds[] = $id;
        return $id;
    }

    private function newSession(string $acceptKey, int $userId, ?int $administratorId = null): int
    {
        $token = RandomHelper::hex(16);
        $session = Hilos::$db->sessions->actions->createAnonymous($token);
        $id = (int) $session->id;
        $this->sessionIds[] = $id;
        $session->actions->bindUser($userId);
        if ($administratorId !== null) {
            $session->actions->setImpersonator($administratorId);
        }
        Hilos::$rt->connections->actions->register($acceptKey, $userId, $token, $id);
        return $id;
    }

    private function killPrimaryConnection(): void
    {
        Database::sql('SELECT CONNECTION_ID() AS `id`');
        $connectionId = (int) Database::field('id');
        $killer = new mysqli(
            Hilos::$env[EnvConstants::DB_HOST]->string(),
            Hilos::$env[EnvConstants::DB_USERNAME]->string(),
            Hilos::$env[EnvConstants::DB_PASSWORD]->string(),
            Hilos::$env[EnvConstants::DB_DATABASE]->string(),
            Hilos::$env[EnvConstants::DB_PORT]->int(),
        );
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

/** @extends AbstractPageFactory<JournalReceiptTestAgent> */
final class JournalReceiptTestPageFactory extends AbstractPageFactory
{
    protected function createPage(string $pageName): AbstractPage
    {
        return $pageName === JournalReceiptTestPage::PAGE
            ? new JournalReceiptTestPage($this->agent)
            : throw new PageNotFoundException($pageName);
    }

    public function hasPage(string $pageName): bool
    {
        return $pageName === JournalReceiptTestPage::PAGE;
    }
}

final class JournalReceiptTestAgent implements PageAgentInterface
{
    public function getId(): string
    {
        return 'receipt-agent';
    }

    public function getAgentSignalSource(): SignalSourceInterface
    {
        return new SignalSource(SignalSource::AGENT, 'receipt-agent');
    }
}

final class JournalReceiptTestPage extends AbstractPage
{
    public const string PAGE = 'journal_receipt_test';
    public const string WRITE_ACTION = 'journal.receipt.write';
    public const string NOOP_ACTION = 'journal.receipt.noop';
    public const string FAIL_ACTION = 'journal.receipt.fail';

    public const array AUTH_ACTIONS = [self::WRITE_ACTION, self::NOOP_ACTION, self::FAIL_ACTION];

    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        if ($action === self::FAIL_ACTION) {
            throw new RuntimeException('receipt action failed');
        }
        if ($action === self::WRITE_ACTION) {
            Database::sql('INSERT INTO `hilos_setting` (`key`, `type`, `value`) VALUES (?, ?, ?)',
                ['journal.receipt.hil1449.' . $acceptKey, 'string', 'written']);
        }
        return null;
    }
}
