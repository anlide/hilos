<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\Hilos\DemoHilosChangeLogAgent;
use Demo\Chat\Database\Database;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\ChangeLog\ChangeLogDashboardPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\AdminViewMode\HiddenValue;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceOperations;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\ChangeLog\JournalReceiptData;
use Hilos\Database\ChangeLog\JournalReceiptScope;
use Hilos\Database\ChangeLog\JournalTriggerFiles;
use Hilos\Database\ChangeLog\JournalTriggerInstaller;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\Migration;
use Hilos\Pages\ChangeLog\DTO\HilosChangeLogEntryReadActionDTO;
use Hilos\Pages\ChangeLog\DTO\HilosChangeLogReceiptReadActionDTO;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\Attributes\DataProvider;

/** Real journal triggers, reader and routed tracked acknowledgements for both detail actions. */
final class ChangeLogReceiptPageIntegrationTest extends IntegrationTestCase
{
    private const string KEY_PREFIX = 'journal.receipt.hil1460.';
    private const string TEST_AGENT = 'change-log-receipt-test';
    private const string ADMIN = 'change-log-detail-admin';
    private const string VIEWER = 'change-log-detail-viewer';

    private const array JOURNAL_CLEANUP_ORDER = [
        'hilos_change_log_value', 'hilos_change_log_change', 'hilos_change_log',
        'hilos_change_log_receipt', 'hilos_change_log_field', 'hilos_change_log_table',
    ];

    /** @var array<string, int> Journal high water marks before the fixture */
    private array $baseline = [];

    /** @var list<array<string, mixed>> Original trigger definitions */
    private array $originalTriggers = [];

    /** @var list<int> Fixture people */
    private array $userIds = [];

    /** @var list<int> Fixture sessions */
    private array $sessionIds = [];

    /** @var list<int> Fixture moderator pieces */
    private array $pieceIds = [];

