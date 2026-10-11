<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\BotAgent;
use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Core\Router\DTO\BotMessageSignalData;
use Demo\Chat\Database\Database;
use Demo\Chat\Database\Settings\SettingsCatalog;
use Demo\Chat\Hilos;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\ChangeLog\JournalReceiptData;
use Hilos\Database\ChangeLog\JournalReceiptScope;
use Hilos\Database\ChangeLog\JournalTriggerFiles;
use Hilos\Database\ChangeLog\JournalTriggerInstaller;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Migration;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\HilosException;
use Hilos\LLM\Contract\AsyncChatLLMInterface;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\RandomHelper;
use JsonException;
use Random\RandomException;
use ReflectionProperty;

/** Autonomous agent receipts attribute real journaled writes and end without leaking to later work. */
final class JournalAgentReceiptIntegrationTest extends IntegrationTestCase
{
    private const string KEY_PREFIX = 'journal.receipt.hil1450.';

    private const string EMPTY_ACTION = 'journal.receipt.agent.empty';

    private const string HUMAN_ACTION = 'journal.receipt.web.setting';

    private const array JOURNAL_CLEANUP_ORDER = [
        'hilos_change_log_value', 'hilos_change_log_change', 'hilos_change_log',
        'hilos_change_log_receipt', 'hilos_change_log_field', 'hilos_change_log_table',
    ];

    /** @var array<string, int> Journal high water marks before the case */
    private array $baseline = [];

    /** @var list<array<string, mixed>> Trigger definitions present before the case */
    private array $originalTriggers = [];

    private ?SignalRouter $previousRouter = null;

    private ?string $previousAgentId = null;

    private JournalAgentReceiptTestAgent $writer;

    private string $settingKey;

    private int $settingId;

    private ?int $botId = null;

    private ?ChatAgent $recipient = null;

    /**
     * @throws HilosException When the scoped setting write or journal query fails
     * @throws JsonException When the journal record key is not valid JSON
     */
    public function testAutonomousWriteCreatesOneAgentReceiptAndJournalRow(): void
    {
        $receiptId = $this->underAgent($this->writer, fn (): ?int => $this->writer->writeSetting($this->settingKey, 'autonomous'));

        $this->assertNotNull($receiptId);
        $this->assertAgentReceiptAttribution($receiptId);
        $this->assertSame(1, $this->newReceiptCount());
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        Database::sql("SELECT l.`receipt_id`, l.`record_key`, l.`mutation_type`, t.`name`"
            . " FROM {$database}.`hilos_change_log` l JOIN {$database}.`hilos_change_log_table` t ON t.`id` = l.`table_id`"
            . ' WHERE l.`receipt_id` = ?', [$receiptId]);
        $rows = Database::rows();
        $this->assertCount(1, $rows);
        $this->assertSame((string) $receiptId, $rows[0]['receipt_id']);
        $this->assertSame('hilos_setting', $rows[0]['name']);
        $this->assertSame('update', $rows[0]['mutation_type']);
        $this->assertSame([$this->settingId], json_decode($rows[0]['record_key'], true, flags: JSON_THROW_ON_ERROR));
        $this->assertReceiptContextCleared();
    }

    /** @throws HilosException When an empty scope, no-op setting write, or journal query fails */
    public function testEmptyAndNoopScopesDeleteTheirReceipts(): void
    {
        $emptyId = $this->underAgent($this->writer, fn (): ?int => JournalReceiptScope::run(
            JournalReceiptData::agent(self::EMPTY_ACTION, $this->writer->getId()),
            static fn (): ?int => JournalReceiptScope::currentId(),
        ));
        $this->assertNotNull($emptyId);
        $this->assertNull($this->receipt($emptyId));
        $this->assertSame(0, $this->logCount($emptyId));
        $this->assertReceiptContextCleared();

        $noopId = $this->underAgent($this->writer, fn (): ?int => $this->writer->writeSetting($this->settingKey, 'initial'));
        $this->assertNotNull($noopId);
        $this->assertNotSame($emptyId, $noopId);
        $this->assertNull($this->receipt($noopId));
        $this->assertSame(0, $this->logCount($noopId));
        $this->assertSame(0, $this->newReceiptCount());
        $this->assertReceiptContextCleared();
    }

