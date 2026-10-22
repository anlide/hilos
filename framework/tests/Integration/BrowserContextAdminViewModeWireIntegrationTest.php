<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Page\DTO\PagePayload;
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
use Hilos\Hilos;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Testing\IntegrationTest;
use Hilos\Tests\Fixtures\WireTestBrowser;
use Hilos\Tests\Fixtures\WireTestHilos;
use Hilos\Tests\Fixtures\WireTestRow;
use Hilos\Tests\Fixtures\WireTestTable;
use Hilos\Tests\Fixtures\WireTestTableContext;
use Hilos\Users\AccountStandingResolver;
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
