<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\Hilos\DemoHilosChangeLogAgent;
use Demo\Chat\Database\Database;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\ChangeLog\ChangeLogDashboardPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\AdminViewMode\HiddenValue;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\ChangeLog\JournalReceiptData;
use Hilos\Database\ChangeLog\JournalReceiptScope;
use Hilos\Database\ChangeLog\JournalTriggerFiles;
use Hilos\Database\ChangeLog\JournalTriggerInstaller;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\Migration;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;

/** The Change Log page answers the same journal overview to an admin and a view-mode viewer. */
final class ChangeLogDashboardPageIntegrationTest extends IntegrationTestCase
{
    private const string KEY = 'journal.dashboard.hil1459';
    private const string TEST_AGENT = 'change-log-dashboard-test';

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

    /** @var list<int> Fixture user ids */
    private array $userIds = [];

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
        Database::sqlRun('DELETE FROM `hilos_setting` WHERE `key` = ?', [self::KEY]);
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

    public function testOverviewIsAOneFrameSnapshotAndVisibleInAdminViewMode(): void
    {
        $admin = Hilos::$db->users->actions->createWithName('Change log dashboard admin');
        $this->userIds[] = (int)$admin->id;
        $admin->actions->setAdmin(true);
        $adminSession = Hilos::$db->sessions->actions->createAnonymous(substr(hash('sha256', self::TEST_AGENT . 'admin'), 0, 32));
        $this->sessionIds[] = (int)$adminSession->id;
        $adminSession->actions->bindUser((int)$admin->id);
        Hilos::$rt->connections->actions->register('change-log-admin', (int)$admin->id,
            $adminSession->token, (int)$adminSession->id);
        $viewer = Hilos::$db->users->actions->createWithName('Change log dashboard viewer');
        $this->userIds[] = (int)$viewer->id;
        $viewerSession = Hilos::$db->sessions->actions->createAnonymous(substr(hash('sha256', self::TEST_AGENT . 'viewer'), 0, 32));
        $this->sessionIds[] = (int)$viewerSession->id;
        $viewerSession->actions->bindUser((int)$viewer->id);
        Hilos::$rt->connections->actions->register('change-log-viewer', (int)$viewer->id,
            $viewerSession->token, (int)$viewerSession->id);

        $before = $this->overview('change-log-admin');
        self::assertSame(['hilos_identity', 'hilos_second_factor', 'hilos_setting', 'hilos_user'], $before['journaledTables']);
        self::assertGreaterThanOrEqual(count($before['journaledTables']), $before['liveTables']);
        self::assertIsInt($before['journalEntries']);
        self::assertIsInt($before['journalBytes']);

        JournalReceiptScope::run(
            JournalReceiptData::web((int)$admin->id, null, (int)$adminSession->id, 'setting.create', 'dashboard-test'),
            static function (): void {
                Database::sqlRun('INSERT INTO `hilos_setting` (`key`, `type`, `value`) VALUES (?, ?, ?)',
                    [self::KEY, 'string', 'value']);
            },
        );
        $after = $this->overview('change-log-admin');
        self::assertSame($before['journalEntries'] + 1, $after['journalEntries']);
        self::assertSame($before['journaledTables'], $after['journaledTables']);
        self::assertSame($before['liveTables'], $after['liveTables']);
        self::assertIsString($after['oldestAt']);
        self::assertMatchesRegularExpression('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z\z/', $after['oldestAt']);

        RtTruthSourceRegistry::registerDaemon(AdminViewModeRuntime::RT_ITEM);
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(true);

        $viewerOverview = $this->overview('change-log-viewer');
        self::assertSame($after, $viewerOverview);
        self::assertStringNotContainsString(HiddenValue::KEY, json_encode($viewerOverview, JSON_THROW_ON_ERROR));
    }

    /**
     * @param string $acceptKey Connection opening the page
     * @return array<string, mixed> Overview from its only page response
     */
    private function overview(string $acceptKey): array
    {
        while (Hilos::$sr->getNextQueuedSignal() !== null) {
        }
        Hilos::$sr->subscribeToPage(ChangeLogDashboardPage::PAGE,
            new WebSocketPageSubscribeSignalDTO($acceptKey, ChangeLogDashboardPage::PAGE, []));
        ExecutionContext::run(new ExecutionFrame(acceptKey: $acceptKey), static function () use ($acceptKey): void {
            new ChangeLogDashboardPage(new DemoHilosChangeLogAgent())->onSubscribe($acceptKey, new PageRouteParams([]));
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
        return $responses[0]->payload->data[ChangeLogDashboardPage::OVERVIEW];
    }

    /** @return list<array<string, mixed>> Primary schema trigger definitions */
    private static function triggerCatalog(): array
    {
        Database::sql('SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, EVENT_MANIPULATION, ACTION_TIMING, ACTION_STATEMENT'
            . ' FROM INFORMATION_SCHEMA.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME');
        return Database::rows();
    }
}
