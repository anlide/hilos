<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\DTO\TableViewportDeltaDTO;
use Hilos\Core\Table\DTO\TableWindowSignalData;
use Hilos\Core\Table\TableConstants;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\Schema\Schema;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Hilos;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Testing\IntegrationTest;
use Hilos\Tests\Fixtures\WireTestBrowser;
use Hilos\Tests\Fixtures\WireTestHilos;
use Hilos\Tests\Fixtures\WireTestRow;
use Hilos\Tests\Fixtures\WireTestTable;
use Hilos\Tests\Fixtures\WireTestTableContext;
use Hilos\Users\AccountStandingResolver;
use Hilos\Users\AccountStanding;
use Hilos\AdminViewMode\HiddenValue;
use Hilos\AdminViewMode\WireField;
use Hilos\Auth\AccountDeletion\AccountDeletionSettings;
use Hilos\Auth\AccountDeletion\AccountDeletionSettingsCatalog;
use Hilos\Backup\Anonymization\PiiRegistry;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserPageBindings;
use Hilos\Core\Browser\Config\BrowserPageConfig;
use Hilos\Core\Browser\Config\BrowserSourceConfig;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Object\Item\AccountDeletion as ObjectAccountDeletion;
use Hilos\Tables\Users\HilosUserDetailBrowserTable;
use Hilos\Users\DTO\AccountStandingStateSignalData;
use Throwable;

require_once __DIR__ . '/../Fixtures/BrowserContextAdminViewModeWireFixture.php';

/**
 * Unmarked environment owner: beside the parent's person table it stands the two tables the gate reads
 * an admin's standing from, keeps the facade and fixture state the wire case replaces, and proves the
 * rollback by SQL while the person's table still stands - whatever the case's outcome.
 */
abstract class BrowserContextAdminViewModeWireIntegrationEnvironment extends FrameworkRollbackIntegrationTestCase
{
    /** The admin the shared fixture browser puts behind every connection */
    protected const int USER_ID = 7;

    /** The standing tables beside the person's: the merge keeps a key onto the person, so it is dropped first */
    private const array TABLES = ['hilos_user_merge', 'hilos_account_deletion'];

    private ?BrowserContext $previousBrowser = null;
    /** @var class-string<Hilos> Facade class the previous browser was bound to */
    private string $previousAppClass = Hilos::class;
    private ?TableContext $previousTable = null;
    private ?SettingsAccessor $previousSetting = null;
    /** @var array<string, WireTestRow> Rows the shared table held before the case */
    private array $previousRows = [];
    /** @var array<string, string> Searched fields the shared table declared before the case */
    private array $previousSearchable = [];
    private bool $previousSettingName = false;
    /** Whether the parent's connection and schema came up, so this environment's own SQL may run */
    private bool $databaseReady = false;
    /** @var list<string> Successfully created standing tables, including partial setup */
    private array $createdTables = [];

    /** Keeps what the case replaces, then adds the standing tables to the parent's schema; no setting is mounted. */
    protected function prepareIntegrationEnvironment(): void
    {
        $this->previousBrowser = Hilos::$browser;
        $this->previousAppClass = Hilos::appClass();
        $this->previousTable = Hilos::$table;
        $this->previousSetting = Hilos::$setting;
        $this->previousRows = WireTestTable::$rows;
        $this->previousSearchable = WireTestTable::$searchable;
        $this->previousSettingName = WireTestTable::$settingName;
        parent::prepareIntegrationEnvironment();
        $this->databaseReady = true;
        foreach (self::TABLES as $table) {
            self::runStub($table, false);
            $this->createdTables[] = $table;
        }
        Schema::reset();
        Schema::initialize();
        Hilos::$setting = null;
    }

