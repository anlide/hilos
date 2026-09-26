<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserPageBindings;
use Hilos\Core\Browser\Config\BrowserPageConfig;
use Hilos\Core\Browser\Config\BrowserSourceConfig;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\Table\Definition\SelfSnapshotTable;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableFacetCountDTO;
use Hilos\Core\Table\DTO\TableFacetCountsSignalData;
use Hilos\Core\Table\DTO\TableProgressDTO;
use Hilos\Core\Table\DTO\TableProgressSignalData;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\DTO\TableViewportDeltaDTO;
use Hilos\Core\Table\DTO\TableViewportFrozenSignalData;
use Hilos\Core\Table\DTO\TableWindowSignalData;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\Table\TableFacetTally;
use Hilos\Core\Table\TableProgressScope;
use Hilos\Hilos;
use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Collection\RtCollection;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\RtItem;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Utils\Helpers\TimeHelper;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A table whose live changes stopped arriving says so, instead of freezing on rows that look live (HIL-1139).
 *
 * Until this leaf the live road of a window failed in silence: a change the table could not
 * build left the window on its old rows with a line in the log, and a throw one step later took
 * the whole page down with a page error. Now the one window freezes — a line in the log and ONE
 * table_viewport_frozen frame to the one connection — while the page, its lists and every
 * neighboring table go on living; and the first delivery that reaches the window without a
 * throw brings it the whole window instead of a delta, as a window after a broken socket.
 *
 * What pulls against itself here is the same as for a page that failed (HIL-575): the
 * connection must be told, it must be told once, and the recovery must be whole. A frozen
 * window is also left alone for the rest of the flush that froze it — a delivery built in the
 * same flush as the failure is no proof the road is back.
 */
final class BrowserContextTableFreezeTest extends TestCase
{
    /** Temporary main log file the assertions read the written lines back from */
    private string $logFile = '';

    /** @var list<array{0: string, 1: mixed}> Signals taken off the router since the last drain, by name and payload */
    private array $taken = [];

    protected function setUp(): void
    {
        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'hilos-table-freeze-log');
        Logger::setLogFile($this->logFile);