    /** @throws HilosException When a settings write, receipt scope, or journal query fails */
    public function testAutonomousAndUnscopedWritesDoNotBorrowPreviousHumanAttribution(): void
    {
        $humanId = $this->underAgent($this->writer, fn (): ?int => JournalReceiptScope::run(
            JournalReceiptData::web(101, 202, 303, self::HUMAN_ACTION, 'receipt-page-agent'),
            function (): ?int {
                Hilos::$db->settings[$this->settingKey]->actions->updateValue('human');
                return JournalReceiptScope::currentId();
            },
        ));
        $this->assertNotNull($humanId);
        $humanReceipt = $this->receipt($humanId);
        $this->assertNotNull($humanReceipt);
        $this->assertSame('101', $humanReceipt['actor_user_id']);
        $this->assertSame('202', $humanReceipt['subject_user_id']);
        $this->assertSame('303', $humanReceipt['session_id']);
        $this->assertSame('web', $humanReceipt['channel']);
        $this->assertSame(self::HUMAN_ACTION, $humanReceipt['action']);
        $this->assertSame('receipt-page-agent', $humanReceipt['agent']);
        $this->assertSame('session #303', $humanReceipt['source']);
        $this->assertReceiptContextCleared();

        $agentId = $this->underAgent($this->writer, fn (): ?int => $this->writer->writeSetting($this->settingKey, 'autonomous'));
        $this->assertNotNull($agentId);
        $this->assertNotSame($humanId, $agentId);
        $this->assertAgentReceiptAttribution($agentId);
        $this->assertSame(1, $this->logCount($humanId));
        $this->assertSame(1, $this->logCount($agentId));
        $this->assertSame(2, $this->newReceiptCount());
        $this->assertReceiptContextCleared();

        $this->underAgent($this->writer, function (): void {
            Hilos::$db->settings[$this->settingKey]->actions->updateValue('outside-receipt');
        });
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        Database::sql("SELECT `receipt_id` FROM {$database}.`hilos_change_log` WHERE `id` > ? ORDER BY `id` DESC LIMIT 1",
            [$this->baseline['hilos_change_log']]);
        $unscopedRow = Database::row();
        $this->assertNotNull($unscopedRow);
        $this->assertNull($unscopedRow['receipt_id']);
        $this->assertSame($humanReceipt, $this->receipt($humanId));
        $this->assertAgentReceiptAttribution($agentId);
        $this->assertSame(2, $this->newReceiptCount());
        $this->assertReceiptContextCleared();
    }