    /**
     * Proves the rollback before any DROP, drops the standing tables in reverse, restores what the case
     * replaced, and lets the parent release in every outcome; the first failure is reported after all of it ran.
     */
    protected function releaseIntegrationEnvironment(): void
    {
        $failures = [];
        AccountStandingResolver::forget(self::USER_ID);
        if ($this->databaseReady) {
            try {
                Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
                // A dropped table would prove nothing about the rollback: the person's row is asked for while its table stands.
                self::assertSame([], Database::sql('SELECT id FROM hilos_user WHERE id = ?', [self::USER_ID])->rows());
            } catch (Throwable $failure) {
                $failures[] = $failure;
            }
            foreach (array_reverse($this->createdTables) as $table) {
                try {
                    self::runStub($table, true);
                } catch (Throwable $failure) {
                    $failures[] = $failure;
                }
            }
        }
        $this->createdTables = [];
        $this->databaseReady = false;
        try {
            $this->restoreFacades();
        } catch (Throwable $failure) {
            $failures[] = $failure;
        } finally {
            try {
                parent::releaseIntegrationEnvironment();
            } catch (Throwable $failure) {
                $failures[] = $failure;
            }
        }
        if ($failures !== []) {
            throw $failures[0];
        }
    }

    /** Puts back the facade and fixture state; the browser is bound again through the facade it was bound to. */
    private function restoreFacades(): void
    {
        WireTestTable::$rows = $this->previousRows;
        WireTestTable::$searchable = $this->previousSearchable;
        WireTestTable::$settingName = $this->previousSettingName;
        Hilos::$table = $this->previousTable;
        Hilos::$setting = $this->previousSetting;
        $appClass = $this->previousAppClass;
        if ($this->previousBrowser === null) {
            $appClass::initBrowser();
            $appClass::resetBrowser();
        } else {
            $appClass::initBrowser($this->previousBrowser);
        }
    }

    /**
     * @param string $table Existing framework stub name, unchanged
     * @param bool $down Whether to execute its existing drop statement
     */
    private static function runStub(string $table, bool $down): void
    {
        $name = 'create_' . $table;
        if ($down) {
            $name .= '_down';
        }
        Database::sqlRun((string)file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/' . $name . '.sql'));
    }
}

/**
 * Pins what an admin receives over the wire of a typed table - the rows byte for byte as before - on the
 * path the unit class cannot take: the page gate reads the admin's standing out of the database (HIL-1564).
 *
 * The rows are the shared fixture's, in memory; the only persisted thing is the person the gate judges,
 * inserted inside the rollback scope before any ORM read. The product writes nothing here, so the
 * person is read back by independent SQL before and after every scenario and found unchanged, and the
 * environment finds the row gone once the scope is rolled back.
 */
#[IntegrationTest]
final class BrowserContextAdminViewModeWireIntegrationTest extends BrowserContextAdminViewModeWireIntegrationEnvironment
{
    private const string ACCEPT_KEY = 'ak-1';

    public function testAnAdminReceivesTheRowsExactlyAsBefore(): void
    {
        $browser = $this->boot();

        $viewport = $this->sendWindow($browser, TableSortOrderDTO::of(new TableSortDTO('name')));

        $window = $this->nextSignal(SignalTypeConstants::TABLE_WINDOW, TableWindowSignalData::class);
        $this->assertSame([self::plainRow('beta'), self::plainRow('alpha')], $window->rows);
        $this->assertSame(['name' => 'Anna', 'key' => 'beta'], $window->firstAnchor?->values);
        $this->assertNull($viewport->shownFields);
        $this->assertSame('name', $viewport->sort?->last()->field);
        $this->assertPersistedStanding();
    }

    public function testAnAdminStillSearchesEveryDeclaredField(): void
    {
        $browser = $this->boot(searchable: ['name' => 'name']);

        $this->sendWindow($browser, null, 'Anna');

        $window = $this->nextSignal(SignalTypeConstants::TABLE_WINDOW, TableWindowSignalData::class);
        $this->assertSame([self::plainRow('beta')], $window->rows);
        $this->assertPersistedStanding();
    }

