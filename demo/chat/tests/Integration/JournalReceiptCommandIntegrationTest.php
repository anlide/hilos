<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\Hilos\SessionsLibraryAgent;
use Demo\Chat\Agents\Hilos\UserAgent;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Database\Database;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Constants\CliCommands;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\AbstractPageFactory;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\Exception\PageNotFoundException;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\ChangeLog\JournalReceiptScope;
use Hilos\Database\ChangeLog\JournalTriggerFiles;
use Hilos\Database\ChangeLog\JournalTriggerInstaller;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\Migration;
use Hilos\Database\Settings\Library\SettingsLibraryAgent;
use Hilos\Database\Settings\SettingsCommandConstants;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\Worker\DTO\AgentStartDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\Socket\Worker\WorkerDaemonClient;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\AdminCommandConstants;
use Hilos\Utils\Helpers\RandomHelper;
use LogicException;

/**
 * Integration tests for change-log journal receipt attribution on CLI commands (HIL-1455).
 *
 * Checks that worker-dispatched CLI commands run under a cli channel receipt, attributing the
 * command name to both action and source, and recording the executing agent. Verifies that empty
 * commands leave no receipts, failures leave no orphaned receipts, and command handovers
 * (admin:grant) preserve the receipt through both library and instance owners.
 */
final class JournalReceiptCommandIntegrationTest extends IntegrationTestCase
{
    private const string KEY_PREFIX = 'journal.receipt.hil1455.';

    private const array JOURNAL_CLEANUP_ORDER = [
        'hilos_change_log_value', 'hilos_change_log_change', 'hilos_change_log',
        'hilos_change_log_receipt', 'hilos_change_log_field', 'hilos_change_log_table',
    ];

    /** @var array<string, int> Journal high water marks before the case */
    private array $baseline = [];

    /** @var list<array<string, mixed>> Trigger definitions present before the case */
    private array $originalTriggers = [];

    /** @var list<int> Test people, removed after the case */
    private array $userIds = [];

    /** @var list<string> Setting keys touched by tests, removed from hilos_setting after the case */
    private array $touchedSettingKeys = [];

    private ?BrowserContext $previousBrowser = null;

    private ?SignalRouter $previousRouter = null;

    private ?string $previousAgentId = null;