        Hilos::$sr = new SignalRouter();
        Hilos::$rt = new FreezeUnitRtContext();
        Hilos::$rt->configure();
        Hilos::$rt->addRow(FreezeUnitState::create('a', 'Alpha'));
        Hilos::$rt->addRow(FreezeUnitState::create('b', 'Beta'));
        Hilos::$sr->subscribeToPage(
            FreezeUnitContext::PAGE,
            new WebSocketPageSubscribeSignalDTO('ak-1', FreezeUnitContext::PAGE),
        );
    }

    protected function tearDown(): void
    {
        Logger::resetLogFile();
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }

        Hilos::$sr = null;
        Hilos::$rt = null;
        Hilos::$table = null;
        Hilos::resetBrowser();

        parent::tearDown();
    }

    public function testAChangeTheTableCannotBuildFreezesTheWindowWithOneFrame(): void
    {
        $table = $this->boot(failOn: ['a']);

        $before = TimeHelper::nowMs();
        $this->flushChange('a');

        $frozen = $this->payloadsNamed(SignalTypeConstants::TABLE_VIEWPORT_FROZEN);
        $this->assertCount(1, $frozen);
        $this->assertInstanceOf(TableViewportFrozenSignalData::class, $frozen[0]);
        $this->assertSame(FreezeUnitContext::PAGE, $frozen[0]->page);
        $this->assertSame($table::TABLE, $frozen[0]->tableKey);
        $this->assertGreaterThanOrEqual($before, $frozen[0]->since);
        $this->assertTrue(Hilos::$sr?->isTableViewportFrozen('ak-1', $table::TABLE));
    }

    /**
     * The defect is in the table and is there on every flush that reaches it; a frame per flush
     * would tell the connection the same thing ten times a second.
     */
    public function testTheSecondFailureOfTheSameWindowSaysNothing(): void
    {
        $this->boot(failOn: ['a']);
        $this->flushChange('a');
        $this->drain();

        $this->flushChange('a');

        $this->assertSame([], $this->payloadsNamed(SignalTypeConstants::TABLE_VIEWPORT_FROZEN));
        $this->assertSame([], $this->payloadsNamed(SignalTypeConstants::TABLE_VIEWPORT_DELTA));
    }

    /**
     * The delta this change would carry is judged against rows the connection was never brought up
     * to date on, so the whole window goes instead — rows, counts, and the counts beside the filters.
     */
    public function testTheFirstGoodDeliveryAfterAFreezeIsTheWholeWindowAndItsFacetCounts(): void
    {
        $table = $this->boot(failOn: ['a']);
        Hilos::$sr?->setTableFacets('ak-1', $table::TABLE, [FreezeUnitTable::FILTER_LABEL => ['Alpha', 'Beta']]);
        $this->flushChange('a');
        $this->drain();

        $table->failOn = [];
        $this->flushChange('b', 'Beta renamed');

        $queued = $this->queued();
        $names = array_column($queued, 0);
        $this->assertSame(
            [SignalTypeConstants::TABLE_WINDOW, SignalTypeConstants::TABLE_FACET_COUNTS],
            array_values(array_filter($names, static fn(string $name): bool => str_starts_with($name, 'table_'))),
        );
        $window = $queued[array_search(SignalTypeConstants::TABLE_WINDOW, $names, true)][1];
        $this->assertInstanceOf(TableWindowSignalData::class, $window);
        $this->assertSame($table::TABLE, $window->tableKey);
        $this->assertSame(['a', 'b'], array_map(static fn(array $row): string => (string) $row[PagePayload::rowKey], $window->rows));
        $this->assertSame(2, $window->totalCount);
        $facets = $queued[array_search(SignalTypeConstants::TABLE_FACET_COUNTS, $names, true)][1];
        $this->assertInstanceOf(TableFacetCountsSignalData::class, $facets);
        $this->assertSame($table::TABLE, $facets->tableKey);
        $this->assertFalse(Hilos::$sr?->isTableViewportFrozen('ak-1', $table::TABLE));
    }

    public function testOnceCaughtUpTheWindowGetsDeltasAgain(): void
    {
        $table = $this->boot(failOn: ['a']);
        $this->flushChange('a');
        $table->failOn = [];
        $this->flushChange('b', 'Beta renamed');
        $this->drain();

        $this->flushChange('a', 'Alpha renamed');

        $this->assertSame([], $this->payloadsNamed(SignalTypeConstants::TABLE_WINDOW));
        $deltas = $this->payloadsNamed(SignalTypeConstants::TABLE_VIEWPORT_DELTA);
        $this->assertCount(1, $deltas);
        $this->assertInstanceOf(TableViewportDeltaDTO::class, $deltas[0]);
        $this->assertSame('a', (string) $deltas[0]->rowKey);
    }

    public function testAFreshnessMoveTheTableCannotBuildFreezesTheWindowToo(): void
    {
        $table = $this->boot(failOn: ['a']);

        $context = new FreezeUnitContext();
        $context->recordSourceStaleness(FreezeUnitRtContext::ROWS, ['a']);
        $context->flushToSignalRouter();

        $frozen = $this->payloadsNamed(SignalTypeConstants::TABLE_VIEWPORT_FROZEN);
        $this->assertCount(1, $frozen);
        $this->assertInstanceOf(TableViewportFrozenSignalData::class, $frozen[0]);
        $this->assertSame($table::TABLE, $frozen[0]->tableKey);
        $this->assertStringContainsString('Viewport staleness skipped a row the table failed to build', $this->writtenLog());
    }

    public function testAFreshnessMoveTheTableBuildsCatchesAFrozenWindowUp(): void
    {
        $table = $this->boot(failOn: ['a']);
        $this->flushChange('a');
        $this->drain();

        $table->failOn = [];
        $context = new FreezeUnitContext();
        $context->recordSourceStaleness(FreezeUnitRtContext::ROWS, ['b']);
        $context->flushToSignalRouter();

        $this->assertCount(1, $this->payloadsNamed(SignalTypeConstants::TABLE_WINDOW));
        $this->assertFalse(Hilos::$sr?->isTableViewportFrozen('ak-1', $table::TABLE));
    }

    /**
     * A throw one step past the build — here the row the table built carries no key — used to
     * reach the subscription's trap and take the whole page down. It freezes this one window
     * now, and the neighboring table and the list of the same page get their own.
     */
    public function testAThrowFurtherDownTheWindowsRoadFreezesOnlyThatWindow(): void
    {
        $table = $this->boot(keyless: true, withNeighbor: true);

        $this->flushChange('a', 'Alpha renamed');

        $this->assertSame([], $this->payloadsNamed(SignalConstants::SUBSCRIPTION_PAGE_ERROR));
        $frozen = $this->payloadsNamed(SignalTypeConstants::TABLE_VIEWPORT_FROZEN);
        $this->assertCount(1, $frozen);
        $this->assertInstanceOf(TableViewportFrozenSignalData::class, $frozen[0]);
        $this->assertSame($table::TABLE, $frozen[0]->tableKey);

        $deltas = $this->payloadsNamed(SignalTypeConstants::TABLE_VIEWPORT_DELTA);
        $this->assertCount(1, $deltas);
        $this->assertInstanceOf(TableViewportDeltaDTO::class, $deltas[0]);
        $this->assertSame(FreezeUnitTable::NEIGHBOR, $deltas[0]->tableKey);

        $responses = $this->payloadsNamed(SignalTypeConstants::PAGE_RESPONSE);
        $this->assertCount(1, $responses);
        $this->assertInstanceOf(PageResponseSignalData::class, $responses[0]);
        $this->assertArrayHasKey(FreezeUnitContext::LIST, $responses[0]->payload->tables);

        $log = $this->writtenLog();
        $this->assertStringContainsString('Viewport fan-out froze a window whose live road failed', $log);
        $this->assertStringContainsString('table=' . $table::TABLE, $log);
        $this->assertStringContainsString('acceptKey=ak-1', $log);
        $this->assertStringContainsString('exception=' . TableRowKeyMissingException::class, $log);
    }

    /**
     * The containment is the window's alone: a list of the same page that throws is still a page
     * that could not be delivered, as it always was.
     */
    public function testAThrowInAListOfTheSamePageStillFailsThePage(): void
    {
        $this->boot();

        $context = new FreezeUnitContext(brokenList: true);
        $context->record(SourceChange::rtUpdated(FreezeUnitRtContext::ROWS, 'a', ['name' => 'Alpha renamed']));
        $context->flushToSignalRouter();

        $this->assertCount(1, $this->payloadsNamed(SignalConstants::SUBSCRIPTION_PAGE_ERROR));
        $this->assertSame([], $this->payloadsNamed(SignalTypeConstants::TABLE_VIEWPORT_FROZEN));
    }

    /**
     * A window that cannot be built sends nothing, not even a refusal: the rows on the screen are
     * still the frozen ones and are still said to be, and the next delivery tries again.
     */
    public function testACatchUpWhoseWindowCannotBeBuiltSendsNothingAndKeepsTheMark(): void
    {
        $table = $this->boot(failOn: ['a']);
        $this->flushChange('a');
        $this->drain();

        $table->failOn = [];
        $table->failQuery = true;
        $this->flushChange('b', 'Beta renamed');

        $this->assertSame([], array_filter(
            array_column($this->queued(), 0),
            static fn(string $name): bool => str_starts_with($name, 'table_'),
        ));
        $this->assertTrue(Hilos::$sr?->isTableViewportFrozen('ak-1', $table::TABLE));

        $table->failQuery = false;
        $this->flushChange('b', 'Beta renamed again');

        $this->assertCount(1, $this->payloadsNamed(SignalTypeConstants::TABLE_WINDOW));
    }

    /**
     * A table that refused the catch-up once refuses it for every later change of the same flush;
     * trying again for each would be a full query and a line in the log per change.
     */
    public function testACatchUpThatCannotBeBuiltIsNotTriedAgainInTheSameFlush(): void
    {
        $table = $this->boot(failOn: ['a']);
        $this->flushChange('a');
        $this->drain();
        $table->failOn = [];
        $table->failQuery = true;
        $logged = substr_count($this->writtenLog(), 'Browser window skipped a table that failed to build');

        $context = new FreezeUnitContext();
        $context->record(SourceChange::rtUpdated(FreezeUnitRtContext::ROWS, 'a', ['name' => 'Alpha renamed']));
        $context->record(SourceChange::rtUpdated(FreezeUnitRtContext::ROWS, 'b', ['name' => 'Beta renamed']));
        $context->flushToSignalRouter();

        $this->assertSame(
            $logged + 1,
            substr_count($this->writtenLog(), 'Browser window skipped a table that failed to build'),
        );
        $this->assertSame([], $this->payloadsNamed(SignalTypeConstants::TABLE_VIEWPORT_DELTA));
        $this->assertTrue(Hilos::$sr?->isTableViewportFrozen('ak-1', $table::TABLE));
    }

    /**
     * A delivery built in the same flush as the failure is no proof the road is back, so the
     * window is not caught up, and not sent a delta either, before the flush ends.
     */
    public function testAGoodChangeInTheFlushThatFrozeTheWindowSendsItNothing(): void
    {
        $this->boot(failOn: ['a']);

        $context = new FreezeUnitContext();
        $context->record(SourceChange::rtUpdated(FreezeUnitRtContext::ROWS, 'a', ['name' => 'Alpha renamed']));
        $context->record(SourceChange::rtUpdated(FreezeUnitRtContext::ROWS, 'b', ['name' => 'Beta renamed']));
        $context->flushToSignalRouter();

        $tableFrames = array_values(array_filter(
            array_column($this->queued(), 0),
            static fn(string $name): bool => str_starts_with($name, 'table_'),
        ));
        $this->assertSame([SignalTypeConstants::TABLE_VIEWPORT_FROZEN], $tableFrames);
    }

    /**
     * A window request and its refusal both replace the rows the client holds, so either of them
     * thaws the window; the next change is an ordinary delta again.
     */
    public function testAWindowRequestThawsTheWindow(): void
    {
        $table = $this->boot(failOn: ['a']);
        $this->flushChange('a');
        $this->drain();
        $table->failOn = [];

        $viewport = Hilos::$sr?->getTableViewport('ak-1', $table::TABLE);
        $this->assertNotNull($viewport);
        $delivered = (new FreezeUnitContext())->sendTableWindow(FreezeUnitContext::PAGE, 'ak-1', $viewport);

        $this->assertTrue($delivered);
        $this->assertFalse(Hilos::$sr?->isTableViewportFrozen('ak-1', $table::TABLE));
        $this->drain();

        $this->flushChange('a', 'Alpha renamed');

        $this->assertSame([], $this->payloadsNamed(SignalTypeConstants::TABLE_WINDOW));
        $this->assertCount(1, $this->payloadsNamed(SignalTypeConstants::TABLE_VIEWPORT_DELTA));
    }

    public function testARefusedWindowRequestThawsTheWindowToo(): void
    {
        $table = $this->boot(failOn: ['a']);
        $this->flushChange('a');
        $this->drain();
        $table->failQuery = true;

        $viewport = Hilos::$sr?->getTableViewport('ak-1', $table::TABLE);
        $this->assertNotNull($viewport);
        $delivered = (new FreezeUnitContext())->sendTableWindow(FreezeUnitContext::PAGE, 'ak-1', $viewport);

        $this->assertFalse($delivered);
        $this->assertCount(1, $this->payloadsNamed(SignalTypeConstants::TABLE_WINDOW_REFUSED));
        $this->assertFalse(Hilos::$sr?->isTableViewportFrozen('ak-1', $table::TABLE));
    }

    /**
     * The numbers of a frozen window arrive with its catch-up, beside the rows they count; a
     * recount at the end of the flush that froze it would move a total under rows it no longer
     * describes.
     */
    public function testAWindowFrozenInThisFlushGetsNoRecountedTotal(): void
    {
        $table = $this->boot(failOn: ['a'], search: 'a');

        // Row z is outside the window and its edit leaves the total to a recount at the end of the
        // flush; row a then freezes the window before the flush gets there.
        $context = new FreezeUnitContext();
        $context->record(SourceChange::rtUpdated(FreezeUnitRtContext::ROWS, 'z', ['name' => 'Zeta alpha']));
        $context->record(SourceChange::rtUpdated(FreezeUnitRtContext::ROWS, 'a', ['name' => 'Alpha renamed']));
        $context->flushToSignalRouter();

        $this->assertCount(1, $this->payloadsNamed(SignalTypeConstants::TABLE_VIEWPORT_FROZEN));
        $this->assertSame([], $this->payloadsNamed(SignalTypeConstants::TABLE_VIEWPORT_COUNT));
        $this->assertSame(7, Hilos::$sr?->getTableViewport('ak-1', $table::TABLE)?->totalCount());
    }

    /**
     * A bar is not part of the window: work running on the table keeps being shown while its rows
     * are frozen, and a bar alone does not catch the window up.
     */
    public function testAFrozenWindowStillGetsItsProgress(): void
    {
        $table = $this->boot(failOn: ['a']);
        $this->flushChange('a');
        $this->drain();

        $context = new FreezeUnitContext();
        $context->record(SourceChange::rtUpdated(FreezeUnitTable::PROGRESS_SOURCE, 'run-1', []));
        $context->flushToSignalRouter();

        $bars = $this->payloadsNamed(SignalTypeConstants::TABLE_PROGRESS);
        $this->assertCount(1, $bars);
        $this->assertInstanceOf(TableProgressSignalData::class, $bars[0]);
        $this->assertSame($table::TABLE, $bars[0]->tableKey);
        $this->assertTrue(Hilos::$sr?->isTableViewportFrozen('ak-1', $table::TABLE));
    }

    public function testTheChangeThatFreezesTheWindowStillCarriesItsProgress(): void
    {
        $table = $this->boot(failOn: ['a']);
        $table->progressOn = ['a'];

        $this->flushChange('a');

        $this->assertCount(1, $this->payloadsNamed(SignalTypeConstants::TABLE_VIEWPORT_FROZEN));
        $this->assertCount(1, $this->payloadsNamed(SignalTypeConstants::TABLE_PROGRESS));
    }

    /**
     * Boots the table registry and the connection's window over rows a and b.
     *
     * @param list<string> $failOn Row keys whose mutation build throws
     * @param bool $keyless Whether the table builds rows that carry no key
     * @param bool $withNeighbor Whether a second, healthy table on the same page holds a window too
     * @param ?string $search Search the window narrows its set by, or null for none
     * @return FreezeUnitTable The table the window is on
     */
    private function boot(
        array $failOn = [],
        bool $keyless = false,
        bool $withNeighbor = false,
        ?string $search = null,
    ): FreezeUnitTable {
        $rows = [new FreezeUnitRow('a', 'Alpha'), new FreezeUnitRow('b', 'Beta')];
        $table = new FreezeUnitTable($rows, $failOn, $keyless);
        $neighbor = new FreezeUnitTable($rows);
        Hilos::$table = new FreezeUnitTableContext($table, $neighbor);
        Hilos::$table->configure();

        Hilos::$sr?->setTableViewport('ak-1', self::viewportOf(FreezeUnitTable::TABLE, $search));
        if ($withNeighbor) {
            Hilos::$sr?->setTableViewport('ak-1', self::viewportOf(FreezeUnitTable::NEIGHBOR, null));
        }

        return $table;
    }

    /**
     * A window holding rows a and b as placeholder bodies, so any edit of them reads as a change.
     *
     * @param string $tableKey Table the window is on
     * @param ?string $search Search the window narrows its set by, or null for none
     * @return TableViewportSubscription Window with rows a and b delivered
     */
    private static function viewportOf(string $tableKey, ?string $search): TableViewportSubscription
    {
        $viewport = new TableViewportSubscription(
            tableKey: $tableKey,
            filter: $search === null ? [] : [TableConstants::FILTER_KEY_SEARCH => $search],
            limit: 10,
        );
        $window = [];
        foreach (['a', 'b'] as $rowKey) {
            $window[$rowKey] = [PagePayload::rowKey => $rowKey, PagePayload::slots => []];
        }
        $viewport->recordWindow($window, 7, true, null, null);

        return $viewport;
    }

    /**
     * Records one edit of a row and flushes it through a fresh browser context.
     *
     * @param string $rowKey Row the edit is about
     * @param string $name New name the edit carries
     */
    private function flushChange(string $rowKey, string $name = 'Renamed'): void
    {
        $context = new FreezeUnitContext();
        $context->record(SourceChange::rtUpdated(FreezeUnitRtContext::ROWS, $rowKey, ['name' => $name]));
        $context->flushToSignalRouter();
    }

    /**
     * Every signal queued since the last drain, in the order they were queued.
     *
     * @return list<array{0: string, 1: mixed}> Signal name and its addressed payload
     */
    private function queued(): array
    {
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
            $this->assertSame('ak-1', $signal->data->targetAcceptKey);
            $this->taken[] = [$signal->signalName->getName(), $signal->data->data];
        }

        return $this->taken;
    }

    /**
     * The payloads of one name among the signals queued since the last drain.
     *
     * @param string $name Signal name to keep
     * @return list<mixed> Payloads of that name, in the order they were queued
     */
    private function payloadsNamed(string $name): array
    {
        $payloads = [];
        foreach ($this->queued() as [$queuedName, $payload]) {
            if ($queuedName === $name) {
                $payloads[] = $payload;
            }
        }

        return $payloads;
    }

    /**
     * Empties the signal queue so the next assertion reads only what the next flush queued.
     */
    private function drain(): void
    {
        $this->queued();
        $this->taken = [];
    }

    /**
     * Reads back the journal written since the test started.
     *
     * @return string Written journal, empty when it stayed silent
     */
    private function writtenLog(): string
    {
        $this->assertFileExists($this->logFile);

        return (string) file_get_contents($this->logFile);
    }
}