    public function testAnEditOfAHiddenFieldStillReachesAnAdmin(): void
    {
        $browser = $this->boot();
        $this->sendWindow($browser, null);
        $this->nextSignal(SignalTypeConstants::TABLE_WINDOW, TableWindowSignalData::class);

        WireTestTable::$rows['alpha'] = WireTestRow::sample('alpha', name: 'Oksana');
        $browser->record(SourceChange::dbUpdated(WireTestTable::SOURCE_KEY, 'alpha', ['name' => 'Oksana']));
        $browser->flushToSignalRouter();

        $delta = $this->nextSignal(SignalTypeConstants::TABLE_VIEWPORT_DELTA, TableViewportDeltaDTO::class);
        $this->assertSame(self::plainRow('alpha', name: 'Oksana'), $delta->row);
        $this->assertPersistedStanding();
    }

    public function testAViewerReceivesTheHistoricalDeletionDurationInSnapshotAndLiveRows(): void
    {
        Database::sqlRun(
            'INSERT INTO hilos_account_deletion (user_id, requested_at, effective_at) VALUES (?, ?, ?)',
            [self::USER_ID, '2026-01-01 12:00:00', '2026-01-31 12:00:00'],
        );
        Hilos::$setting = new SettingsAccessor(DeletionCardHiddenSettingsCatalog::class);
        Hilos::$sr = new SignalRouter();
        Hilos::$table = null;
        $params = new PageRouteParams([HilosPageRouteParams::HILOS_USER_USER_ID => self::USER_ID]);
        Hilos::$sr->subscribeToPage(
            WireTestBrowser::PAGE,
            new WebSocketPageSubscribeSignalDTO('viewer-card', WireTestBrowser::PAGE, $params->toArray()),
        );
        $browser = new DeletionCardWireBrowser();
        WireTestHilos::initBrowser($browser);
        $snapshot = $browser->buildSubscribeSnapshot(WireTestBrowser::PAGE, 'viewer-card', $params);
        $rows = $snapshot->tables[HilosUserDetailBrowserTable::TABLE][PagePayload::rows];
        self::assertCount(1, $rows);
        $deletion = $rows[0][PagePayload::slots][HilosDbContext::accountDeletions];
        self::assertSame(30, $deletion['deletionGraceDays']);
        self::assertSame(1769860800000, $deletion['deletionEffectiveAt']);
        self::assertSame(HiddenValue::mark(), $deletion['requestedAt']);
        self::assertSame(HiddenValue::mark(), $deletion['effectiveAt']);

        $standing = AccountStandingResolver::of(self::USER_ID)->toArray();
        $hidden = $browser->hideForViewer(
            ['accountStanding' => $standing, 'accountDeletionGraceDays' => 7, 'name' => 'Wire admin'],
            [
                'accountStanding' => WireField::each(AccountStanding::wireFields()),
                'accountDeletionGraceDays' => WireField::setting(AccountDeletionSettings::GRACE_DAYS_KEY),
                'name' => WireField::column(HilosDbContext::users, 'name'),
            ],
        );
        self::assertSame(30, $hidden['accountStanding']['deletionGraceDays']);
        self::assertSame(1769860800000, $hidden['accountStanding']['deletionEffectiveAt']);
        self::assertSame(HiddenValue::mark(), $hidden['accountDeletionGraceDays']);
        self::assertSame(HiddenValue::mark(), $hidden['name']);
        $liveStanding = new AccountStandingStateSignalData(self::USER_ID, $standing);
        self::assertSame(
            $hidden['accountStanding'],
            $browser->hideForViewer($liveStanding->toArray(), AccountStandingStateSignalData::wireFields())['accountStanding'],
        );

        $browser->record(SourceChange::dbUpdated(HilosDbContext::accountDeletions, '1', ['effective_at' => '2026-01-31 12:00:00']));
        $browser->flushToSignalRouter();
        $live = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($live);
        self::assertInstanceOf(WebSocketSignalData::class, $live->data);
        self::assertSame(SignalTypeConstants::PAGE_RESPONSE, $live->signalName->getName());
        self::assertInstanceOf(PageResponseSignalData::class, $live->data->data);
        $liveRows = $live->data->data->payload->tables[HilosUserDetailBrowserTable::TABLE][PagePayload::rows];
        self::assertSame($rows, $liveRows);
    }

