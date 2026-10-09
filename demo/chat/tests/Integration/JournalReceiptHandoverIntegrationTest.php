<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\Hilos\UserAgent;
use Demo\Chat\Agents\Hilos\UsersLibraryAgent;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Database\Database;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\Users\UserPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Constants\HilosSignalConstants;
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
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Table\TableAnchorDirection;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\ChangeLog\ChangeLogSectionReader;
use Hilos\Database\ChangeLog\JournalReceiptData;
use Hilos\Database\ChangeLog\JournalReceiptScope;
use Hilos\Database\ChangeLog\JournalTriggerFiles;
use Hilos\Database\ChangeLog\JournalTriggerInstaller;
use Hilos\Database\ChangeLog\Section\ChangeLogFeedFilter;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\Migration;
use Hilos\Database\Settings\Library\DTO\SettingWriteSignalData;
use Hilos\Database\Settings\Library\SettingsLibraryAgent;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Socket\Worker\DTO\AgentStartDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\Socket\Worker\WorkerDaemonClient;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\RandomHelper;
use Hilos\Users\DTO\HilosUserUpdateActionDTO;
use LogicException;

/** The page, router, and worker share a receipt across an asynchronous agent frame. */
final class JournalReceiptHandoverIntegrationTest extends IntegrationTestCase
{
    private const string KEY_PREFIX = 'journal.receipt.hil1454.';

    private const array JOURNAL_CLEANUP_ORDER = [
        'hilos_change_log_value', 'hilos_change_log_change', 'hilos_change_log',
        'hilos_change_log_receipt', 'hilos_change_log_field', 'hilos_change_log_table',
    ];

    /** @var array<string, int> Journal high water marks before the case */
    private array $baseline = [];

    /** @var list<array<string, mixed>> Trigger definitions present before the case */
    private array $originalTriggers = [];

    /** @var list<int> Test people, removed after their sessions */
    private array $userIds = [];

    /** @var list<int> Test sessions */
    private array $sessionIds = [];

    /** @var list<int> People used by the rename flow, removed after its journal and feed rows */
    private array $renameUserIds = [];

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
        if ($this->sessionIds !== []) {
            Hilos::$rt->connections->actions->clear();
            foreach ($this->sessionIds as $id) {
                Hilos::$db->sessions[$id]?->actions->delete();
            }
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
        Database::sqlRun('DELETE FROM `hilos_setting` WHERE `key` LIKE ?', [self::KEY_PREFIX . '%']);
        foreach (self::triggerCatalog() as $row) {
            Database::sql('DROP TRIGGER `' . $row['TRIGGER_NAME'] . '`');
        }
        foreach ($this->renameUserIds as $id) {
            Database::sqlRun('DELETE FROM `event` WHERE `id` IN '
                . '(SELECT `event_id` FROM `hilos_user_rename` WHERE `user_id` = ? AND `event_id` IS NOT NULL)', [$id]);
            Database::sqlRun('DELETE FROM `hilos_user_rename` WHERE `user_id` = ?', [$id]);
            Hilos::$db->users[$id]?->actions->delete();
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

    public function testPageHandoverKeepsReceiptUntilWorkerWrites(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), 'test-agent');
        $administratorId = $this->newUser();
        $subjectId = $this->newUser();
        $sessionId = $this->newSession('receipt-handover-ak', $subjectId, $administratorId);
        Hilos::initBrowser();
        $worker = new JournalReceiptHandoverTestWorker();
        $worker->handleDaemonMessage(new AgentStartDTO(JournalReceiptHandoverTestAgent::AGENT_TYPE));

        $frame = $this->dispatchPageAction(JournalReceiptHandoverTestPage::WRITE_ACTION);
        $this->assertInstanceOf(AgentSignalData::class, $frame->data);
        $receiptId = $frame->data->receiptId;
        $this->assertNotNull($receiptId);
        $this->assertSame(1, (int) $this->receipt($receiptId)['open_handovers']);
        $this->assertSame(0, $this->logCount($receiptId));

        $frame = SignalDTO::fromArray($frame->toArray());
        $this->assertInstanceOf(AgentSignalData::class, $frame->data);
        $this->assertSame($receiptId, $frame->data->receiptId);
        $worker->handleDaemonMessage(new DaemonAgentMessageDTO(JournalReceiptHandoverTestAgent::AGENT_TYPE, $frame));

        $this->assertSame(0, (int) $this->receipt($receiptId)['open_handovers']);
        $this->assertSame(1, $this->logCount($receiptId));
        $this->assertSame([
            (string) $administratorId, (string) $subjectId, (string) $sessionId,
            'web', JournalReceiptHandoverTestPage::WRITE_ACTION,
            JournalReceiptHandoverTestAgent::AGENT_TYPE, 'session #' . $sessionId,
        ], $this->receiptAttribution($receiptId));
        Database::sql('SELECT @hilos_receipt AS `id`');
        $this->assertNull(Database::field('id'));
        $this->assertNull(JournalReceiptScope::currentId());

        new JournalReceiptHandoverTestAgent()->onSignalAgent(
            new AgentSignalData(new SignalData()), 'timer', JournalReceiptHandoverTestAgent::WRITE_SIGNAL,
        );
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        Database::sql("SELECT `receipt_id` FROM {$database}.`hilos_change_log` WHERE `id` > ? ORDER BY `id` DESC LIMIT 1",
            [$this->baseline['hilos_change_log']]);
        $this->assertNull(Database::field('receipt_id'));
    }