    protected function setUp(): void
    {
        parent::setUp();
        Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Migration::setMigrationListPath(dirname(__DIR__, 2) . '/backend/Database/Migration');
        Migration::setMigrationName('Schema');
        JournalTriggerFiles::setPath(dirname(__DIR__, 2) . '/backend/Database/Migration/Triggers');
        $this->previousBrowser = Hilos::$browser;
        $this->previousRouter = Hilos::$sr;
        $this->previousAgentId = ExecutionContext::currentAgentId();
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
        ExecutionContext::setCurrentAgentId($this->previousAgentId);
        if ($this->userIds !== []) {
            foreach ($this->userIds as $id) {
                Hilos::$db->users[$id]?->actions->delete();
            }
        }
        if ($this->previousBrowser === null) {
            Hilos::resetBrowser();
        } else {
            Hilos::initBrowser($this->previousBrowser);
        }
        Hilos::$sr = $this->previousRouter;
        if ($this->touchedSettingKeys !== []) {
            foreach ($this->touchedSettingKeys as $key) {
                Database::sqlRun('DELETE FROM `hilos_setting` WHERE `key` = ?', [$key]);
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
        parent::tearDown();
    }

    public function testCommandWithDatabaseWriteCreatesSingleCliReceipt(): void
    {
        $worker = new JournalReceiptCommandTestWorker();
        $worker->handleDaemonMessage(new AgentStartDTO(JournalReceiptCommandTestAgent::AGENT_TYPE));

        $marker = 'marker_val_' . RandomHelper::hex(8);
        $key = self::KEY_PREFIX . RandomHelper::hex(8);
        $correlationId = RandomHelper::hex(8);

        $frame = new SignalDTO(
            new SignalSource(SignalSource::DAEMON),
            new SignalType(SignalTypeConstants::COMMAND_REQUEST),
            new SignalName(JournalReceiptCommandTestAgent::COMMAND_WRITE),
            new CommandRequestDTO($correlationId, JournalReceiptCommandTestAgent::COMMAND_WRITE, [
                'key' => $key,
                'marker' => $marker,
            ]),
        );

        $worker->handleDaemonMessage(new DaemonAgentMessageDTO(JournalReceiptCommandTestAgent::AGENT_TYPE, $frame));

        $reply = $this->nextCommandReply();
        $this->assertTrue($reply->isOk());
        $this->assertSame($correlationId, $reply->correlationId);

        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        Database::sql("SELECT * FROM {$database}.`hilos_change_log_receipt` WHERE `id` > ? ORDER BY `id` ASC", [
            $this->baseline['hilos_change_log_receipt'],
        ]);
        $receipts = Database::rows();
        $this->assertCount(1, $receipts);

        $receipt = $receipts[0];
        $this->assertSame('cli', $receipt['channel']);
        $this->assertSame(JournalReceiptCommandTestAgent::COMMAND_WRITE, $receipt['action']);
        $this->assertSame(JournalReceiptCommandTestAgent::COMMAND_WRITE, $receipt['source']);
        $this->assertSame(JournalReceiptCommandTestAgent::AGENT_TYPE, $receipt['agent']);
        $this->assertNull($receipt['actor_user_id']);
        $this->assertNull($receipt['subject_user_id']);
        $this->assertNull($receipt['session_id']);
        $this->assertSame(0, (int) $receipt['open_handovers']);

        Database::sql("SELECT COUNT(*) AS `count` FROM {$database}.`hilos_change_log` WHERE `receipt_id` = ?", [
            (int) $receipt['id'],
        ]);
        $this->assertSame(1, (int) Database::field('count'));

        foreach ($receipt as $field => $val) {
            if (is_string($val)) {
                $this->assertStringNotContainsString(
                    $marker,
                    $val,
                    "Field {$field} of receipt must not contain payload marker value",
                );
            }
        }

        Database::sql('SELECT @hilos_receipt AS `id`');
        $this->assertNull(Database::field('id'));
        $this->assertNull(JournalReceiptScope::currentId());
    }

    public function testCommandWithoutWritesCreatesNoReceipt(): void
    {
        $worker = new JournalReceiptCommandTestWorker();
        $worker->handleDaemonMessage(new AgentStartDTO(JournalReceiptCommandTestAgent::AGENT_TYPE));

        $correlationId = RandomHelper::hex(8);
        $frame = new SignalDTO(
            new SignalSource(SignalSource::DAEMON),
            new SignalType(SignalTypeConstants::COMMAND_REQUEST),
            new SignalName(JournalReceiptCommandTestAgent::COMMAND_NOOP),
            new CommandRequestDTO($correlationId, JournalReceiptCommandTestAgent::COMMAND_NOOP),
        );

        $worker->handleDaemonMessage(new DaemonAgentMessageDTO(JournalReceiptCommandTestAgent::AGENT_TYPE, $frame));

        $reply = $this->nextCommandReply();
        $this->assertTrue($reply->isOk());
        $this->assertSame($correlationId, $reply->correlationId);

        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        Database::sql("SELECT COUNT(*) AS `count` FROM {$database}.`hilos_change_log_receipt` WHERE `id` > ?", [
            $this->baseline['hilos_change_log_receipt'],
        ]);
        $this->assertSame(0, (int) Database::field('count'));

        Database::sql('SELECT @hilos_receipt AS `id`');
        $this->assertNull(Database::field('id'));
        $this->assertNull(JournalReceiptScope::currentId());
    }

    public function testCommandHandlerFailureLeavesNoEmptyReceipt(): void
    {
        $worker = new JournalReceiptCommandTestWorker();
        $worker->handleDaemonMessage(new AgentStartDTO(JournalReceiptCommandTestAgent::AGENT_TYPE));

        $correlationId = RandomHelper::hex(8);
        $key = self::KEY_PREFIX . RandomHelper::hex(8);
        $frame = new SignalDTO(
            new SignalSource(SignalSource::DAEMON),
            new SignalType(SignalTypeConstants::COMMAND_REQUEST),
            new SignalName(JournalReceiptCommandTestAgent::COMMAND_FAIL),
            new CommandRequestDTO($correlationId, JournalReceiptCommandTestAgent::COMMAND_FAIL, [
                'key' => $key,
            ]),
        );

        $worker->handleDaemonMessage(new DaemonAgentMessageDTO(JournalReceiptCommandTestAgent::AGENT_TYPE, $frame));

        $reply = $this->nextCommandReply();
        $this->assertFalse($reply->isOk());
        $this->assertSame($correlationId, $reply->correlationId);
        $this->assertSame(
            JournalReceiptCommandTestAgent::COMMAND_FAIL . ' failed: Command handler simulated failure',
            $reply->payload['message'] ?? null,
        );

        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        Database::sql("SELECT COUNT(*) AS `count` FROM {$database}.`hilos_change_log_receipt` `r` "
            . "WHERE `r`.`id` > ? AND NOT EXISTS ("
            . "SELECT 1 FROM {$database}.`hilos_change_log` `l` WHERE `l`.`receipt_id` = `r`.`id`"
            . ")", [$this->baseline['hilos_change_log_receipt']]);
        $this->assertSame(0, (int) Database::field('count'), 'Receipt without journal rows must not remain');

        Database::sql('SELECT @hilos_receipt AS `id`');
        $this->assertNull(Database::field('id'));
        $this->assertNull(JournalReceiptScope::currentId());
    }

    public function testRealAdminGrantCommandPreservesReceiptAcrossSessionsLibraryAndUserAgent(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), 'test-agent');
        $userId = (int) Hilos::$db->users->actions->createWithName('Admin Grant Target')->id;
        $this->userIds[] = $userId;
        Hilos::initBrowser();

        $worker = new JournalReceiptCommandTestWorker();
        $worker->handleDaemonMessage(new AgentStartDTO(SessionsLibraryAgent::AGENT_TYPE));
        $worker->handleDaemonMessage(new AgentStartDTO(UserAgent::AGENT_TYPE . ':' . $userId));

        $correlationId = RandomHelper::hex(8);
        $frame = new SignalDTO(
            new SignalSource(SignalSource::DAEMON),
            new SignalType(SignalTypeConstants::COMMAND_REQUEST),
            new SignalName(CliCommands::ADMIN_GRANT),
            new CommandRequestDTO($correlationId, CliCommands::ADMIN_GRANT, [
                AdminCommandConstants::FIELD_USER_ID => $userId,
                AdminCommandConstants::FIELD_ADMIN => true,
            ]),
        );

        $worker->handleDaemonMessage(new DaemonAgentMessageDTO(SessionsLibraryAgent::AGENT_TYPE, $frame));

        $first = $this->nextAgentFrameNamed(HilosSignalConstants::HILOS_USER_ADMIN_COMMAND);
        $this->assertInstanceOf(AgentSignalData::class, $first->data);
        $receiptId = $first->data->receiptId;
        $this->assertNotNull($receiptId);
        $worker->handleDaemonMessage(new DaemonAgentMessageDTO(UserAgent::AGENT_TYPE . ':' . $userId, $first));

        $second = $this->nextAgentFrameNamed(HilosSignalConstants::HILOS_USER_ADMIN_COMMAND_DONE);
        $this->assertInstanceOf(AgentSignalData::class, $second->data);
        $this->assertSame($receiptId, $second->data->receiptId);
        $worker->handleDaemonMessage(new DaemonAgentMessageDTO(SessionsLibraryAgent::AGENT_TYPE, $second));

        $reply = $this->nextCommandReply();
        $this->assertTrue($reply->isOk());
        $this->assertSame($correlationId, $reply->correlationId);

        $this->assertTrue(Hilos::$db->users[$userId]?->admin);

        $receipt = $this->receipt($receiptId);
        $this->assertNotNull($receipt);
        $this->assertSame('cli', $receipt['channel']);
        $this->assertSame(CliCommands::ADMIN_GRANT, $receipt['action']);
        $this->assertSame(CliCommands::ADMIN_GRANT, $receipt['source']);
        $this->assertSame(UserAgent::AGENT_TYPE . ':' . $userId, $receipt['agent']);
        $this->assertNull($receipt['actor_user_id']);
        $this->assertNull($receipt['subject_user_id']);
        $this->assertNull($receipt['session_id']);
        $this->assertSame(0, (int) $receipt['open_handovers']);

        $this->assertGreaterThan(0, $this->logCount($receiptId));

        Database::sql('SELECT @hilos_receipt AS `id`');
        $this->assertNull(Database::field('id'));
        $this->assertNull(JournalReceiptScope::currentId());
    }

