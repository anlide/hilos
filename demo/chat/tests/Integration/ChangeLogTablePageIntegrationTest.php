<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\Hilos\DemoHilosChangeLogAgent;
use Demo\Chat\Database\Database;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\ChangeLog\ChangeLogTablePage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\AdminViewMode\HiddenValue;
use Hilos\Backup\Anonymization\LiveSchemaReader;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\ChangeLog\JournalTriggerFiles;
use Hilos\Database\ChangeLog\JournalTriggerInstaller;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\Migration;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUpdateSubscriptionSignalDTO;
use Hilos\Tables\ChangeLog\HilosChangeLogTableParts;
use Hilos\TruthSource\RtTruthSourceRegistry;

/** One live table's section answers admin and viewer alike, follows an address change, and refuses an unknown name. */
final class ChangeLogTablePageIntegrationTest extends IntegrationTestCase
{
    private const string TEST_AGENT = 'change-log-table-page-test';
    private const string ADMIN = 'change-log-table-admin';
    private const string VIEWER = 'change-log-table-viewer';
    private const string BOT_NAME = 'Change log table bot';

    private const array JOURNAL_CLEANUP_ORDER = [
        'hilos_change_log_value', 'hilos_change_log_change', 'hilos_change_log',
        'hilos_change_log_receipt', 'hilos_change_log_field', 'hilos_change_log_table',
    ];

    private PageSignalRouter $router;

    /** @var array<string, int> Journal row-number high water marks */
    private array $baseline = [];

    /** @var list<array<string, mixed>> Triggers present before the test */
    private array $originalTriggers = [];

    /** @var list<int> Fixture session ids */
    private array $sessionIds = [];

    /** @var list<int> Fixture person ids */
    private array $userIds = [];

    /** @var list<int> Fixture bot ids */
    private array $botIds = [];

    /** Initializes the real browser context, the subscription dispatcher, and one admin connection. */
    protected function setUp(): void
    {
        parent::setUp();
        Hilos::initBrowser();
        Hilos::$sr = new SignalRouter();
        $this->router = new PageSignalRouter(new HilosPageFactory(new DemoHilosChangeLogAgent(), Hilos::class), new ActionRouteConfig());
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
        RtTruthSourceRegistry::registerDaemon(AdminViewModeRuntime::RT_ITEM);
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(false);
        Hilos::$rt->connections->actions->clear();
        $this->connect(self::ADMIN, 'Change log table admin', true);
    }

    /** Removes fixture people, sessions, bots, and journal rows, restoring the original triggers. */
    protected function tearDown(): void
    {
        Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Database::setJournalReceiptId(null);
        Hilos::$rt->connections->actions->clear();
        RtTruthSourceRegistry::unregisterAgent(self::TEST_AGENT);
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(false);
        RtTruthSourceRegistry::unregisterDaemon(AdminViewModeRuntime::RT_ITEM);
        foreach (self::triggerCatalog() as $row) {
            Database::sql('DROP TRIGGER `' . $row['TRIGGER_NAME'] . '`');
        }
        foreach ($this->botIds as $id) {
            Database::sqlRun('DELETE FROM `bot` WHERE `id` = ?', [$id]);
        }
        foreach ($this->sessionIds as $id) {
            Database::sqlRun('DELETE FROM `hilos_session` WHERE `id` = ?', [$id]);
        }
        foreach ($this->userIds as $id) {
            Database::sqlRun('DELETE FROM `hilos_user` WHERE `id` = ?', [$id]);
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
        Hilos::$sr = null;
        parent::tearDown();
    }

    /** A journaled project table answers its activity, every column in schema order with its placement, and three triggers. */
    public function testJournaledProjectTableSection(): void
    {
        Database::sqlRun('INSERT INTO `bot` (`name`) VALUES (?)', [self::BOT_NAME]);
        $this->botIds[] = Database::lastInsertId();
        $summary = Hilos::$db->changeLogTables->sectionTable('bot')?->summary;
        self::assertNotNull($summary?->lastChangeAt);
        self::assertGreaterThanOrEqual(1, $summary->changesLast24h);

        $section = $this->subscribe(self::ADMIN, 'bot')->payload->data[ChangeLogTablePage::SECTION];
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $section['lastChangeAt']);
        self::assertSame([
            'name' => 'bot',
            'owner' => 'project',
            'journaled' => true,
            'changesLast24h' => $summary->changesLast24h,
            'lastChangeAt' => HilosChangeLogTableParts::displayTime($summary->lastChangeAt),
            'columns' => [
                self::column('id', 'int', 'record_key', null),
                self::column('name', 'varchar', 'value', 'inline'),
                self::column('description', 'text', 'value', 'long'),
                self::column('style', 'varchar', 'value', 'inline'),
                self::column('topics', 'text', 'value', 'long'),
                self::column('personality', 'text', 'value', 'long'),
                self::column('active', 'tinyint', 'value', 'inline'),
                self::column('reaction_delay_min', 'int', 'value', 'inline'),
                self::column('reaction_delay_max', 'int', 'value', 'inline'),
                self::column('reaction_chance', 'tinyint', 'value', 'inline'),
                self::column('topic_match_required', 'tinyint', 'value', 'inline'),
                self::column('cooldown_after_message', 'int', 'value', 'inline'),
                self::column('priority', 'int', 'value', 'inline'),
            ],
            'triggers' => [
                ['name' => 'hilos_cl_bot_after_insert', 'event' => 'INSERT', 'table' => 'bot', 'validFromMigration' => 95],
                ['name' => 'hilos_cl_bot_after_update', 'event' => 'UPDATE', 'table' => 'bot', 'validFromMigration' => 95],
                ['name' => 'hilos_cl_bot_after_delete', 'event' => 'DELETE', 'table' => 'bot', 'validFromMigration' => 95],
            ],
        ], $section);
    }