    /** @throws HilosException When the bot handover, message write, or journal query fails */
    public function testCompletedBotResultHandsReceiptToChatAndDeletesItAfterMessageWrite(): void
    {
        $humanId = $this->underAgent($this->writer, fn (): ?int => JournalReceiptScope::run(
            JournalReceiptData::web(101, 202, 303, self::HUMAN_ACTION, 'receipt-page-agent'),
            function (): ?int {
                Hilos::$db->settings[$this->settingKey]->actions->updateValue('human');
                return JournalReceiptScope::currentId();
            },
        ));
        $this->assertNotNull($humanId);
        $humanReceipt = $this->receipt($humanId);
        $this->assertNotNull($humanReceipt);
        $this->assertReceiptContextCleared();

        $bot = $this->botWithCompletedResult("  An autonomous bot reply\n");
        $this->drainSignals();
        $before = microtime(true);
        $this->underAgent($bot, static fn () => $bot->onTick());
        $sentAt = (new ReflectionProperty($bot, 'lastMessageSentAt'))->getValue($bot);
        $this->assertGreaterThanOrEqual($before, $sentAt);
        $this->assertLessThanOrEqual(microtime(true), $sentAt);
        $this->assertFalse((new ReflectionProperty($bot, 'generationInFlight'))->getValue($bot));

        $frame = $this->nextBotMessage();
        $this->assertInstanceOf(AgentSignalData::class, $frame->data);
        $this->assertInstanceOf(BotMessageSignalData::class, $frame->data->data);
        $this->assertSame($this->botId, $frame->data->data->botId);
        $this->assertSame('An autonomous bot reply', $frame->data->data->message);
        $this->assertSame('bot_message', $frame->signalName->getName());
        $receiptId = $frame->data->receiptId;
        $this->assertNotNull($receiptId);
        $this->assertNotSame($humanId, $receiptId);
        $receipt = $this->receipt($receiptId);
        $this->assertNotNull($receipt);
        $this->assertNull($receipt['actor_user_id']);
        $this->assertNull($receipt['subject_user_id']);
        $this->assertNull($receipt['session_id']);
        $this->assertSame('agent', $receipt['channel']);
        $this->assertSame('bot_message', $receipt['action']);
        $this->assertSame('bot:' . $this->botId, $receipt['source']);
        $this->assertSame($bot->getId(), $receipt['agent']);
        $this->assertSame(1, (int) $receipt['open_handovers']);
        $this->assertSame(0, $this->logCount($receiptId));
        $this->assertSame(2, $this->newReceiptCount());
        $this->assertReceiptContextCleared();

        $this->recipient = new ChatAgent();
        OwnershipDeclaration::claimAll($this->recipient);
        $this->underAgent($this->recipient, fn () => JournalReceiptScope::join(
            $receiptId,
            $this->recipient->getId(),
            function () use ($frame, $receiptId): void {
                $this->assertSame($receiptId, JournalReceiptScope::currentId());
                Database::sql('SELECT @hilos_receipt AS `id`');
                $this->assertSame((string) $receiptId, Database::field('id'));
                $this->recipient->onSignalAgent(
                    $frame->data, SignalSource::describe($frame->signalSource), $frame->signalName->getName(),
                );
            },
        ));

        Database::sql('SELECT `event_id` FROM `event_message` WHERE `author_bot_id` = ?', [$this->botId]);
        $messages = Database::rows();
        $this->assertCount(1, $messages);
        $this->assertSame('An autonomous bot reply', Hilos::$db->eventMessages[(int) $messages[0]['event_id']]->message);
        $this->assertNull($this->receipt($receiptId));
        $this->assertSame(0, $this->logCount($receiptId));
        $this->assertSame(1, $this->newReceiptCount());
        $this->assertSame($humanReceipt, $this->receipt($humanId));
        $this->assertReceiptContextCleared();

        $this->underAgent($this->writer, function (): void {
            Hilos::$db->settings[$this->settingKey]->actions->updateValue('after-bot-handover');
        });
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        Database::sql("SELECT `receipt_id` FROM {$database}.`hilos_change_log` WHERE `id` > ? ORDER BY `id` DESC LIMIT 1",
            [$this->baseline['hilos_change_log']]);
        $this->assertNull(Database::field('receipt_id'));
        $this->assertSame($humanReceipt, $this->receipt($humanId));
        $this->assertReceiptContextCleared();
    }

    /** @throws HilosException When the fixture, tick, or receipt query fails */
    public function testEmptyBotResultAndIdleTickNeverOpenAReceipt(): void
    {
        $bot = $this->botWithCompletedResult(" \t\n ");
        (new ReflectionProperty($bot, 'lastMessageSentAt'))->setValue($bot, 123.0);
        $this->drainSignals();
        Database::sql('SELECT LAST_INSERT_ID(0)');

        $this->underAgent($bot, static fn () => $bot->onTick());
        $this->assertNoReceiptOpened();
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
        $this->assertSame(0, $this->newReceiptCount());
        $this->assertSame(123.0, (new ReflectionProperty($bot, 'lastMessageSentAt'))->getValue($bot));
        $this->assertFalse((new ReflectionProperty($bot, 'generationInFlight'))->getValue($bot));
        $this->assertReceiptContextCleared();

        $this->underAgent($bot, static fn () => $bot->onTick());
        $this->assertNoReceiptOpened();
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
        $this->assertSame(0, $this->newReceiptCount());
        $this->assertSame(123.0, (new ReflectionProperty($bot, 'lastMessageSentAt'))->getValue($bot));
        $this->assertReceiptContextCleared();
    }