    public function testRealSettingsSetCommandAttributesReceiptAndUpdatesDatabase(): void
    {
        $this->touchedSettingKeys[] = 'example_integer';
        Database::sqlRun('DELETE FROM `hilos_setting` WHERE `key` = ?', ['example_integer']);

        $worker = new JournalReceiptCommandTestWorker();
        $worker->handleDaemonMessage(new AgentStartDTO(SettingsLibraryAgent::AGENT_TYPE));

        $correlationId = RandomHelper::hex(8);
        $frame = new SignalDTO(
            new SignalSource(SignalSource::DAEMON),
            new SignalType(SignalTypeConstants::COMMAND_REQUEST),
            new SignalName(CliCommands::SETTINGS_SET),
            new CommandRequestDTO($correlationId, CliCommands::SETTINGS_SET, [
                SettingsCommandConstants::FIELD_KEY => 'example_integer',
                SettingsCommandConstants::FIELD_VALUE => '30',
            ]),
        );

        $worker->handleDaemonMessage(new DaemonAgentMessageDTO(SettingsLibraryAgent::AGENT_TYPE, $frame));

        $done = $this->nextAgentFrameNamed(HilosSignalConstants::HILOS_SETTING_COMMAND_DONE);
        $this->assertInstanceOf(AgentSignalData::class, $done->data);
        $receiptId = $done->data->receiptId;
        $this->assertNotNull($receiptId);
        $worker->handleDaemonMessage(new DaemonAgentMessageDTO(SettingsLibraryAgent::AGENT_TYPE, $done));

        $reply = $this->nextCommandReply();
        $this->assertTrue($reply->isOk());
        $this->assertSame($correlationId, $reply->correlationId);

        Database::sql('SELECT `value` FROM `hilos_setting` WHERE `key` = ?', ['example_integer']);
        $this->assertSame('30', Database::field('value'));
        $this->assertSame(30, Hilos::$setting['example_integer']->int());

        $receipt = $this->receipt($receiptId);
        $this->assertNotNull($receipt);
        $this->assertSame('cli', $receipt['channel']);
        $this->assertSame(CliCommands::SETTINGS_SET, $receipt['action']);
        $this->assertSame('settings:set example_integer', $receipt['source']);
        $this->assertSame(SettingsLibraryAgent::AGENT_TYPE, $receipt['agent']);
        $this->assertNull($receipt['actor_user_id']);
        $this->assertNull($receipt['subject_user_id']);
        $this->assertNull($receipt['session_id']);
        $this->assertSame(0, (int) $receipt['open_handovers']);

        $this->assertGreaterThan(0, $this->logCount($receiptId));

        $this->assertStringNotContainsString('30', $receipt['source']);
        $this->assertStringNotContainsString('30', $receipt['action']);
        $this->assertStringNotContainsString('30', $receipt['channel']);
        $this->assertStringNotContainsString('30', $receipt['agent']);

        Database::sql('SELECT @hilos_receipt AS `id`');
        $this->assertNull(Database::field('id'));
        $this->assertNull(JournalReceiptScope::currentId());
    }