    /** A table without the journal label answers the same section with no activity, no placement, and no triggers. */
    public function testUntrackedFrameworkTableSection(): void
    {
        $schema = LiveSchemaReader::read(DatabaseConnectionDefaults::PRIMARY_INDEX)['hilos_session'];
        $section = $this->subscribe(self::ADMIN, 'hilos_session')->payload->data[ChangeLogTablePage::SECTION];
        self::assertSame([
            'name' => 'hilos_session',
            'owner' => 'framework',
            'journaled' => false,
            'changesLast24h' => null,
            'lastChangeAt' => null,
            'columns' => array_map(
                static fn(string $name): array => self::column($name, (string)$schema->typeOf($name), null, null),
                array_keys($schema->columns),
            ),
            'triggers' => [],
        ], $section);
        self::assertNotEmpty($section['columns']);
    }

    /** An unknown name is a 404 refusal and a missing one a route error; neither answers a section. */
    public function testUnknownTableIsNotFound(): void
    {
        $this->assertSubscriptionError([HilosPageRouteParams::HILOS_CHANGE_LOG_TABLE_ID => 'no_such_table'], 404, 'not_found');
        $this->assertSubscriptionError([], 400, 'missing_page_route_param');

        $this->expectException(PageResourceNotFoundException::class);
        ExecutionContext::run(new ExecutionFrame(acceptKey: self::ADMIN), static function (): void {
            new ChangeLogTablePage(new DemoHilosChangeLogAgent())->onSubscribe(
                self::ADMIN, new PageRouteParams([HilosPageRouteParams::HILOS_CHANGE_LOG_TABLE_ID => 'no_such_table']),
            );
        });
    }

