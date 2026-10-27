<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\AdminViewMode\HiddenValue;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\Exception\ActionViewModeException;
use Hilos\Core\Page\PageAccessGate;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Schema\Schema;
use Hilos\Hilos;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogDashboardPage;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogFeedPage;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogNoisyPage;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogTablesPage;
use Hilos\Pages\ChangeLog\DTO\HilosChangeLogReceiptReadActionDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Tables\ChangeLog\HilosChangeLogFeedTable;
use Hilos\Tables\ChangeLog\HilosChangeLogNoisyColumnsTable;
use Hilos\Tables\ChangeLog\HilosChangeLogTablesTable;
use Hilos\Testing\IntegrationTest;

require_once __DIR__ . '/BrowserContextAdminViewModeWireIntegrationTest.php';

/** Real journal schema stood before the rollback scope, with the existing standing environment. */
abstract class ChangeLogSectionPagesEnvironment extends BrowserContextAdminViewModeWireIntegrationEnvironment
{
    protected function prepareIntegrationEnvironment(): void
    {
        parent::prepareIntegrationEnvironment();
        $primary = Database::getConnectionConfig(0);
        Database::configure(1, $primary->host, $primary->user, $primary->password,
            ChangeLogDatabase::configuredName(), $primary->port, $primary->charset);
        $this->journalStub(true);
        $this->journalStub(false);
        ChangeLogSectionWireHilos::initBrowser(new ChangeLogSectionWireBrowser(false));
        Hilos::$db = new ChangeLogSectionWireDb();
        Hilos::$db->configure();
        Hilos::$table = new ChangeLogSectionWireTables();
        Hilos::$table->configure();
        Schema::reset();
        Schema::initialize();
    }

    protected function releaseIntegrationEnvironment(): void
    {
        try {
            $this->journalStub(true);
        } finally {
            parent::releaseIntegrationEnvironment();
        }
    }

    /** @param bool $down Whether to release the unchanged journal stub */
    private function journalStub(bool $down): void
    {
        $journal = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        if ($down) {
            foreach (['hilos_change_log_value', 'hilos_change_log_change', 'hilos_change_log',
                'hilos_change_log_receipt', 'hilos_change_log_field', 'hilos_change_log_table'] as $table) {
                Database::sqlRun("DROP TABLE IF EXISTS {$journal}.`{$table}`");
            }
            return;
        }
        Database::sqlRun(str_replace('{{change_log_database}}', $journal,
            (string)file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_hilos_change_log.sql')));
    }
}

/** Exercises the actual browser snapshot, one response, schema verdicts and relocated reading actions. */
#[IntegrationTest]
final class ChangeLogSectionPagesIntegrationTest extends ChangeLogSectionPagesEnvironment
{
    public function testDirectPagesAnswerOnceWithTheirOwnDataWindowsAndIdentity(): void
    {
        foreach ([false, true] as $viewer) {
            ChangeLogSectionWireHilos::initBrowser(new ChangeLogSectionWireBrowser($viewer));
            $feed = $this->subscribe(ChangeLogSectionWireFeedPage::class);
            self::assertSame(1, $feed->payload->data['changeLogOverview']['journalEntries']);
            self::assertContains('hilos_user', $feed->payload->data['changeLogOverview']['journaledTables']);
            self::assertSame('Feed', $feed->payload->data['pageLabel']);
            self::assertSame([HilosChangeLogFeedTable::TABLE], array_keys($feed->payload->windows));
            $row = $feed->payload->windows[HilosChangeLogFeedTable::TABLE]['rows'][0]['slots']['feed'];
            self::assertSame($viewer ? HiddenValue::mark() : 'Section actor', $row['actorLabel']);
            self::assertSame($viewer ? HiddenValue::mark() : 'Section subject', $row['subjectLabel']);
            $overview = $this->subscribe(ChangeLogSectionWireOverviewPage::class);
            self::assertSame([], $overview->payload->windows);
            self::assertArrayHasKey('changeLogOverview', $overview->payload->data);
            $tables = $this->subscribe(ChangeLogSectionWireTablesPage::class);
            self::assertArrayNotHasKey('changeLogNoisyColumns', $tables->payload->data);
            self::assertSame([HilosChangeLogTablesTable::TABLE], array_keys($tables->payload->windows));
            $noisy = $this->subscribe(ChangeLogSectionWireNoisyPage::class);
            self::assertSame('Noisy columns', $noisy->payload->data['pageLabel']);
            self::assertSame([HilosChangeLogNoisyColumnsTable::TABLE], array_keys($noisy->payload->windows));
            self::assertStringNotContainsString(HiddenValue::KEY, json_encode($noisy->payload->toArray(), JSON_THROW_ON_ERROR));
            self::assertNotEmpty($noisy->payload->data['changeLogNoisyColumns']);
            foreach (AbstractHilosChangeLogFeedPage::READING_ACTIONS as $action) {
                PageAccessGate::assertAction(ChangeLogSectionWireFeedPage::class, 'section-wire', $action);
            }
            $page = new ChangeLogSectionWireFeedPage($this->createStub(PageAgentInterface::class));
            $reply = $page->onAction('section-wire', HilosSignalConstants::HILOS_CHANGE_LOG_RECEIPT_READ,
                new HilosChangeLogReceiptReadActionDTO(1, null))->toArray();
            self::assertSame($viewer ? HiddenValue::mark() : 'Section actor', $reply['receipt']['actorLabel']);
            self::assertCount(1, $reply['entries']);
        }
        $this->expectException(ActionViewModeException::class);
        PageAccessGate::assertAction(ChangeLogSectionWireFeedPage::class, 'section-wire', 'undeclared_mutation');
    }

    protected function setUpInTransaction(): void
    {
        Database::sqlRun("INSERT INTO hilos_user (id, name, admin, block) VALUES (7, 'Section actor', 1, 0), (8, 'Section subject', 0, 0)");
        $journal = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        Database::sqlRun("INSERT INTO {$journal}.hilos_change_log_table (id, name) VALUES (1, 'hilos_user')");
        Database::sqlRun("INSERT INTO {$journal}.hilos_change_log_receipt"
            . " (id, created_at, actor_user_id, subject_user_id, channel, action) VALUES (1, '2026-10-27 10:00:00', 7, 8, 'web', 'user.update')");
        Database::sqlRun("INSERT INTO {$journal}.hilos_change_log"
            . " (id, created_at, receipt_id, table_id, record_key, mutation_type) VALUES (1, '2026-10-27 10:00:00', 1, 1, '[8]', 'update')");
    }

    /**
     * @param class-string<AbstractPage> $class Page
     * @return PageResponseSignalData The complete first reply
     */
    private function subscribe(string $class): PageResponseSignalData
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$sr->subscribeToPage($class::PAGE, new WebSocketPageSubscribeSignalDTO('section-wire', $class::PAGE, []));
        ExecutionContext::run(new ExecutionFrame(acceptKey: 'section-wire'), function () use ($class): void {
            new $class($this->createStub(PageAgentInterface::class))->onSubscribe('section-wire', new PageRouteParams([]));
        });
        $responses = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() === SignalTypeConstants::PAGE_RESPONSE) {
                $responses[] = $signal->data->data;
            }
        }
        self::assertCount(1, $responses);
        self::assertInstanceOf(PageResponseSignalData::class, $responses[0]);
        self::assertSame([], $responses[0]->payload->refusedWindows);
        return $responses[0];
    }
}