    public function testRealSettingsCommandRefusalsLeaveNoReceiptAndNoWrites(): void
    {
        $this->touchedSettingKeys[] = 'example_integer';
        $orphanKey = 'orphan_' . RandomHelper::hex(8);
        $this->touchedSettingKeys[] = $orphanKey;
        Database::sqlRun('DELETE FROM `hilos_setting` WHERE `key` = ?', ['example_integer']);

        $worker = new JournalReceiptCommandTestWorker();
        $worker->handleDaemonMessage(new AgentStartDTO(SettingsLibraryAgent::AGENT_TYPE));

        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());

        // (1) Typed validation failure: settings:set example_integer abc
        Database::sql("SELECT COALESCE(MAX(`id`), 0) AS `id` FROM {$database}.`hilos_change_log_receipt`");
        $baselineReceipt = (int) Database::field('id');

        $correlationId1 = RandomHelper::hex(8);
        $frame1 = new SignalDTO(
            new SignalSource(SignalSource::DAEMON),
            new SignalType(SignalTypeConstants::COMMAND_REQUEST),
            new SignalName(CliCommands::SETTINGS_SET),
            new CommandRequestDTO($correlationId1, CliCommands::SETTINGS_SET, [
                SettingsCommandConstants::FIELD_KEY => 'example_integer',
                SettingsCommandConstants::FIELD_VALUE => 'abc',
            ]),
        );
        $worker->handleDaemonMessage(new DaemonAgentMessageDTO(SettingsLibraryAgent::AGENT_TYPE, $frame1));