/**
 * Serves one page with a window table, a neighboring window table and a list over one runtime collection.
 */
final class FreezeUnitContext extends BrowserContext
{
    public const string PAGE = 'table_freeze_page';
    public const string LIST = 'tableFreezeList';

    private const string SIGNAL = 'table_freeze_signal';

    /**
     * @param bool $brokenList Whether the list's declaration refuses to be read
     */
    public function __construct(private readonly bool $brokenList = false)
    {
        parent::__construct();
    }

    /**
     * @param string $page Page name from the subscription mirror
     * @return ?BrowserPageConfig Test page metadata, or null when absent
     * @throws PageInternalErrorException When a page or source declaration is malformed
     */
    protected function resolveBrowserPageConfig(string $page): ?BrowserPageConfig
    {
        if ($page !== self::PAGE) {
            return null;
        }

        return BrowserPageConfig::fromArray([BrowserConfigKey::SIGNAL => self::SIGNAL]);
    }

    /**
     * @param string $page Page name from the subscription mirror
     * @return BrowserPageBindings Test page table bindings
     */
    protected function resolveBrowserPageBindings(string $page): BrowserPageBindings
    {
        return $page === self::PAGE
            ? BrowserPageBindings::fromArray([FreezeUnitTable::TABLE => [], FreezeUnitTable::NEIGHBOR => [], self::LIST => []])
            : BrowserPageBindings::empty();
    }