abstract class ChangeLogSectionWireHilos extends Hilos
{
    public const array PAGES = [
        ChangeLogSectionWireOverviewPage::PAGE => ChangeLogSectionWireOverviewPage::class,
        ChangeLogSectionWireFeedPage::PAGE => ChangeLogSectionWireFeedPage::class,
        ChangeLogSectionWireTablesPage::PAGE => ChangeLogSectionWireTablesPage::class,
        ChangeLogSectionWireNoisyPage::PAGE => ChangeLogSectionWireNoisyPage::class,
    ];

    public const array PAGE_TABLES = [
        ChangeLogSectionWireFeedPage::PAGE => [HilosChangeLogFeedTable::TABLE => []],
        ChangeLogSectionWireTablesPage::PAGE => [HilosChangeLogTablesTable::TABLE => []],
        ChangeLogSectionWireNoisyPage::PAGE => [HilosChangeLogNoisyColumnsTable::TABLE => []],
    ];
    protected const array FEATURES = [HilosFeature::CHANGE_LOG];

}

final class ChangeLogSectionWireDb extends HilosDbContext
{
    /** @return list<string> Fresh journal reads for the wire fixture */
    protected function processWideReadCollections(): array
    {
        return [...parent::processWideReadCollections(), self::changeLogTables, self::changeLogFields,
            self::changeLogReceipts, self::changeLogEntries, self::changeLogChanges, self::changeLogValues];
    }
}

final class ChangeLogSectionWireBrowser extends BrowserContext
{
    /** @param bool $viewer Whether this connection has the view verdict */
    public function __construct(private readonly bool $viewer)
    {
        parent::__construct();
    }

    /** @inheritDoc */
    public function isAdminViewModeViewer(string $pageClass, string $acceptKey): bool
    {
        return $this->viewer;
    }

    /** @inheritDoc */
    protected function resolveConnectionIdentity(string $acceptKey): ConnectionIdentity
    {
        return ConnectionIdentity::resolved(7);
    }
}

final class ChangeLogSectionWireTables extends TableContext
{
    public function configure(): void
    {
        $this->register(HilosChangeLogFeedTable::TABLE, new HilosChangeLogFeedTable());
        $this->register(HilosChangeLogTablesTable::TABLE, new HilosChangeLogTablesTable());
        $this->register(HilosChangeLogNoisyColumnsTable::TABLE, new HilosChangeLogNoisyColumnsTable());
    }
}

final class ChangeLogSectionWireOverviewPage extends AbstractHilosChangeLogDashboardPage
{
}

final class ChangeLogSectionWireFeedPage extends AbstractHilosChangeLogFeedPage
{
}

final class ChangeLogSectionWireTablesPage extends AbstractHilosChangeLogTablesPage
{
}

final class ChangeLogSectionWireNoisyPage extends AbstractHilosChangeLogNoisyPage
{
}