        $done1 = $this->nextAgentFrameNamed(HilosSignalConstants::HILOS_SETTING_COMMAND_DONE);
        $this->assertInstanceOf(AgentSignalData::class, $done1->data);
        $worker->handleDaemonMessage(new DaemonAgentMessageDTO(SettingsLibraryAgent::AGENT_TYPE, $done1));

        $reply1 = $this->nextCommandReply();
        $this->assertFalse($reply1->isOk());
        $this->assertSame($correlationId1, $reply1->correlationId);

        Database::sql('SELECT `value` FROM `hilos_setting` WHERE `key` = ?', ['example_integer']);
        $this->assertNull(Database::field('value'));
        $this->assertSame(0, Hilos::$setting['example_integer']->int());

        Database::sql("SELECT COUNT(*) AS `count` FROM {$database}.`hilos_change_log_receipt` WHERE `id` > ?", [
            $baselineReceipt,
        ]);
        $this->assertSame(0, (int) Database::field('count'), 'Receipt must not remain after typed refusal');

        // (2) Key outside catalog: settings:set nonexistent_key 123
        $correlationId2 = RandomHelper::hex(8);
        $nonexistentKey = 'nonexistent_' . RandomHelper::hex(8);
        $frame2 = new SignalDTO(
            new SignalSource(SignalSource::DAEMON),
            new SignalType(SignalTypeConstants::COMMAND_REQUEST),
            new SignalName(CliCommands::SETTINGS_SET),
            new CommandRequestDTO($correlationId2, CliCommands::SETTINGS_SET, [
                SettingsCommandConstants::FIELD_KEY => $nonexistentKey,
                SettingsCommandConstants::FIELD_VALUE => '123',
            ]),
        );
        $worker->handleDaemonMessage(new DaemonAgentMessageDTO(SettingsLibraryAgent::AGENT_TYPE, $frame2));

        $reply2 = $this->nextCommandReply();
        $this->assertFalse($reply2->isOk());
        $this->assertSame($correlationId2, $reply2->correlationId);
        $this->assertSame("Setting key '{$nonexistentKey}' is not in catalog", $reply2->payload['message'] ?? null);

        Database::sql('SELECT `value` FROM `hilos_setting` WHERE `key` = ?', [$nonexistentKey]);
        $this->assertNull(Database::field('value'));

        Database::sql("SELECT COUNT(*) AS `count` FROM {$database}.`hilos_change_log_receipt` WHERE `id` > ?", [
            $baselineReceipt,
        ]);
        $this->assertSame(0, (int) Database::field('count'), 'Receipt must not remain after unknown key refusal');

        // (3) Orphan key outside catalog: Hilos::$db->settings->actions->addOrphan
        Hilos::$db->settings->actions->addOrphan($orphanKey, 'string', 'initial_orphan_val', Hilos::$setting->catalog());

        Database::sql("SELECT COALESCE(MAX(`id`), 0) AS `id` FROM {$database}.`hilos_change_log_receipt`");
        $baselineAfterOrphan = (int) Database::field('id');

        $correlationId3 = RandomHelper::hex(8);
        $frame3 = new SignalDTO(
            new SignalSource(SignalSource::DAEMON),
            new SignalType(SignalTypeConstants::COMMAND_REQUEST),
            new SignalName(CliCommands::SETTINGS_SET),
            new CommandRequestDTO($correlationId3, CliCommands::SETTINGS_SET, [
                SettingsCommandConstants::FIELD_KEY => $orphanKey,
                SettingsCommandConstants::FIELD_VALUE => 'new_orphan_val',
            ]),
        );
        $worker->handleDaemonMessage(new DaemonAgentMessageDTO(SettingsLibraryAgent::AGENT_TYPE, $frame3));