    /**
     * @param string $browserKey Browser table key
     * @return ?BrowserSourceConfig Test list config
     * @throws PageInternalErrorException When the fixture was asked for a broken list
     */
    protected function resolveBrowserOnlyConfig(string $browserKey): ?BrowserSourceConfig
    {
        if ($browserKey !== self::LIST) {
            return null;
        }
        if ($this->brokenList) {
            throw new PageInternalErrorException('the list declaration is broken');
        }

        return BrowserSourceConfig::fromArray([
            BrowserTableConfigKey::ROWS => [[
                BrowserTableFieldKey::SOURCE => [
                    BrowserSourceKey::TYPE => BrowserSourceType::RT,
                    BrowserSourceKey::KEY => FreezeUnitRtContext::ROWS,
                ],
                BrowserTableFieldKey::ROW_KEY => 'id',
                BrowserTableFieldKey::FIELDS => ['id', 'name'],
            ]],
        ]);
    }
}

final class FreezeUnitTableContext extends TableContext
{
    public function __construct(
        private readonly FreezeUnitTable $table,
        private readonly FreezeUnitTable $neighbor,
    ) {
    }

    public function configure(): void
    {
        $this->register(FreezeUnitTable::TABLE, $this->table);
        $this->register(FreezeUnitTable::NEIGHBOR, $this->neighbor);
    }
}