    private int $adminId;
    private int $adminSessionId;
    private int $requestNumber = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Hilos::initBrowser();
        Hilos::$sr = new SignalRouter();
        Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Migration::setMigrationListPath(dirname(__DIR__, 2) . '/backend/Database/Migration');
        Migration::setMigrationName('Schema');
        JournalTriggerFiles::setPath(dirname(__DIR__, 2) . '/backend/Database/Migration/Triggers');
        $this->originalTriggers = self::triggerCatalog();
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        foreach (self::JOURNAL_CLEANUP_ORDER as $table) {
            Database::sql("SELECT COALESCE(MAX(`id`), 0) AS `id` FROM {$database}.`{$table}`");
            $this->baseline[$table] = (int)Database::field('id');
        }
        JournalTriggerInstaller::apply();
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT);
        Hilos::$rt->connections->actions->clear();
        RtTruthSourceRegistry::registerDaemon(AdminViewModeRuntime::RT_ITEM);
        Hilos::$rt->hilosAdminViewModeRuntime->actions->set(false);
        $this->adminId = $this->registerBrowser(self::ADMIN, true);
        $this->adminSessionId = $this->sessionIds[0];
        $this->registerBrowser(self::VIEWER, false);
    }

    protected function tearDown(): void
    {
        Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Database::setJournalReceiptId(null);
        Hilos::$rt->connections->actions->clear();
        RtTruthSourceRegistry::unregisterAgent(self::TEST_AGENT);
        Hilos::$rt->hilosAdminViewModeRuntime->actions->set(false);
        RtTruthSourceRegistry::unregisterDaemon(AdminViewModeRuntime::RT_ITEM);
        foreach (self::triggerCatalog() as $row) {
            Database::sql('DROP TRIGGER `' . $row['TRIGGER_NAME'] . '`');
        }
        foreach ($this->sessionIds as $id) {
            Database::sqlRun('DELETE FROM `hilos_session` WHERE `id` = ?', [$id]);
        }
        foreach ($this->userIds as $id) {
            Database::sqlRun('DELETE FROM `hilos_user` WHERE `id` = ?', [$id]);
        }
        foreach ($this->pieceIds as $id) {
            Database::sqlRun('DELETE FROM `moderator_prompt_piece` WHERE `id` = ?', [$id]);
        }
        Database::sqlRun('DELETE FROM `hilos_setting` WHERE `key` LIKE ?', [self::KEY_PREFIX . '%']);
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        foreach (self::JOURNAL_CLEANUP_ORDER as $table) {
            Database::sqlRun("DELETE FROM {$database}.`{$table}` WHERE `id` > ?", [$this->baseline[$table]]);
        }
        foreach ($this->originalTriggers as $row) {
            Database::sql('CREATE TRIGGER `' . $row['TRIGGER_NAME'] . '` ' . $row['ACTION_TIMING']
                . ' ' . $row['EVENT_MANIPULATION'] . ' ON `' . $row['EVENT_OBJECT_TABLE']
                . '` FOR EACH ROW ' . $row['ACTION_STATEMENT']);
        }
        Hilos::$sr = null;
        parent::tearDown();
    }

    public function testFullReceiptHasFreshAttributionLongBodiesAndNoPersonalValues(): void
    {
        $subjectId = $this->createUser('Receipt subject before');
        $before = str_repeat('Long body before. ', 300);
        $after = str_repeat('Long body after. ', 400);
        $receiptId = JournalReceiptScope::run(
            JournalReceiptData::web($this->adminId, $subjectId, $this->adminSessionId, 'moderator_prompt_piece_update', 'library'),
            function () use ($subjectId, $before, $after): ?int {
                Database::sql('INSERT INTO `moderator_prompt_piece` (`section`, `prompt_piece`) VALUES (?, ?)', ['name_rule', $before]);
                $pieceId = Database::lastInsertId();
                $this->pieceIds[] = $pieceId;
                Database::sqlRun('UPDATE `moderator_prompt_piece` SET `section` = ?, `prompt_piece` = ? WHERE `id` = ?',
                    ['message_rule', $after, $pieceId]);
                Database::sqlRun('DELETE FROM `moderator_prompt_piece` WHERE `id` = ?', [$pieceId]);
                Database::sqlRun('UPDATE `hilos_user` SET `name` = ? WHERE `id` = ?', ['Receipt subject after', $subjectId]);
                return JournalReceiptScope::currentId();
            },
        );
        self::assertNotNull($receiptId);
        $counts = $this->journalCounts();
        $admin = $this->receipt(self::ADMIN, $receiptId);
        self::assertSame($counts, $this->journalCounts(), 'A detail read leaves no receipt or journal entries of its own');
        self::assertSame([
            'receiptId' => $receiptId, 'createdAt' => $admin['receipt']['createdAt'],
            'actorId' => $this->adminId, 'actorLabel' => 'Receipt detail admin', 'actorDeleted' => false,
            'subjectId' => $subjectId, 'subjectLabel' => 'Receipt subject after', 'subjectDeleted' => false,
            'sessionId' => $this->adminSessionId, 'channel' => 'web', 'action' => 'moderator_prompt_piece_update',
            'agent' => 'library', 'source' => 'session #' . $this->adminSessionId, 'entryCount' => 4,
        ], $admin['receipt']);
        self::assertMatchesRegularExpression('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z\z/', $admin['receipt']['createdAt']);
        self::assertSame(['create', 'update', 'delete', 'update'], array_column($admin['entries'], 'mutation'));
        self::assertSame([], $admin['entries'][0]['changes'], 'Create records only a fact');
        $update = array_column($admin['entries'][1]['changes'], null, 'field');
        self::assertSame('inline', $update['section']['kind']);
        self::assertSame('name_rule', $update['section']['oldValue']);
        self::assertSame('message_rule', $update['section']['newValue']);
        self::assertSame('long', $update['prompt_piece']['kind']);
        self::assertSame($before, $update['prompt_piece']['oldValue']);
        self::assertSame($after, $update['prompt_piece']['newValue']);
        self::assertTrue($update['prompt_piece']['oldPresent']);
        self::assertTrue($update['prompt_piece']['newPresent']);
        self::assertFalse($update['prompt_piece']['bodyOmitted']);
        $deleted = array_column($admin['entries'][2]['changes'], null, 'field');
        self::assertSame($after, $deleted['prompt_piece']['oldValue']);
        self::assertNull($deleted['prompt_piece']['newValue']);
        self::assertTrue($deleted['prompt_piece']['oldPresent']);
        self::assertFalse($deleted['prompt_piece']['newPresent']);
        self::assertFalse($deleted['prompt_piece']['bodyOmitted']);
        $personal = array_column($admin['entries'][3]['changes'], null, 'field');
        self::assertSame('fact', $personal['name']['kind']);
        self::assertNull($personal['name']['oldValue']);
        self::assertNull($personal['name']['newValue']);
        self::assertTrue($personal['name']['oldPresent']);
        self::assertTrue($personal['name']['newPresent']);
        self::assertNull($admin['nextAfterEntryId']);

        Hilos::$rt->hilosAdminViewModeRuntime->actions->set(true);
        $viewer = $this->receipt(self::VIEWER, $receiptId);
        self::assertSame($admin['entries'], $viewer['entries']);
        self::assertSame(array_replace($admin['receipt'], [
            'actorLabel' => HiddenValue::mark(), 'subjectLabel' => HiddenValue::mark(),
        ]), $viewer['receipt']);
        $bytes = json_encode($viewer, JSON_THROW_ON_ERROR);
        foreach (['Receipt detail admin', 'Receipt subject before', 'Receipt subject after'] as $name) {
            self::assertStringNotContainsString($name, $bytes);
        }
        self::assertSame($admin, $this->receipt(self::ADMIN, $receiptId), 'The mode leaves the admin answer intact');

        Database::sqlRun('DELETE FROM `hilos_user` WHERE `id` = ?', [$subjectId]);
        $erased = $this->receipt(self::ADMIN, $receiptId);
        self::assertSame('Deleted user #' . $subjectId, $erased['receipt']['subjectLabel']);
        self::assertTrue($erased['receipt']['subjectDeleted']);
        self::assertSame($subjectId, $erased['receipt']['subjectId']);
        self::assertSame(HiddenValue::mark(), $this->receipt(self::VIEWER, $receiptId)['receipt']['subjectLabel']);
    }

    #[DataProvider('attributionCases')]
    public function testGuestAndSystemChannelAttributionIsReadWithoutInventingPeople(
        JournalReceiptData $origin,
        string $channel,
        string $action,
        ?string $agent,
        ?string $source,
    ): void {
        $receiptId = $this->settingReceipt($origin, 'attribution');
        $header = $this->receipt(self::ADMIN, $receiptId)['receipt'];
        self::assertSame($channel, $header['channel']);
        self::assertSame($action, $header['action']);
        self::assertSame($agent, $header['agent']);
        self::assertSame($source, $header['source']);
        foreach (['actorId', 'actorLabel', 'subjectId', 'subjectLabel', 'sessionId'] as $field) {
            self::assertNull($header[$field]);
        }
        self::assertFalse($header['actorDeleted']);
        self::assertFalse($header['subjectDeleted']);
        self::assertSame(1, $header['entryCount']);
    }

    /** @return iterable<string, array{JournalReceiptData, string, string, ?string, ?string}> Existing receipt producer contracts */
    public static function attributionCases(): iterable
    {
        yield 'guest' => [JournalReceiptData::web(null, null, null, 'register', 'usersLibrary'),
            'web', 'register', 'usersLibrary', null];
        yield 'migration' => [JournalReceiptData::migration('up', 95, '/migrations/095.sql'),
            'migration', 'migration.up.95', null, '095.sql'];
        yield 'agent' => [JournalReceiptData::agent('bot_message', 'bot:12'), 'agent', 'bot_message', 'bot:12', 'bot:12'];
        yield 'cron' => [JournalReceiptData::cron('sweep_second_factor_remove', '* * * * *', 'usersLibrary'),
            'cron', 'sweep_second_factor_remove', 'usersLibrary', 'sweep_second_factor_remove (* * * * *)'];
        yield 'cli' => [JournalReceiptData::cli('settings:set', 'hilos_index'), 'cli', 'settings:set', 'hilos_index', 'settings:set'];
    }

    public function testCronReceiptKeepsItsSourceAndNamesTheActualInstanceWriter(): void
    {
        Hilos::$sr = new JournalReceiptSyncRouter();
        $receiptId = JournalReceiptScope::start(JournalReceiptData::cron('sweep_second_factor_remove', '* * * * *', 'usersLibrary'));
        self::assertNotNull($receiptId);
        $writer = 'hilos_user:' . $this->adminId;
        $key = self::KEY_PREFIX . 'cron-part';
        TruthSourceRegistry::register(
            HilosDbContext::settings, TruthSourceKeys::all(), $writer,
            TruthSourceOperations::of(TruthSourceOperation::Add, TruthSourceOperation::Remove),
        );
        try {
            try {
                ExecutionContext::run(new ExecutionFrame($writer), static fn () => JournalReceiptScope::runPart(
                    $receiptId,
                    $writer,
                    static fn () => Hilos::$db->settings->actions->addOrphan($key, 'string', 'first', []),
                ));
            } finally {
                JournalReceiptScope::finish($receiptId);
            }
            self::assertSame('usersLibrary', $this->receipt(self::ADMIN, $receiptId)['receipt']['agent']);
            Hilos::$sr->deliverTo();
            $header = $this->receipt(self::ADMIN, $receiptId)['receipt'];
            self::assertSame('cron', $header['channel']);
            self::assertSame('sweep_second_factor_remove', $header['action']);
            self::assertSame($writer, $header['agent']);
            self::assertSame('sweep_second_factor_remove (* * * * *)', $header['source']);
        } finally {
            try {
                ExecutionContext::run(new ExecutionFrame($writer), static function () use ($key): void {
                    if (isset(Hilos::$db->settings[$key])) {
                        Hilos::$db->settings[$key]->actions->delete();
                    }
                });
            } finally {
                TruthSourceRegistry::unregister(HilosDbContext::settings, $writer);
            }
        }
    }

    public function testLargeReceiptUsesThreeBoundedPortionsWithoutDuplicatesAndRereadsTheHeader(): void
    {
        $receiptId = JournalReceiptScope::run(JournalReceiptData::agent('setting.batch', 'hilos_index'), static function (): ?int {
            for ($number = 0; $number < 103; $number++) {
                Database::sqlRun('INSERT INTO `hilos_setting` (`key`, `type`, `value`) VALUES (?, ?, ?)',
                    [self::KEY_PREFIX . 'page-' . $number, 'string', 'first']);
            }
            return JournalReceiptScope::currentId();
        });
        self::assertNotNull($receiptId);
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        // Create facts have no field rows: equal timestamps exercise the id tie-break without altering field history.
        Database::sqlRun("UPDATE {$database}.`hilos_change_log` SET `created_at` = UTC_TIMESTAMP(6) WHERE `receipt_id` = ?", [$receiptId]);
        Database::sql("SELECT `id` FROM {$database}.`hilos_change_log` WHERE `receipt_id` = ? ORDER BY `created_at`, `id`", [$receiptId]);
        $expectedIds = array_map(static fn(array $row): int => (int)$row['id'], Database::rows());
        Database::useConnection(ChangeLogDatabase::CONNECTION_INDEX);
        $direct = new ChangeLogDashboardPage(new DemoHilosChangeLogAgent())->onAction(self::ADMIN,
            HilosSignalConstants::HILOS_CHANGE_LOG_RECEIPT_READ, new HilosChangeLogReceiptReadActionDTO($receiptId));
        self::assertSame(ChangeLogDatabase::CONNECTION_INDEX, Database::getCurrentIndex());
        Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Hilos::$rt->hilosAdminViewModeRuntime->actions->set(true);
        $first = $this->receipt(self::VIEWER, $receiptId);
        self::assertSame($direct->toArray()['entries'], $first['entries']);
        self::assertCount(50, $first['entries']);
        self::assertSame(103, $first['receipt']['entryCount']);
        self::assertSame($first['entries'][49]['entryId'], $first['nextAfterEntryId']);
        $second = $this->receipt(self::VIEWER, $receiptId, $first['nextAfterEntryId']);
        self::assertCount(50, $second['entries']);
        self::assertSame($second['entries'][49]['entryId'], $second['nextAfterEntryId']);
        $last = $this->receipt(self::VIEWER, $receiptId, $second['nextAfterEntryId']);
        self::assertCount(3, $last['entries']);
        self::assertNull($last['nextAfterEntryId']);
        $actualIds = array_column(array_merge($first['entries'], $second['entries'], $last['entries']), 'entryId');
        self::assertSame($expectedIds, $actualIds);
        self::assertCount(103, array_unique($actualIds));
        $end = $this->receipt(self::VIEWER, $receiptId, $last['entries'][2]['entryId']);
        self::assertSame([], $end['entries']);
        self::assertNull($end['nextAfterEntryId']);
        Database::setJournalReceiptId($receiptId);
        try {
            Database::sqlRun('INSERT INTO `hilos_setting` (`key`, `type`, `value`) VALUES (?, ?, ?)',
                [self::KEY_PREFIX . 'later', 'string', 'later']);
        } finally {
            Database::setJournalReceiptId(null);
        }
        $fresh = $this->receipt(self::VIEWER, $receiptId, $last['entries'][2]['entryId']);
        self::assertSame(104, $fresh['receipt']['entryCount']);
        self::assertCount(1, $fresh['entries']);
        self::assertNull($fresh['nextAfterEntryId']);
    }

    public function testBareEntryActionIncludesFullLongValuesAndFactsForAdminAndViewer(): void
    {
        $piece = Hilos::$db->moderatorPromptPieces->actions->create('name_rule', str_repeat('bare before', 400));
        $pieceId = (int)$piece->id;
        $this->pieceIds[] = $pieceId;
        Database::sqlRun('UPDATE `moderator_prompt_piece` SET `prompt_piece` = ? WHERE `id` = ?', [str_repeat('bare after', 500), $pieceId]);
        $entryId = $this->lastBareEntry();
        $counts = $this->journalCounts();
        $admin = $this->success(self::ADMIN, HilosSignalConstants::HILOS_CHANGE_LOG_ENTRY_READ, ['entryId' => $entryId]);
        self::assertSame($counts, $this->journalCounts());
        self::assertSame($entryId, $admin['entry']['entryId']);
        self::assertNull($admin['entry']['receiptId']);
        self::assertSame([$pieceId], $admin['entry']['recordKey']);
        self::assertSame('long', $admin['entry']['changes'][0]['kind']);
        self::assertSame(str_repeat('bare before', 400), $admin['entry']['changes'][0]['oldValue']);
        self::assertSame(str_repeat('bare after', 500), $admin['entry']['changes'][0]['newValue']);
        self::assertFalse($admin['entry']['changes'][0]['bodyOmitted']);
        Hilos::$rt->hilosAdminViewModeRuntime->actions->set(true);
        self::assertSame($admin, $this->success(self::VIEWER, HilosSignalConstants::HILOS_CHANGE_LOG_ENTRY_READ, ['entryId' => $entryId]));
        Database::sqlRun('UPDATE `hilos_user` SET `name` = ? WHERE `id` = ?', ['Bare personal value never on the wire', $this->adminId]);
        $personalId = $this->lastBareEntry();
        foreach ([self::ADMIN, self::VIEWER] as $acceptKey) {
            $reply = $this->success($acceptKey, HilosSignalConstants::HILOS_CHANGE_LOG_ENTRY_READ, ['entryId' => $personalId]);
            self::assertSame('fact', $reply['entry']['changes'][0]['kind']);
            self::assertNull($reply['entry']['changes'][0]['oldValue']);
            self::assertNull($reply['entry']['changes'][0]['newValue']);
            self::assertStringNotContainsString('Bare personal value never on the wire', json_encode($reply, JSON_THROW_ON_ERROR));
        }
    }

    public function testUnknownEmptyAndWronglyAddressedDetailsHaveTrackedFailures(): void
    {
        $first = $this->settingReceipt(JournalReceiptData::agent('setting.create', 'hilos_index'), 'first');
        $other = $this->settingReceipt(JournalReceiptData::agent('setting.create', 'hilos_index'), 'other');
        $ownedEntryId = Hilos::$db->changeLogEntries->receiptEntries($first, null, 1)->get(0)->id;
        $foreignEntryId = Hilos::$db->changeLogEntries->receiptEntries($other, null, 1)->get(0)->id;
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        Database::sql("INSERT INTO {$database}.`hilos_change_log_receipt` (`created_at`, `channel`, `action`)"
            . " VALUES (UTC_TIMESTAMP(6), 'web', 'empty-detail-test')");
        $emptyId = Database::lastInsertId();
        foreach ([
            [HilosSignalConstants::HILOS_CHANGE_LOG_RECEIPT_READ, ['receiptId' => 999999999], 'Receipt not found'],
            [HilosSignalConstants::HILOS_CHANGE_LOG_RECEIPT_READ, ['receiptId' => $emptyId], 'Receipt not found'],
            [HilosSignalConstants::HILOS_CHANGE_LOG_RECEIPT_READ, ['receiptId' => $first, 'afterEntryId' => $foreignEntryId],
                'Invalid receipt cursor'],
            [HilosSignalConstants::HILOS_CHANGE_LOG_RECEIPT_READ, ['receiptId' => $first, 'afterEntryId' => 999999999],
                'Invalid receipt cursor'],
            [HilosSignalConstants::HILOS_CHANGE_LOG_ENTRY_READ, ['entryId' => 999999999], 'Change not found'],
            [HilosSignalConstants::HILOS_CHANGE_LOG_ENTRY_READ, ['entryId' => $ownedEntryId], 'This change belongs to a receipt'],
        ] as [$action, $payload, $reason]) {
            $ack = $this->dispatch(self::ADMIN, $action, $payload);
            self::assertInstanceOf(PageActionErrorSignalData::class, $ack);
            self::assertSame($reason, $ack->reason);
        }
        foreach ([
            [HilosSignalConstants::HILOS_CHANGE_LOG_RECEIPT_READ, ['receiptId' => '1']],
            [HilosSignalConstants::HILOS_CHANGE_LOG_RECEIPT_READ, ['receiptId' => $first, 'afterEntryId' => 0]],
            [HilosSignalConstants::HILOS_CHANGE_LOG_ENTRY_READ, ['entryId' => false]],
        ] as [$action, $payload]) {
            self::assertInstanceOf(PageActionErrorSignalData::class, $this->dispatch(self::ADMIN, $action, $payload));
        }
    }

    #[DataProvider('detailRequests')]
    public function testMissingBrowserContextRefusesBothReads(ActionPayloadDTO $request): void
    {
        Hilos::$browser = null;
        try {
            $this->expectException(LogicException::class);
            new ChangeLogDashboardPage(new DemoHilosChangeLogAgent())->onAction(self::ADMIN, $request->getAction(), $request);
        } finally {
            Hilos::initBrowser();
        }
    }

    /** @return iterable<string, array{ActionPayloadDTO}> Both detail addresses */
    public static function detailRequests(): iterable
    {
        yield 'receipt' => [new HilosChangeLogReceiptReadActionDTO(1)];
        yield 'entry' => [new HilosChangeLogEntryReadActionDTO(1)];
    }

    /**
     * @param string $acceptKey Connection reading a receipt
     * @param int $id Receipt number
     * @param ?int $after Cursor, or null for the first portion
     * @return array<string, mixed> Tracked receipt reply
     */
    private function receipt(string $acceptKey, int $id, ?int $after = null): array
    {
        return $this->success($acceptKey, HilosSignalConstants::HILOS_CHANGE_LOG_RECEIPT_READ,
            ['receiptId' => $id, 'afterEntryId' => $after]);
    }

    /**
     * @param string $acceptKey Reading connection
     * @param string $action Detail action name
     * @param array<string, mixed> $payload Request payload
     * @return array<string, mixed> Reply from the real success acknowledgement
     */
    private function success(string $acceptKey, string $action, array $payload): array
    {
        $ack = $this->dispatch($acceptKey, $action, $payload);
        self::assertInstanceOf(PageActionSuccessSignalData::class, $ack);
        self::assertNotNull($ack->reply);
        return $ack->reply;
    }

    /**
     * @param string $acceptKey Reading connection
     * @param string $action Detail action name
     * @param array<string, mixed> $payload Raw request parsed by project topology
     * @return PageActionSuccessSignalData|PageActionErrorSignalData The sole correlated acknowledgement
     */
    private function dispatch(string $acceptKey, string $action, array $payload): PageActionSuccessSignalData|PageActionErrorSignalData
    {
        $this->drainSignals();
        $requestId = 'detail-' . ++$this->requestNumber;
        $agent = new DemoHilosChangeLogAgent();
        $router = new PageSignalRouter(new HilosPageFactory($agent, Hilos::class), new ActionRouteConfig(Hilos::getPageActionRoutes()));
        $this->underAgent($agent, static fn() => $router->dispatchAction(
            new WebSocketActionSignalDTO($acceptKey, $action, $payload, $requestId), 'websocket',
        ));
        $acks = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if (!in_array($signal->signalName->getName(), [SignalConstants::ACTION_SUCCESS, SignalConstants::ACTION_ERROR], true)) {
                continue;
            }
            self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
            self::assertSame($acceptKey, $signal->data->targetAcceptKey);
            $ack = $signal->data->data;
            self::assertTrue($ack instanceof PageActionSuccessSignalData || $ack instanceof PageActionErrorSignalData);
            self::assertSame($action, $ack->action);
            self::assertSame($requestId, $ack->requestId);
            if ($acceptKey === self::VIEWER) {
                $bytes = json_encode($signal->data->toArray(), JSON_THROW_ON_ERROR);
                foreach (['Receipt detail admin', 'Receipt subject before', 'Receipt subject after',
                    'Bare personal value never on the wire'] as $name) {
                    self::assertStringNotContainsString($name, $bytes, 'Raw queued viewer bytes must not carry names');
                }
            }
            $acks[] = $ack;
        }
        self::assertCount(1, $acks);
        return $acks[0];
    }

    /**
     * @param JournalReceiptData $origin Attribution fixture
     * @param string $suffix Unique setting key suffix
     * @return int Receipt retaining one real trigger entry
     */
    private function settingReceipt(JournalReceiptData $origin, string $suffix): int
    {
        $id = JournalReceiptScope::run($origin, static function () use ($suffix): ?int {
            Database::sqlRun('INSERT INTO `hilos_setting` (`key`, `type`, `value`) VALUES (?, ?, ?)',
                [self::KEY_PREFIX . $suffix, 'string', 'first']);
            return JournalReceiptScope::currentId();
        });
        self::assertNotNull($id);
        return $id;
    }

    /** @return int Latest real bare entry */
    private function lastBareEntry(): int
    {
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        Database::sql("SELECT `id` FROM {$database}.`hilos_change_log` WHERE `receipt_id` IS NULL ORDER BY `id` DESC LIMIT 1");
        return (int)Database::field('id');
    }

    /** @return array{int, int} Persisted receipt and entry counts */
    private function journalCounts(): array
    {
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        Database::sql("SELECT (SELECT COUNT(*) FROM {$database}.`hilos_change_log_receipt`) AS receipts,"
            . " (SELECT COUNT(*) FROM {$database}.`hilos_change_log`) AS entries");
        $counts = Database::row();
        self::assertNotNull($counts);
        return [(int)$counts['receipts'], (int)$counts['entries']];
    }

    /**
     * @param string $acceptKey Fixture connection
     * @param bool $admin Whether it proves an administrator
     * @return int Fixture person id
     */
    private function registerBrowser(string $acceptKey, bool $admin): int
    {
        $userId = $this->createUser($admin ? 'Receipt detail admin' : 'Receipt detail viewer');
        if ($admin) {
            Hilos::$db->users[$userId]->actions->setAdmin(true);
        }
        $session = Hilos::$db->sessions->actions->createAnonymous(substr(hash('sha256', $acceptKey), 0, 32));
        $this->sessionIds[] = (int)$session->id;
        $session->actions->bindUser($userId);
        Hilos::$rt->connections->actions->register($acceptKey, $userId, $session->token, (int)$session->id);
        return $userId;
    }

    /**
     * @param string $name Fixture name
     * @return int New person id
     */
    private function createUser(string $name): int
    {
        $id = (int)Hilos::$db->users->actions->createWithName($name)->id;
        $this->userIds[] = $id;
        return $id;
    }

    /** @return list<array<string, mixed>> Primary trigger definitions */
    private static function triggerCatalog(): array
    {
        Database::sql('SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, EVENT_MANIPULATION, ACTION_TIMING, ACTION_STATEMENT'
            . ' FROM INFORMATION_SCHEMA.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME');
        return Database::rows();
    }
}