        $reply3 = $this->nextCommandReply();
        $this->assertFalse($reply3->isOk());
        $this->assertSame($correlationId3, $reply3->correlationId);
        $this->assertSame("Setting key '{$orphanKey}' is not in catalog", $reply3->payload['message'] ?? null);

        Database::sql('SELECT `value` FROM `hilos_setting` WHERE `key` = ?', [$orphanKey]);
        $this->assertSame('initial_orphan_val', Database::field('value'));

        Database::sql("SELECT COUNT(*) AS `count` FROM {$database}.`hilos_change_log_receipt` WHERE `id` > ?", [
            $baselineAfterOrphan,
        ]);
        $this->assertSame(0, (int) Database::field('count'), 'Receipt must not remain after orphan key refusal');

        Database::sql('SELECT @hilos_receipt AS `id`');
        $this->assertNull(Database::field('id'));
        $this->assertNull(JournalReceiptScope::currentId());
    }

    public function testRealSettingsResetCommandRemovesRowAndAttributesReceipt(): void
    {
        $this->touchedSettingKeys[] = 'example_integer';
        Database::sqlRun('INSERT INTO `hilos_setting` (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = ?', [
            'example_integer',
            '42',
            '42',
        ]);
        $this->assertSame(42, Hilos::$setting['example_integer']->int());

        $worker = new JournalReceiptCommandTestWorker();
        $worker->handleDaemonMessage(new AgentStartDTO(SettingsLibraryAgent::AGENT_TYPE));

        $correlationId = RandomHelper::hex(8);
        $frame = new SignalDTO(
            new SignalSource(SignalSource::DAEMON),
            new SignalType(SignalTypeConstants::COMMAND_REQUEST),
            new SignalName(CliCommands::SETTINGS_RESET),
            new CommandRequestDTO($correlationId, CliCommands::SETTINGS_RESET, [
                SettingsCommandConstants::FIELD_KEY => 'example_integer',
            ]),
        );

        $worker->handleDaemonMessage(new DaemonAgentMessageDTO(SettingsLibraryAgent::AGENT_TYPE, $frame));

        $done = $this->nextAgentFrameNamed(HilosSignalConstants::HILOS_SETTING_COMMAND_DONE);
        $this->assertInstanceOf(AgentSignalData::class, $done->data);
        $receiptId = $done->data->receiptId;
        $this->assertNotNull($receiptId);
        $worker->handleDaemonMessage(new DaemonAgentMessageDTO(SettingsLibraryAgent::AGENT_TYPE, $done));

        $reply = $this->nextCommandReply();
        $this->assertTrue($reply->isOk());
        $this->assertSame($correlationId, $reply->correlationId);

        Database::sql('SELECT `value` FROM `hilos_setting` WHERE `key` = ?', ['example_integer']);
        $this->assertNull(Database::field('value'));
        $this->assertSame(0, Hilos::$setting['example_integer']->int());

        $receipt = $this->receipt($receiptId);
        $this->assertNotNull($receipt);
        $this->assertSame('cli', $receipt['channel']);
        $this->assertSame(CliCommands::SETTINGS_RESET, $receipt['action']);
        $this->assertSame('settings:reset example_integer', $receipt['source']);
        $this->assertSame(SettingsLibraryAgent::AGENT_TYPE, $receipt['agent']);
        $this->assertNull($receipt['actor_user_id']);
        $this->assertNull($receipt['subject_user_id']);
        $this->assertNull($receipt['session_id']);
        $this->assertSame(0, (int) $receipt['open_handovers']);

        $this->assertGreaterThan(0, $this->logCount($receiptId));

        Database::sql('SELECT @hilos_receipt AS `id`');
        $this->assertNull(Database::field('id'));
        $this->assertNull(JournalReceiptScope::currentId());
    }

    /** @return ?array<string, mixed> Receipt row, or null after its deletion */
    private function receipt(int $id): ?array
    {
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        Database::sql("SELECT * FROM {$database}.`hilos_change_log_receipt` WHERE `id` = ?", [$id]);

        return Database::row();
    }

    private function logCount(int $receiptId): int
    {
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        Database::sql("SELECT COUNT(*) AS `count` FROM {$database}.`hilos_change_log` WHERE `receipt_id` = ?", [$receiptId]);

        return (int) Database::field('count');
    }

    /** @return list<array<string, mixed>> Primary schema trigger definitions */
    private static function triggerCatalog(): array
    {
        Database::sql('SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, EVENT_MANIPULATION, ACTION_TIMING, ACTION_STATEMENT'
            . ' FROM INFORMATION_SCHEMA.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME');

        return Database::rows();
    }

    private function nextAgentFrameNamed(string $name): SignalDTO
    {
        while (($frame = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($frame->signalName->getName() === $name && $frame->data instanceof AgentSignalData) {
                return $frame;
            }
        }

        self::fail("The command handover owes agent frame {$name}");
    }

    private function nextCommandReply(): CommandReplyDTO
    {
        while (($frame = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($frame->data instanceof CommandReplyDTO) {
                return $frame->data;
            }
        }

        self::fail('The worker owes a command reply');
    }
}

/** A worker with a live agent and a daemon link that receives no external frames. */
final class JournalReceiptCommandTestWorker extends WorkerManager
{
    public function __construct()
    {
        parent::__construct(1);
        $this->daemonClient = new JournalReceiptCommandTestClient();
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new ChatSignalRouter();
    }

    protected function createAgentManager(): AgentManager
    {
        return new JournalReceiptCommandTestAgentManager();
    }

    protected function createPageSignalRouter(AgentInterface $agent): PageSignalRouter
    {
        return new PageSignalRouter(new JournalReceiptCommandEmptyPageFactory($agent), new ActionRouteConfig());
    }
}

final class JournalReceiptCommandTestClient extends WorkerDaemonClient
{
    public function send(WorkerDTO|array $data): void
    {
    }

    public function isConnected(): bool
    {
        return true;
    }
}

final class JournalReceiptCommandTestAgentManager extends AgentManager
{
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        return match ($agentType) {
            SessionsLibraryAgent::AGENT_TYPE => new SessionsLibraryAgent(),
            SettingsLibraryAgent::AGENT_TYPE => new SettingsLibraryAgent(),
            UserAgent::AGENT_TYPE => new UserAgent($agentIndex ?? throw new LogicException('Person agent needs an index')),
            default => new JournalReceiptCommandTestAgent(),
        };
    }
}