    /**
     * The agent's claim and an isolated settings row live beside the installed service triggers.
     *
     * @throws HilosException When the database, triggers, or fixture writer cannot start
     * @throws RandomException When the fixture key cannot be generated
     */
    protected function setUp(): void
    {
        parent::setUp();
        Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Migration::setMigrationListPath(dirname(__DIR__, 2) . '/backend/Database/Migration');
        Migration::setMigrationName('Schema');
        JournalTriggerFiles::setPath(dirname(__DIR__, 2) . '/backend/Database/Migration/Triggers');
        $this->previousRouter = Hilos::$sr;
        $this->previousAgentId = ExecutionContext::currentAgentId();
        $this->originalTriggers = self::triggerCatalog();
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        foreach (self::JOURNAL_CLEANUP_ORDER as $table) {
            Database::sql("SELECT COALESCE(MAX(`id`), 0) AS `id` FROM {$database}.`{$table}`");
            $this->baseline[$table] = (int) Database::field('id');
        }
        JournalTriggerInstaller::apply();
        Hilos::initSignalRouter(new ChatSignalRouter());
        $this->writer = new JournalAgentReceiptTestAgent();
        $this->startAgent($this->writer);
        $this->settingKey = self::KEY_PREFIX . RandomHelper::hex(8);
        $this->settingId = $this->underAgent($this->writer, fn (): int => (int) Hilos::$db->settings->actions->addOrphan(
            $this->settingKey,
            SettingsCatalogConstants::TYPE_STRING,
            'initial',
            SettingsCatalog::getCatalog(),
        )->id);
    }