    public function testEmptyAndChainedHandoversCloseOnlyAfterTheLastFrame(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), 'test-agent');
        $this->newSession('receipt-handover-ak', $this->newUser());
        Hilos::initBrowser();
        $worker = new JournalReceiptHandoverTestWorker();
        $worker->handleDaemonMessage(new AgentStartDTO(JournalReceiptHandoverTestAgent::AGENT_TYPE));

        $empty = $this->dispatchPageAction(JournalReceiptHandoverTestPage::NOOP_ACTION);
        $this->assertInstanceOf(AgentSignalData::class, $empty->data);
        $emptyId = $empty->data->receiptId;
        $this->assertNotNull($emptyId);
        $worker->handleDaemonMessage(new DaemonAgentMessageDTO(JournalReceiptHandoverTestAgent::AGENT_TYPE, $empty));
        $this->assertNull($this->receipt($emptyId));

        $first = $this->dispatchPageAction(JournalReceiptHandoverTestPage::CHAIN_ACTION);
        $this->assertInstanceOf(AgentSignalData::class, $first->data);
        $receiptId = $first->data->receiptId;
        $this->assertNotNull($receiptId);
        $worker->handleDaemonMessage(new DaemonAgentMessageDTO(JournalReceiptHandoverTestAgent::AGENT_TYPE, $first));
        $this->assertSame(1, (int) $this->receipt($receiptId)['open_handovers']);
        $this->assertSame(0, $this->logCount($receiptId));

        $second = $this->nextAgentFrame();
        $this->assertInstanceOf(AgentSignalData::class, $second->data);
        $this->assertSame($receiptId, $second->data->receiptId);
        $worker->handleDaemonMessage(new DaemonAgentMessageDTO(JournalReceiptHandoverTestAgent::AGENT_TYPE, $second));
        $this->assertSame(0, (int) $this->receipt($receiptId)['open_handovers']);
        $this->assertSame(1, $this->logCount($receiptId));
    }

    public function testRealSettingsLibraryOwnsTheWriteAndTheReplyClosesTheReceipt(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), 'test-agent');
        $this->newSession('receipt-handover-ak', $this->newUser());
        Hilos::initBrowser();
        $worker = new JournalReceiptHandoverTestWorker();
        $worker->handleDaemonMessage(new AgentStartDTO(SettingsLibraryAgent::AGENT_TYPE));
        $worker->handleDaemonMessage(new AgentStartDTO(JournalReceiptHandoverTestAgent::AGENT_TYPE));

        $key = SettingsCatalogConstants::STUB_KEY_EXAMPLE_STRING;
        Database::sql('SELECT `type`, `value` FROM `hilos_setting` WHERE `key` = ?', [$key]);
        $previous = Database::row();
        try {
            $frame = $this->dispatchPageAction(JournalReceiptHandoverTestPage::SETTING_ACTION);
            $this->assertInstanceOf(AgentSignalData::class, $frame->data);
            $receiptId = $frame->data->receiptId;
            $this->assertNotNull($receiptId);
            $worker->handleDaemonMessage(new DaemonAgentMessageDTO(SettingsLibraryAgent::AGENT_TYPE, $frame));

            $this->assertSame(1, $this->logCount($receiptId));
            $this->assertSame(SettingsLibraryAgent::AGENT_TYPE, $this->receipt($receiptId)['agent']);
            $this->assertSame(1, (int) $this->receipt($receiptId)['open_handovers']);
            $reply = $this->nextAgentFrame();
            $this->assertInstanceOf(AgentSignalData::class, $reply->data);
            $this->assertSame($receiptId, $reply->data->receiptId);
            $worker->handleDaemonMessage(new DaemonAgentMessageDTO(JournalReceiptHandoverTestAgent::AGENT_TYPE, $reply));
            $this->assertSame(0, (int) $this->receipt($receiptId)['open_handovers']);
        } finally {
            $previousAgent = ExecutionContext::currentAgentId();
            ExecutionContext::setCurrentAgentId(SettingsLibraryAgent::AGENT_TYPE);
            try {
                if ($previous === null) {
                    Hilos::$db->settings[$key]?->actions->delete();
                } else {
                    Hilos::$db->settings[$key]?->actions->updateValue($previous['value']);
                }
            } finally {
                ExecutionContext::setCurrentAgentId($previousAgent);
            }
        }
    }

    public function testNonAgentFrameQueuedInsideReceiptDoesNotKeepItOpen(): void
    {
        Hilos::initSignalRouter(new ChatSignalRouter());
        $receiptId = JournalReceiptScope::run(
            JournalReceiptData::web(null, null, null, 'receipt.non-agent', 'test-agent'),
            static function (): ?int {
                Hilos::$sr->queueSignal(
                    new SignalSource(SignalSource::WORKER),
                    new SignalType(SignalTypeConstants::CRON),
                    new SignalName('receipt_non_agent_probe'),
                    new AgentSignalData(new SignalData()),
                );
                return JournalReceiptScope::currentId();
            },
        );
        $this->assertNotNull($receiptId);
        $this->assertNull($this->receipt($receiptId));
        $frame = Hilos::$sr->getNextQueuedSignal();
        $this->assertInstanceOf(SignalDTO::class, $frame);
        $this->assertInstanceOf(AgentSignalData::class, $frame->data);
        $this->assertNull($frame->data->receiptId);
    }

    public function testUndeliveredAndUnattributedFramesDoNotBorrowAnotherReceipt(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), 'test-agent');
        $userId = $this->newUser();
        $this->newSession('receipt-handover-ak', $userId);
        Hilos::initBrowser();
        $worker = new JournalReceiptHandoverTestWorker();
        $worker->handleDaemonMessage(new AgentStartDTO(JournalReceiptHandoverTestAgent::AGENT_TYPE));

        $lost = $this->dispatchPageAction(JournalReceiptHandoverTestPage::WRITE_ACTION);
        $this->assertInstanceOf(AgentSignalData::class, $lost->data);
        $receiptId = $lost->data->receiptId;
        $this->assertNotNull($receiptId);
        $this->assertSame(1, (int) $this->receipt($receiptId)['open_handovers']);
        $this->assertSame(0, $this->logCount($receiptId));
        $reader = new ChangeLogSectionReader();
        $this->assertNull($reader->receipt($receiptId));
        $this->assertSame([], $reader->feedWindow(
            new ChangeLogFeedFilter(who: (string) $userId), null, TableAnchorDirection::After, 50,
        ));

        $plain = new SignalDTO(
            new SignalSource(SignalSource::AGENT, 'outside-receipt'),
            new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            new SignalName(JournalReceiptHandoverTestAgent::WRITE_SIGNAL),
            new AgentSignalData(new SignalData()),
        );
        $worker->handleDaemonMessage(new DaemonAgentMessageDTO(JournalReceiptHandoverTestAgent::AGENT_TYPE, $plain));
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        Database::sql("SELECT `receipt_id` FROM {$database}.`hilos_change_log` WHERE `id` > ? ORDER BY `id` DESC LIMIT 1",
            [$this->baseline['hilos_change_log']]);
        $this->assertNull(Database::field('receipt_id'));
        $this->assertSame(1, (int) $this->receipt($receiptId)['open_handovers']);
    }

    public function testImpersonatedUserRenameKeepsOneReceiptThroughBothOwners(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), 'test-agent');
        $administratorId = $this->newUser(cleanup: false);
        $subjectId = $this->newUser(cleanup: false);
        $targetId = $this->newUser(cleanup: false);
        Hilos::$db->users[$administratorId]?->actions->setAdmin(true);
        Hilos::$db->users[$subjectId]?->actions->setAdmin(true);
        $sessionId = $this->newSession('receipt-handover-ak', $subjectId, $administratorId);
        Hilos::initBrowser();
        $worker = new JournalReceiptHandoverTestWorker();
        $worker->handleDaemonMessage(new AgentStartDTO(UsersLibraryAgent::AGENT_TYPE));
        $worker->handleDaemonMessage(new AgentStartDTO(UserAgent::AGENT_TYPE . ':' . $targetId));
        $worker->handleDaemonMessage(new AgentStartDTO(JournalReceiptHandoverTestAgent::AGENT_TYPE));

        $pageRouter = new PageSignalRouter(
            new HilosPageFactory(new JournalReceiptHandoverTestPageAgent(), Hilos::class),
            new ActionRouteConfig([HilosSignalConstants::HILOS_USER_UPDATE => UserPage::PAGE]),
        );
        $previousAgent = ExecutionContext::currentAgentId();
        ExecutionContext::setCurrentAgentId('receipt-page-agent');
        try {
            $pageRouter->dispatchAction(new WebSocketActionSignalDTO(
                'receipt-handover-ak', HilosSignalConstants::HILOS_USER_UPDATE,
                new HilosUserUpdateActionDTO($targetId, 'Renamed by handover')->toArray(), 'request-rename',
            ), 'websocket');
        } finally {
            ExecutionContext::setCurrentAgentId($previousAgent);
        }

        $first = $this->nextAgentFrameNamed(HilosSignalConstants::HILOS_USER_ADMIN_RENAME);
        $this->assertInstanceOf(AgentSignalData::class, $first->data);
        $receiptId = $first->data->receiptId;
        $this->assertNotNull($receiptId);
        $worker->handleDaemonMessage(new DaemonAgentMessageDTO(UsersLibraryAgent::AGENT_TYPE, $first));
        $second = $this->nextAgentFrameNamed(HilosSignalConstants::HILOS_USER_RENAME);
        $this->assertInstanceOf(AgentSignalData::class, $second->data);
        $this->assertSame($receiptId, $second->data->receiptId);
        $worker->handleDaemonMessage(new DaemonAgentMessageDTO(UserAgent::AGENT_TYPE . ':' . $targetId, $second));
        $this->assertSame('Renamed by handover', Hilos::$db->users[$targetId]?->name);

        $third = $this->nextAgentFrameNamed(HilosSignalConstants::HILOS_USER_RENAME_DONE);
        $this->assertInstanceOf(AgentSignalData::class, $third->data);
        $this->assertSame($receiptId, $third->data->receiptId);
        $worker->handleDaemonMessage(new DaemonAgentMessageDTO(UsersLibraryAgent::AGENT_TYPE, $third));

        while (($frame = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($frame->data instanceof AgentSignalData && $frame->data->receiptId === $receiptId) {
                $worker->handleDaemonMessage(new DaemonAgentMessageDTO(JournalReceiptHandoverTestAgent::AGENT_TYPE, $frame));
            }
        }
        $this->assertSame(0, (int) $this->receipt($receiptId)['open_handovers']);
        $this->assertSame(UserAgent::AGENT_TYPE . ':' . $targetId, $this->receipt($receiptId)['agent']);
        $this->assertSame((string) $administratorId, $this->receipt($receiptId)['actor_user_id']);
        $this->assertSame((string) $subjectId, $this->receipt($receiptId)['subject_user_id']);
        $this->assertSame((string) $sessionId, $this->receipt($receiptId)['session_id']);
        $this->assertSame(1, $this->logCount($receiptId));
    }

    public function testMigrationCanRemoveAndRestoreTheHandoverColumn(): void
    {
        $latest = Migration::getCurrentIndex();
        $this->assertGreaterThanOrEqual(93, $latest);
        try {
            $this->assertSame($latest - 92, Migration::migrateDown(92));
            $this->assertSame($latest - 92, Migration::migrateUp($latest));
        } finally {
            if (Migration::getCurrentIndex() < $latest) {
                Migration::migrateUp($latest);
            }
        }
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        Database::sql("SHOW COLUMNS FROM {$database}.`hilos_change_log_receipt` LIKE 'open_handovers'");
        $this->assertNotNull(Database::row());
    }

    private function dispatchPageAction(string $action): SignalDTO
    {
        $router = new PageSignalRouter(
            new JournalReceiptHandoverTestPageFactory(new JournalReceiptHandoverTestPageAgent()),
            new ActionRouteConfig([
                JournalReceiptHandoverTestPage::WRITE_ACTION => JournalReceiptHandoverTestPage::PAGE,
                JournalReceiptHandoverTestPage::NOOP_ACTION => JournalReceiptHandoverTestPage::PAGE,
                JournalReceiptHandoverTestPage::CHAIN_ACTION => JournalReceiptHandoverTestPage::PAGE,
                JournalReceiptHandoverTestPage::SETTING_ACTION => JournalReceiptHandoverTestPage::PAGE,
            ]),
        );
        $previousAgent = ExecutionContext::currentAgentId();
        ExecutionContext::setCurrentAgentId('receipt-page-agent');
        try {
            $router->dispatchAction(new WebSocketActionSignalDTO('receipt-handover-ak', $action, [], 'request-handover'), 'websocket');
        } finally {
            ExecutionContext::setCurrentAgentId($previousAgent);
        }
        return $this->nextAgentFrame();
    }

    private function nextAgentFrame(): SignalDTO
    {
        while (($frame = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($frame->data instanceof AgentSignalData) {
                return $frame;
            }
        }
        self::fail('The action owes an agent frame');
    }

    private function nextAgentFrameNamed(string $name): SignalDTO
    {
        while (($frame = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($frame->signalName->getName() === $name && $frame->data instanceof AgentSignalData) {
                return $frame;
            }
        }
        self::fail("The handover owes agent frame {$name}");
    }

    private function newUser(bool $cleanup = true): int
    {
        $id = (int) Hilos::$db->users->actions->createWithName('Receipt handover fixture')->id;
        if ($cleanup) {
            $this->userIds[] = $id;
        } else {
            $this->renameUserIds[] = $id;
        }
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

    /** @return ?array<string, mixed> Receipt row, or null after its deletion */
    private function receipt(int $id): ?array
    {
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        Database::sql("SELECT * FROM {$database}.`hilos_change_log_receipt` WHERE `id` = ?", [$id]);
        return Database::row();
    }

    /** @return list<?string> Attribution fields in stored order */
    private function receiptAttribution(int $id): array
    {
        $row = $this->receipt($id);
        $this->assertNotNull($row);
        return array_values(array_intersect_key($row, array_flip([
            'actor_user_id', 'subject_user_id', 'session_id', 'channel', 'action', 'agent', 'source',
        ])));
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
}

/** @extends AbstractPageFactory<JournalReceiptHandoverTestPageAgent> */
final class JournalReceiptHandoverTestPageFactory extends AbstractPageFactory
{
    protected function createPage(string $pageName): AbstractPage
    {
        return $pageName === JournalReceiptHandoverTestPage::PAGE
            ? new JournalReceiptHandoverTestPage($this->agent)
            : throw new PageNotFoundException($pageName);
    }

    public function hasPage(string $pageName): bool
    {
        return $pageName === JournalReceiptHandoverTestPage::PAGE;
    }
}

final class JournalReceiptHandoverTestPageAgent implements PageAgentInterface
{
    public function getId(): string
    {
        return 'receipt-page-agent';
    }

    public function getAgentSignalSource(): SignalSourceInterface
    {
        return new SignalSource(SignalSource::AGENT, 'receipt-page-agent');
    }
}

final class JournalReceiptHandoverTestPage extends AbstractPage
{
    public const string PAGE = 'journal_receipt_handover_test';
    public const string WRITE_ACTION = 'journal.receipt.handover.write';
    public const string NOOP_ACTION = 'journal.receipt.handover.noop';
    public const string CHAIN_ACTION = 'journal.receipt.handover.chain';
    public const string SETTING_ACTION = 'journal.receipt.handover.setting';

    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        $signalName = match ($action) {
            self::NOOP_ACTION => JournalReceiptHandoverTestAgent::NOOP_SIGNAL,
            self::CHAIN_ACTION => JournalReceiptHandoverTestAgent::CHAIN_SIGNAL,
            self::SETTING_ACTION => HilosSignalConstants::HILOS_SETTING_WRITE,
            default => JournalReceiptHandoverTestAgent::WRITE_SIGNAL,
        };
        $payload = $action === self::SETTING_ACTION
            ? new SettingWriteSignalData(
                replySignal: JournalReceiptHandoverTestAgent::SETTING_REPLY_SIGNAL,
                acceptKey: $acceptKey,
                requestId: null,
                action: HilosSignalConstants::SETTING_ADD,
                successMessage: null,
                key: SettingsCatalogConstants::STUB_KEY_EXAMPLE_STRING,
                value: 'handover-value',
            )
            : new SignalData();
        Hilos::$sr->queueSignal(
            new SignalSource(SignalSource::AGENT, 'receipt-page-agent'),
            new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            new SignalName($signalName),
            new AgentSignalData($payload),
        );
        return null;
    }
}

/** A worker with a live agent and a daemon link that receives no external frames. */
final class JournalReceiptHandoverTestWorker extends WorkerManager
{
    public function __construct()
    {
        parent::__construct(1);
        $this->daemonClient = new JournalReceiptHandoverTestClient();
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new ChatSignalRouter();
    }

    protected function createAgentManager(): AgentManager
    {
        return new JournalReceiptHandoverTestAgentManager();
    }

    protected function createPageSignalRouter(AgentInterface $agent): PageSignalRouter
    {
        return new PageSignalRouter(new JournalReceiptHandoverEmptyPageFactory($agent), new ActionRouteConfig());
    }
}

final class JournalReceiptHandoverTestClient extends WorkerDaemonClient
{
    public function send(WorkerDTO|array $data): void
    {
    }

    public function isConnected(): bool
    {
        return true;
    }
}

final class JournalReceiptHandoverTestAgentManager extends AgentManager
{
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        return match ($agentType) {
            SettingsLibraryAgent::AGENT_TYPE => new SettingsLibraryAgent(),
            UsersLibraryAgent::AGENT_TYPE => new UsersLibraryAgent(),
            UserAgent::AGENT_TYPE => new UserAgent($agentIndex ?? throw new LogicException('Person agent needs an index')),
            default => new JournalReceiptHandoverTestAgent(),
        };
    }
}

/** @extends AbstractPageFactory<AgentInterface> */
final class JournalReceiptHandoverEmptyPageFactory extends AbstractPageFactory
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

final class JournalReceiptHandoverTestAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'journal_receipt_handover_test_agent';
    public const string WRITE_SIGNAL = 'journal_receipt_handover_write';
    public const string NOOP_SIGNAL = 'journal_receipt_handover_noop';
    public const string CHAIN_SIGNAL = 'journal_receipt_handover_chain';
    public const string SETTING_REPLY_SIGNAL = 'journal_receipt_handover_setting_done';

    public function onStop(): void
    {
    }

    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        if ($name === self::CHAIN_SIGNAL) {
            Hilos::$sr->queueSignal(
                $this->getAgentSignalSource(),
                new SignalType(SignalTypeConstants::AGENT_SIGNAL),
                new SignalName(self::WRITE_SIGNAL),
                new AgentSignalData(new SignalData()),
            );
        } elseif ($name === self::WRITE_SIGNAL) {
            Database::sqlRun('INSERT INTO `hilos_setting` (`key`, `type`, `value`) VALUES (?, ?, ?)', [
                'journal.receipt.hil1454.' . RandomHelper::hex(8), 'string', 'written',
            ]);
        }
    }
}
