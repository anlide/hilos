<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\Hilos\DemoHilosChangeLogAgent;
use Demo\Chat\Database\Database;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\ChangeLog\ChangeLogNoisyPage;
use Demo\Chat\Pages\Hilos\ChangeLog\ChangeLogTablesPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Demo\Chat\Tables\ChatTableContext;
use Hilos\AdminViewMode\HiddenValue;
use Hilos\Backup\Anonymization\LiveSchemaReader;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Table\DTO\TableWindowSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\ChangeLog\JournalTriggerFiles;
use Hilos\Database\ChangeLog\JournalTriggerInstaller;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\Migration;
use Hilos\Database\Schema\JournaledTables;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Tables\ChangeLog\HilosChangeLogTablesTableRow;
use Hilos\TruthSource\RtTruthSourceRegistry;

/** Admin and viewer subscriptions carry each page's metadata and first window together. */
final class ChangeLogTablesPageIntegrationTest extends IntegrationTestCase
{
    private const string TEST_AGENT = 'change-log-tables-page-test';

    private const array JOURNAL_CLEANUP_ORDER = [
        'hilos_change_log_value', 'hilos_change_log_change', 'hilos_change_log',
        'hilos_change_log_receipt', 'hilos_change_log_field', 'hilos_change_log_table',
    ];

    /** @var array<string, int> Journal row-number high water marks */
    private array $baseline = [];

    /** @var list<array<string, mixed>> Triggers present before the test */
    private array $originalTriggers = [];

    /** @var list<int> Fixture session ids */
    private array $sessionIds = [];

    /** @var list<int> Fixture person ids */
    private array $userIds = [];

    /** Initializes the real browser context and temporary connection ownership. */
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
    }

    /** Removes fixture people, sessions, and journal rows, restoring the original triggers. */
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

    public function testSchemaMetadataAndTablesArriveOnTheirOwnPagesForAdminAndViewer(): void
    {
        $this->connect('change-log-tables-admin', 'Change log tables admin', true);
        $this->connect('change-log-tables-viewer', 'Change log tables viewer', false);
        RtTruthSourceRegistry::registerDaemon(AdminViewModeRuntime::RT_ITEM);
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(true);

        $schemas = LiveSchemaReader::read(DatabaseConnectionDefaults::PRIMARY_INDEX);
        $expected = [];
        $journaledNames = [];
        foreach (JournaledTables::mounted() as $entityClass) {
            if (!isset($schemas[$entityClass::_table])) {
                continue;
            }
            $journaledNames[] = $entityClass::_table;
            foreach ($entityClass::_journalNoise as $column => $reason) {
                $expected[$entityClass::_table . '.' . $column] = [
                    'table' => $entityClass::_table, 'column' => $column, 'reason' => $reason,
                ];
            }
        }
        ksort($expected, SORT_STRING);
        self::assertNotEmpty($expected);

        $adminPayload = $this->subscribe('change-log-tables-admin');
        self::assertSame(array_values($expected), $adminPayload->data[ChangeLogNoisyPage::NOISY_COLUMNS]);
        $viewerPayload = $this->subscribe('change-log-tables-viewer');
        self::assertSame($adminPayload->data[ChangeLogNoisyPage::NOISY_COLUMNS],
            $viewerPayload->data[ChangeLogNoisyPage::NOISY_COLUMNS]);
        self::assertSame($adminPayload->windows, $viewerPayload->windows);
        $reopened = $this->subscribe('change-log-tables-viewer');
        self::assertSame($viewerPayload->data[ChangeLogNoisyPage::NOISY_COLUMNS],
            $reopened->data[ChangeLogNoisyPage::NOISY_COLUMNS]);
        foreach ([$this->subscribe('change-log-tables-admin', ChangeLogTablesPage::class),
            $this->subscribe('change-log-tables-viewer', ChangeLogTablesPage::class),
            $this->subscribe('change-log-tables-viewer', ChangeLogTablesPage::class)] as $payload) {
            self::assertArrayNotHasKey(ChangeLogNoisyPage::NOISY_COLUMNS, $payload->data);
            self::assertSame([], $payload->refusedWindows);
            self::assertArrayHasKey(ChatTableContext::hilosChangeLogTables, $payload->windows);
            $window = $payload->windows[ChatTableContext::hilosChangeLogTables];
            self::assertSame(count($schemas), $window[TableWindowSignalData::totalCount]);
            self::assertTrue($window[TableWindowSignalData::totalExact]);
            self::assertNotEmpty($window[TableWindowSignalData::rows]);
            $untrackedNames = array_values(array_diff(array_keys($schemas), $journaledNames));
            sort($journaledNames, SORT_STRING);
            sort($untrackedNames, SORT_STRING);
            self::assertSame(array_slice(array_merge($journaledNames, $untrackedNames), 0, $window[TableWindowSignalData::limit]),
                array_column($window[TableWindowSignalData::rows], PagePayload::rowKey));
            foreach ($window[TableWindowSignalData::rows] as $row) {
                self::assertSame($row[PagePayload::rowKey],
                    $row[PagePayload::slots]['table'][HilosChangeLogTablesTableRow::name]);
                self::assertArrayNotHasKey('id', $row[PagePayload::slots]['table']);
            }
            $wire = json_encode($payload->toArray(), JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString(HiddenValue::KEY, $wire);
            self::assertStringNotContainsString('Change log tables admin', $wire);
            self::assertStringNotContainsString('Change log tables viewer', $wire);
        }
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
     * Opens or reopens the page without any client action or viewport request.
     *
     * @param string $acceptKey Subscribing connection
     * @param class-string<ChangeLogNoisyPage|ChangeLogTablesPage> $pageClass Page being opened
     * @return PagePayload Metadata and first window from the only page response
     */
    private function subscribe(string $acceptKey, string $pageClass = ChangeLogNoisyPage::class): PagePayload
    {
        while (Hilos::$sr->getNextQueuedSignal() !== null) {
        }
        Hilos::$sr->subscribeToPage($pageClass::PAGE,
            new WebSocketPageSubscribeSignalDTO($acceptKey, $pageClass::PAGE, []));
        ExecutionContext::run(new ExecutionFrame(acceptKey: $acceptKey), static function () use ($acceptKey, $pageClass): void {
            new $pageClass(new DemoHilosChangeLogAgent())->onSubscribe($acceptKey, new PageRouteParams([]));
        });
        $responses = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() !== SignalTypeConstants::PAGE_RESPONSE) {
                continue;
            }
            self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
            self::assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
            $responses[] = $signal->data->data;
        }
        self::assertCount(1, $responses);
        return $responses[0]->payload;
    }

    /** @return list<array<string, mixed>> Primary schema trigger definitions */
    private static function triggerCatalog(): array
    {
        Database::sql('SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, EVENT_MANIPULATION, ACTION_TIMING, ACTION_STATEMENT'
            . ' FROM INFORMATION_SCHEMA.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME');
        return Database::rows();
    }
}