    /**
     * Restores the trigger catalog and globals, removes only this case's rows, and releases its writer.
     *
     * @throws HilosException When fixture or journal cleanup fails
     */
    protected function tearDown(): void
    {
        Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Database::setJournalReceiptId(null);
        if ($this->recipient !== null) {
            Database::sql('SELECT `event_id` FROM `event_message` WHERE `author_bot_id` = ?', [$this->botId]);
            $eventIds = array_map(static fn (array $row): int => (int) $row['event_id'], Database::rows());
            $this->underAgent($this->recipient, static fn () => Hilos::$db->events->actions->deleteByIds($eventIds));
            TruthSourceRegistry::unregisterAgent($this->recipient->getId());
            RtTruthSourceRegistry::unregisterAgent($this->recipient->getId());
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->recipient->getId()));
        }
        if ($this->botId !== null) {
            Hilos::$db->bots[$this->botId]?->actions->delete();
        }
        if (isset($this->settingKey)) {
            $this->underAgent($this->writer, function (): void {
                Hilos::$db->settings[$this->settingKey]?->actions->delete();
            });
        }
        if (isset($this->writer)) {
            $this->writer->onStop();
            TruthSourceRegistry::unregisterAgent($this->writer->getId());
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->writer->getId()));
        }
        ExecutionContext::setCurrentAgentId($this->previousAgentId);
        Hilos::$sr = $this->previousRouter;
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

    /**
     * Uses the existing client property seam to drive the real result boundary without scheduling a request.
     *
     * @param string $text Completed model output
     * @return BotAgent Bot with one result ready to consume
     * @throws HilosException When the fixture bot or its LLM profile cannot be created
     */
    private function botWithCompletedResult(string $text): BotAgent
    {
        $this->botId = (int) Hilos::$db->bots->actions->create('Receipt Bot')->id;
        $bot = new BotAgent((string) $this->botId);
        $client = $this->createMock(AsyncChatLLMInterface::class);
        $client->method('hasResult')->willReturnOnConsecutiveCalls(true, false);
        $client->method('isBusy')->willReturn(false);
        $client->expects(self::once())->method('consumeResult')->willReturn($text);
        $client->expects(self::never())->method('startGenerate');
        (new ReflectionProperty($bot, 'chatClient'))->setValue($bot, $client);
        (new ReflectionProperty($bot, 'generationInFlight'))->setValue($bot, true);

        return $bot;
    }

    /** @return SignalDTO The real bot result's outgoing handover frame */
    private function nextBotMessage(): SignalDTO
    {
        while (($frame = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($frame->signalName->getName() === ChatSignalConstants::BOT_MESSAGE) {
                return $frame;
            }
        }
        self::fail('The nonempty bot result owes a BOT_MESSAGE frame');
    }

    /**
     * @param int $id Receipt identifier
     * @return ?array<string, mixed> Stored receipt, or null after cleanup
     * @throws DatabaseException When the journal query fails
     */
    private function receipt(int $id): ?array
    {
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        Database::sql("SELECT * FROM {$database}.`hilos_change_log_receipt` WHERE `id` = ?", [$id]);
        return Database::row();
    }

    /** @throws DatabaseException When the primary session's last allocated identifier cannot be read */
    private function assertNoReceiptOpened(): void
    {
        Database::sql('SELECT LAST_INSERT_ID() AS `id`');
        $this->assertSame('0', Database::field('id'));
    }

    /**
     * @param int $id Receipt identifier
     * @throws DatabaseException When the receipt cannot be read
     */
    private function assertAgentReceiptAttribution(int $id): void
    {
        $receipt = $this->receipt($id);
        $this->assertNotNull($receipt);
        $this->assertNull($receipt['actor_user_id']);
        $this->assertNull($receipt['subject_user_id']);
        $this->assertNull($receipt['session_id']);
        $this->assertSame('agent', $receipt['channel']);
        $this->assertSame(JournalAgentReceiptTestAgent::WRITE_ACTION, $receipt['action']);
        $this->assertSame($this->writer->getId(), $receipt['source']);
        $this->assertSame($this->writer->getId(), $receipt['agent']);
        $this->assertSame(0, (int) $receipt['open_handovers']);
    }

    /** @throws DatabaseException When the primary connection attribution cannot be read */
    private function assertReceiptContextCleared(): void
    {
        Database::sql('SELECT @hilos_receipt AS `id`');
        $this->assertNull(Database::field('id'));
        $this->assertNull(JournalReceiptScope::currentId());
    }

    /**
     * @return int Receipts remaining above the case's high water mark
     * @throws DatabaseException When the receipt count cannot be read
     */
    private function newReceiptCount(): int
    {
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        Database::sql("SELECT COUNT(*) AS `count` FROM {$database}.`hilos_change_log_receipt` WHERE `id` > ?",
            [$this->baseline['hilos_change_log_receipt']]);
        return (int) Database::field('count');
    }

    /**
     * @param int $receiptId Receipt whose journal rows are counted
     * @return int Journal rows attributed to this receipt
     * @throws DatabaseException When the journal count cannot be read
     */
    private function logCount(int $receiptId): int
    {
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        Database::sql("SELECT COUNT(*) AS `count` FROM {$database}.`hilos_change_log` WHERE `receipt_id` = ?", [$receiptId]);
        return (int) Database::field('count');
    }

    /**
     * @return list<array<string, mixed>> Primary schema trigger definitions
     * @throws DatabaseException When the trigger catalog cannot be read
     */
    private static function triggerCatalog(): array
    {
        Database::sql('SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, EVENT_MANIPULATION, ACTION_TIMING, ACTION_STATEMENT'
            . ' FROM INFORMATION_SCHEMA.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME');
        return Database::rows();
    }
}

/** The declared owner used for autonomous settings writes. */
final class JournalAgentReceiptTestAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'journal_agent_receipt_test_agent';

    public const string WRITE_ACTION = 'journal.receipt.agent.setting';

    public const array OWNS_DB = [HilosDbContext::settings => TruthSourceOperation::ALL];

    /**
     * @param string $key Existing fixture setting key
     * @param string $value Value for the autonomous operation
     * @return ?int Receipt active during the write
     * @throws HilosException When the scoped setting write fails
     */
    public function writeSetting(string $key, string $value): ?int
    {
        return JournalReceiptScope::run(
            JournalReceiptData::agent(self::WRITE_ACTION, $this->getId()),
            static function () use ($key, $value): ?int {
                Hilos::$db->settings[$key]->actions->updateValue($value);
                return JournalReceiptScope::currentId();
            },
        );
    }

    /** The fixture keeps no work to finish on stop. */
    public function onStop(): void
    {
    }
}