/**
 * A window table over the runtime rows, broken on demand at the build, one step later, or at the window.
 */
final class FreezeUnitTable extends TableDefinition implements SelfSnapshotTable
{
    public const string TABLE = 'tableFreezeTable';
    public const string NEIGHBOR = 'tableFreezeNeighbor';
    public const string SLOT = 'tableFreezeRows';
    public const string FILTER_LABEL = 'label';

    /** Source whose changes report work on the table and touch none of its rows */
    public const string PROGRESS_SOURCE = 'tableFreezeProgress';

    /** Message the refused row-mutation build carries into the journal line */
    public const string MUTATION_FAILURE = 'the project row builder gave up';

    /** @var list<string> Row keys whose change reports work on the table beside the row */
    public array $progressOn = [];

    public bool $failQuery = false;

    /**
     * @param list<FreezeUnitRow> $rows Rows the window is taken from
     * @param list<string> $failOn Row keys whose mutation build throws
     * @param bool $keyless Whether the rows this table builds for a change carry no key
     */
    public function __construct(
        public array $rows = [],
        public array $failOn = [],
        private readonly bool $keyless = false,
    ) {
        parent::__construct();
    }

    /**
     * Maps a change of the runtime rows to an update of the row it names.
     *
     * @param SourceChange $change Source change that may affect this table
     * @return ?TableRowMutationDTO Row mutation, or null for another source
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        if ($change->sourceKey !== FreezeUnitRtContext::ROWS) {
            return null;
        }
        if (in_array((string) $change->sourceId, $this->failOn, true)) {
            throw new RuntimeException(self::MUTATION_FAILURE);
        }

        $name = $change->rowFields['name'] ?? null;
        $row = new FreezeUnitRow(
            $this->keyless ? null : (string) $change->sourceId,
            is_string($name) ? $name : 'unchanged',
        );

        return $this->mutation($change->mutationType, $change->sourceId, $row);
    }

    /**
     * Reports one table-wide bar for a change of the progress source or of a row that asked for one.
     *
     * @param SourceChange $change Source change that may report work on this table
     * @return ?TableProgressDTO Bar to show, or null when the change reports none
     */
    public function buildProgressForSourceEvent(SourceChange $change): ?TableProgressDTO
    {
        $reports = $change->sourceKey === self::PROGRESS_SOURCE
            || ($change->sourceKey === FreezeUnitRtContext::ROWS && in_array((string) $change->sourceId, $this->progressOn, true));
        if (!$reports) {
            return null;
        }

        return new TableProgressDTO(TableProgressScope::Table, 'run-1', null, 1, 2);
    }