    /**
     * The person the gate judges, written by SQL before any ORM read warms the users collection; the
     * verdict remembered for them by an earlier case of this process is dropped, so the gate reads anew.
     */
    protected function setUpInTransaction(): void
    {
        Database::sqlRun("INSERT INTO hilos_user (id, name, admin, block) VALUES (7, 'Wire admin', 1, 0)");
        AccountStandingResolver::forget(self::USER_ID);
        $this->assertPersistedStanding();
    }

    /**
     * Boots the table, the page subscription and the shared fixture browser - an admin behind every
     * connection - over the real database layer the environment mounted, which it leaves as it is.
     *
     * @param array<string, string> $searchable Fields the table declares the search over
     * @return WireTestBrowser Booted browser context, also mounted on the facade
     */
    private function boot(array $searchable = ['seen' => 'seen']): WireTestBrowser
    {
        Hilos::$sr = new SignalRouter();
        WireTestTable::$rows = [];
        foreach (['alpha', 'beta', 'gamma'] as $key) {
            WireTestTable::$rows[$key] = WireTestRow::sample($key);
        }
        WireTestTable::$searchable = $searchable;
        WireTestTable::$settingName = false;
        Hilos::$table = new WireTestTableContext();
        Hilos::$table->configure();
        Hilos::$sr->subscribeToPage(
            WireTestBrowser::PAGE,
            new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, WireTestBrowser::PAGE),
        );

        $browser = new WireTestBrowser(viewer: false);
        WireTestHilos::initBrowser($browser);

        return $browser;
    }

    /**
     * Asks for a window the way a tab's window frame does: held, then served through the gate.
     *
     * @param WireTestBrowser $browser Booted browser context
     * @param ?TableSortOrderDTO $sort Order the tab asks for
     * @param ?string $search Search term the tab types, or null for none
     * @return TableViewportSubscription The window the connection holds
     */
    private function sendWindow(WireTestBrowser $browser, ?TableSortOrderDTO $sort, ?string $search = null): TableViewportSubscription
    {
        $viewport = $browser->viewportForViewer(
            WireTestBrowser::PAGE,
            self::ACCEPT_KEY,
            new TableViewportSubscription(
                tableKey: WireTestTable::TABLE,
                filter: $search === null ? [] : [TableConstants::FILTER_KEY_SEARCH => $search],
                sort: $sort,
                limit: 2,
            ),
        );
        Hilos::$sr?->setTableViewport(self::ACCEPT_KEY, $viewport);
        // A false here hides its cause: sendTableWindow contains any failure of the gate and logs
        // "Browser window skipped a broken declaration" instead.
        $this->assertTrue($browser->sendTableWindow(WireTestBrowser::PAGE, self::ACCEPT_KEY, $viewport));

        return $viewport;
    }