/** @extends AbstractPageFactory<AgentInterface> */
final class JournalReceiptCommandEmptyPageFactory extends AbstractPageFactory
{
    protected function createPage(string $pageName): AbstractPage
    {
        throw new PageNotFoundException($pageName);
    }

    public function hasPage(string $pageName): bool
    {
        return false;
    }
}

final class JournalReceiptCommandTestAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'journal_receipt_command_test_agent';
    public const string COMMAND_WRITE = 'test:setting-write';
    public const string COMMAND_NOOP = 'test:noop';
    public const string COMMAND_FAIL = 'test:fail';

    public const array AGENT_COMMANDS = [
        self::COMMAND_WRITE,
        self::COMMAND_NOOP,
        self::COMMAND_FAIL,
    ];

    public function onStop(): void
    {
    }

    public function onSignalCommand(CommandRequestDTO $data, string $source, string $name): void
    {
        if ($data->command === self::COMMAND_WRITE) {
            $key = (string) $data->payload['key'];
            $marker = (string) ($data->payload['marker'] ?? 'written');
            Database::sqlRun('INSERT INTO `hilos_setting` (`key`, `type`, `value`) VALUES (?, ?, ?)', [
                $key, 'string', $marker,
            ]);
            $this->replyToCommand(CommandReplyDTO::ok($data->correlationId));
        } elseif ($data->command === self::COMMAND_NOOP) {
            $this->replyToCommand(CommandReplyDTO::ok($data->correlationId));
        } elseif ($data->command === self::COMMAND_FAIL) {
            $key = (string) $data->payload['key'];
            Database::sqlRun('INSERT INTO `hilos_setting` (`key`, `type`, `value`) VALUES (?, ?, ?)', [
                $key, 'string', 'fail-value',
            ]);
            throw new AgentException('Command handler simulated failure');
        }
    }
}