    /**
     * @param AbstractTableRow $row Self-snapshot row
     * @return array{rowKey: int|string, sources: array<string, mixed>} Internal browser-row envelope
     * @throws TableRowKeyMissingException When the row is a placeholder and carries no key
     */
    public function browserRow(AbstractTableRow $row): array
    {
        return [
            BrowserPageSignalData::rowKey => $row->requireRowKey(),
            BrowserPageSignalData::sources => [self::SLOT => $row->toArray()],
        ];
    }

    /**
     * @param TableQueryDTO $query Window query the counts describe
     * @param array<string, list<int|float|string|bool>> $wanted Options to count, by filter key
     * @return ?array<string, mixed> Counts beside the label filter's options
     */
    public function facetCounts(TableQueryDTO $query, array $wanted): ?array
    {
        return TableFacetTally::forFilters(
            $query,
            array_intersect_key($wanted, [self::FILTER_LABEL => true]),
            fn(TableQueryDTO $set): TableFacetCountDTO => new TableFacetCountDTO(count($this->rows), true),
        );
    }

    /**
     * @return array<string, string> Searched fields, so a searching window reaches this table at all
     */
    protected function searchableFields(): array
    {
        return ['label' => 'label'];
    }

    protected function init(): void
    {
        $this->setRowClass(FreezeUnitRow::class);
    }