    /**
     * Asserts the next queued signal is the named frame to the connection and returns its payload.
     *
     * @template T of SignalDataInterface
     * @param string $name Signal name expected
     * @param class-string<T> $class Payload class expected
     * @return T The payload
     */
    private function nextSignal(string $name, string $class): SignalDataInterface
    {
        $signal = Hilos::$sr?->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertSame($name, $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertSame(self::ACCEPT_KEY, $signal->data->targetAcceptKey);
        $this->assertInstanceOf($class, $signal->data->data);

        return $signal->data->data;
    }

    /** The fixture as the database holds it: one admin, neither merged nor scheduled for erasure, read past the ORM. */
    private function assertPersistedStanding(): void
    {
        $this->assertSame(
            [['id' => '7', 'name' => 'Wire admin', 'admin' => '1', 'block' => '0']],
            Database::sql('SELECT id, name, admin, block FROM hilos_user WHERE id = ?', [self::USER_ID])->rows(),
        );
        $this->assertSame([], Database::sql('SELECT user_id FROM hilos_user_merge WHERE user_id = ?', [self::USER_ID])->rows());
        $this->assertSame([], Database::sql('SELECT user_id FROM hilos_account_deletion WHERE user_id = ?', [self::USER_ID])->rows());
    }

    /**
     * @param string $key Sample row key
     * @param ?string $seen Last activity, or null for the sample's own
     * @param ?string $name Name, or null for the sample's own
     * @return array<string, mixed> The wire row an admin receives
     */
    private static function plainRow(string $key, ?string $seen = null, ?string $name = null): array
    {
        $row = WireTestRow::sample($key, $seen, $name);

        return [
            PagePayload::rowKey => $key,
            PagePayload::slots => WireTestTable::slotsOf($row),
        ];
    }
}

/** A project may keep its current grace setting hidden; the request's duration stays open. */
final class DeletionCardHiddenSettingsCatalog implements CatalogProviderInterface
{
    /** @return array<string, array<string, mixed>> Framework fragment with this project's visibility choice */
    public static function getCatalog(): array
    {
        $catalog = AccountDeletionSettingsCatalog::getCatalog();
        $catalog[AccountDeletionSettings::GRACE_DAYS_KEY][SettingsCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE] = false;

        return $catalog;
    }
}

/** Projects the real card's deletion row through the declarative browser and viewer bridge. */
final class DeletionCardWireBrowser extends BrowserContext
{
    /**
     * @param string $pageClass Page being viewed
     * @param string $acceptKey Viewer connection
     * @return bool Viewer verdict
     */
    public function isAdminViewModeViewer(string $pageClass, string $acceptKey): bool
    {
        return true;
    }

    /** @return ?PiiRegistry Real mounted Entity verdicts, including the deletion table's PURGE */
    protected function viewerPiiRegistry(): ?PiiRegistry
    {
        return PiiRegistry::collect();
    }

    /**
     * @param string $page Subscribed page
     * @return ?BrowserPageConfig Shared fixture page metadata
     */
    protected function resolveBrowserPageConfig(string $page): ?BrowserPageConfig
    {
        return BrowserPageConfig::fromArray([BrowserConfigKey::SIGNAL => WireTestBrowser::SIGNAL]);
    }

    /**
     * @param string $page Subscribed page
     * @return BrowserPageBindings Real card binding
     */
    protected function resolveBrowserPageBindings(string $page): BrowserPageBindings
    {
        return BrowserPageBindings::fromArray([HilosUserDetailBrowserTable::TABLE => HilosUserDetailBrowserTable::BINDING]);
    }

    /**
     * @param string $browserKey Requested table
     * @return ?BrowserSourceConfig Real deletion row with raw dates to prove their masking
     */
    protected function resolveBrowserOnlyConfig(string $browserKey): ?BrowserSourceConfig
    {
        if ($browserKey !== HilosUserDetailBrowserTable::TABLE) {
            return null;
        }
        $config = HilosUserDetailBrowserTable::BROWSER;
        $rows = array_values(array_filter(
            $config[BrowserTableConfigKey::ROWS],
            static fn (array $row): bool => $row[BrowserTableFieldKey::SOURCE][BrowserSourceKey::KEY] === HilosDbContext::accountDeletions,
        ));
        $rows[0][BrowserTableFieldKey::FIELDS][] = ObjectAccountDeletion::requestedAt;
        $rows[0][BrowserTableFieldKey::FIELDS][] = ObjectAccountDeletion::effectiveAt;

        return BrowserSourceConfig::fromArray([
            BrowserTableConfigKey::PARAMS => $config[BrowserTableConfigKey::PARAMS],
            BrowserTableConfigKey::SOURCES => [$rows[0][BrowserTableFieldKey::SOURCE]],
            BrowserTableConfigKey::ROWS => $rows,
        ]);
    }
}