    /** The section is schema metadata, so a viewer of the admin view mode receives exactly what an admin does. */
    public function testViewerReceivesTheSameSection(): void
    {
        $this->connect(self::VIEWER, 'Change log table viewer', false);
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(true);

        foreach (['bot', 'hilos_session'] as $table) {
            $admin = $this->subscribe(self::ADMIN, $table)->payload->data[ChangeLogTablePage::SECTION];
            $viewer = $this->subscribe(self::VIEWER, $table)->payload->data[ChangeLogTablePage::SECTION];
            self::assertSame($admin, $viewer);
            self::assertSame($table, $viewer['name']);
            $wire = json_encode($viewer, JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString(HiddenValue::KEY, $wire);
            self::assertStringNotContainsString('Change log table viewer', $wire);
        }
    }

    /** A new address on the same page answers once with the other table's section; the record key is not the server's. */
    public function testAddressChangeAnswersTheOtherTable(): void
    {
        self::assertSame('bot', $this->subscribe(self::ADMIN, 'bot')->payload->data[ChangeLogTablePage::SECTION]['name']);
        $frames = $this->dispatch(fn() => $this->router->dispatchPageUpdateSubscription(new WebSocketPageUpdateSubscriptionSignalDTO(
            self::ADMIN, ChangeLogTablePage::PAGE, [HilosPageRouteParams::HILOS_CHANGE_LOG_TABLE_ID => 'hilos_session', 'record' => '7'],
        ), '', ChangeLogTablePage::PAGE), self::ADMIN);
        self::assertCount(1, $frames);
        self::assertSame(SignalTypeConstants::PAGE_RESPONSE, $frames[0]['name']);
        self::assertInstanceOf(PageResponseSignalData::class, $frames[0]['data']);
        $updated = $frames[0]['data']->payload->data[ChangeLogTablePage::SECTION];
        self::assertSame('hilos_session', $updated['name']);
        self::assertSame($this->subscribe(self::ADMIN, 'hilos_session')->payload->data[ChangeLogTablePage::SECTION], $updated);
    }

    /**
     * @param string $name Live column name
     * @param string $sqlType information_schema DATA_TYPE
     * @param ?string $mode Journal placement, or null outside the journal
     * @param ?string $storage Journal storage, or null for the record key and outside the journal
     * @return array<string, ?string> One expected column of the section
     */
    private static function column(string $name, string $sqlType, ?string $mode, ?string $storage): array
    {
        return ['name' => $name, 'sqlType' => $sqlType, 'mode' => $mode, 'storage' => $storage, 'noiseReason' => null];
    }

    /**
     * @param string $acceptKey Fixture connection
     * @param string $name Fixture person's personal name
     * @param bool $admin Whether this person has administrator rights
     */
    private function connect(string $acceptKey, string $name, bool $admin): void
    {
        $user = Hilos::$db->users->actions->createWithName($name);
        $this->userIds[] = (int)$user->id;
        if ($admin) {
            $user->actions->setAdmin(true);
        }
        $session = Hilos::$db->sessions->actions->createAnonymous(substr(hash('sha256', $acceptKey), 0, 32));
        $this->sessionIds[] = (int)$session->id;
        $session->actions->bindUser((int)$user->id);
        Hilos::$rt->connections->actions->register($acceptKey, (int)$user->id, $session->token, (int)$session->id);
    }

    /**
     * @param string $acceptKey Subscriber
     * @param string $table Live table address
     * @return PageResponseSignalData Only opening response
     */
    private function subscribe(string $acceptKey, string $table): PageResponseSignalData
    {
        $frames = $this->dispatchSubscribe($acceptKey, [HilosPageRouteParams::HILOS_CHANGE_LOG_TABLE_ID => $table]);
        self::assertCount(1, $frames);
        self::assertSame(SignalTypeConstants::PAGE_RESPONSE, $frames[0]['name']);
        self::assertInstanceOf(PageResponseSignalData::class, $frames[0]['data']);
        return $frames[0]['data'];
    }

    /**
     * @param array<string, string> $params Raw route parameters
     * @param int $httpCode Expected HTTP equivalent
     * @param string $errorCode Expected framework error
     */
    private function assertSubscriptionError(array $params, int $httpCode, string $errorCode): void
    {
        $frames = $this->dispatchSubscribe(self::ADMIN, $params);
        self::assertCount(1, $frames);
        self::assertInstanceOf(PageSubscriptionErrorSignalData::class, $frames[0]['data']);
        self::assertSame($httpCode, $frames[0]['data']->httpCode);
        self::assertSame($errorCode, $frames[0]['data']->errorCode);
    }

    /**
     * @param string $acceptKey Subscriber
     * @param array<string, string> $params Raw route parameters
     * @return list<array{name: string, data: SignalDataInterface}> Browser frames from one subscription
     */
    private function dispatchSubscribe(string $acceptKey, array $params): array
    {
        $request = new WebSocketPageSubscribeSignalDTO($acceptKey, ChangeLogTablePage::PAGE, $params);
        return $this->dispatch(function () use ($acceptKey, $request): void {
            Hilos::$sr->subscribeToPage(ChangeLogTablePage::PAGE, $request);
            Hilos::$sr->reportTableWindows($acceptKey, []);
            $this->router->dispatchPageSubscribe($request, '', ChangeLogTablePage::PAGE);
        }, $acceptKey);
    }

    /**
     * @param callable(): void $frame One browser frame handed to the page router
     * @param string $acceptKey Connection that sent it
     * @return list<array{name: string, data: SignalDataInterface}> Browser frames it queued, ignoring worker control traffic
     */
    private function dispatch(callable $frame, string $acceptKey): array
    {
        while (Hilos::$sr->getNextQueuedSignal() !== null) {
        }
        ExecutionContext::run(new ExecutionFrame(acceptKey: $acceptKey), $frame);
        $frames = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof WebSocketSignalData) {
                $frames[] = ['name' => $signal->signalName->getName(), 'data' => $signal->data->data];
            }
        }
        return $frames;
    }

    /** @return list<array<string, mixed>> Primary schema trigger definitions */
    private static function triggerCatalog(): array
    {
        Database::sql('SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, EVENT_MANIPULATION, ACTION_TIMING, ACTION_STATEMENT'
            . ' FROM INFORMATION_SCHEMA.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME');
        return Database::rows();
    }
}