    /**
     * Serves the rows, or refuses the window when the fixture was asked to.
     *
     * @param TableQueryDTO $query Window query parameters
     * @return TableSnapshotDTO Windowed snapshot
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        if ($this->failQuery) {
            throw new RuntimeException('the project row source gave up');
        }

        return $this->filterInMemory(
            array_map(static fn(FreezeUnitRow $row): array => $row->toArray(), $this->rows),
            $query,
        );
    }
}

final class FreezeUnitRow extends AbstractTableRow
{
    public function __construct(
        public readonly ?string $key,
        public readonly string $label,
    ) {
    }

    public function getRowKey(): ?string
    {
        return $this->key;
    }

    /**
     * @return string Payload key the row key travels under
     */
    public static function keyField(): string
    {
        return 'key';
    }

    /**
     * @return array<string, mixed> Row fields
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'label' => $this->label];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static Row instance
     */
    public static function fromArray(array $data): static
    {
        return new static((string) $data['key'], (string) $data['label']);
    }
}

/**
 * Runtime context holding the rows the page's list reads.
 */
final class FreezeUnitRtContext extends RtContext
{
    public const string ROWS = 'tableFreezeSourceRows';

    public function configure(): void
    {
        $this->_stateCollections[self::ROWS] = FreezeUnitStates::init();
        $this->setRepresent(self::ROWS, FreezeUnitCollection::class);
    }

    /**
     * @param FreezeUnitState $row Row to add to the collection
     */
    public function addRow(FreezeUnitState $row): void
    {
        $this->_stateCollections[self::ROWS]->add($row);
    }
}

final class FreezeUnitStates extends RtStates
{
    public const string STATE_CLASS = FreezeUnitState::class;
}

final class FreezeUnitState extends RtState
{
    private function __construct(
        public readonly string $id,
        public readonly string $name,
    ) {
        parent::__construct();
    }

    /**
     * @param string $id Row key
     * @param string $name Row label
     * @return self Row state
     */
    public static function create(string $id, string $name): self
    {
        return new self($id, $name);
    }

    /**
     * @return string Row key
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @param array<string, mixed> $row Raw row
     * @return static Row state
     */
    public static function fromRow(array $row): static
    {
        return new static((string) $row['id'], (string) $row['name']);
    }

    /**
     * @return array<string, mixed> Row payload
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name];
    }
}

final class FreezeUnitCollection extends RtCollection
{
    /**
     * @param RtState $state Backing state
     * @return RtItem View item over the state
     */
    protected function createRtItem(RtState $state): RtItem
    {
        return new FreezeUnitItem($state);
    }
}

final class FreezeUnitItem extends RtItem
{
    /**
     * @param string $name Field name
     * @return mixed Field value
     */
    public function __get(string $name): mixed
    {
        $data = $this->toArray();

        return $data[$name] ?? parent::__get($name);
    }

    /**
     * @return array<string, mixed> Row payload
     */
    public function toArray(): array
    {
        return $this->getState()->toArray();
    }
}
