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
use Hilos\Core\Table\DTO\TableViewportCountDTO;
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
 * same flush as the failure is no proof the road is back. The window's debt is one for a freeze
 * and a refusal alike (HIL-1350), and the database coming back tries it once too (HIL-1352).
 *
 * A table that can name the row of a change it cannot build freezes only the windows holding that
 * row, among their rows or in focus; every other window skips the change, lives on, and is recounted
 * when the change could have moved its numbers (HIL-1353).
 */
final class BrowserContextTableFreezeTest extends TestCase
{
    /** Line a change skipped by the windows that do not hold its row writes into the journal */
    private const string SKIPPED_DELTA_LINE = 'Viewport delta skipped a change the table failed to build in windows that do not hold its row';

    /** Temporary main log file the assertions read the written lines back from */
    private string $logFile = '';

    /** @var list<array{0: string, 1: string, 2: mixed}> Signals taken off the router since the last drain, by tab, name and payload */
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
        $this->assertTrue(Hilos::$sr?->isTableWindowOwed('ak-1', $table::TABLE));
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
        $viewport = Hilos::$sr->getTableViewport('ak-1', $table::TABLE);
        $this->assertNotNull($viewport);
        new FreezeUnitContext()->sendTableWindow(FreezeUnitContext::PAGE, 'ak-1', $viewport, 'before-freeze');
        $tracked = $this->payloadsNamed(SignalTypeConstants::TABLE_WINDOW);
        $this->assertCount(1, $tracked);
        $this->assertSame('before-freeze', $tracked[0]->requestId);
        $this->drain();
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
        $this->assertNull($window->requestId);
        $this->assertArrayNotHasKey(TableWindowSignalData::requestId, $window->toArray());
        $this->assertSame($table::TABLE, $window->tableKey);
        $this->assertSame(['a', 'b'], array_map(static fn(array $row): string => (string) $row[PagePayload::rowKey], $window->rows));
        $this->assertSame(2, $window->totalCount);
        $facets = $queued[array_search(SignalTypeConstants::TABLE_FACET_COUNTS, $names, true)][1];
        $this->assertInstanceOf(TableFacetCountsSignalData::class, $facets);
        $this->assertSame($table::TABLE, $facets->tableKey);
        $this->assertFalse(Hilos::$sr?->isTableWindowOwed('ak-1', $table::TABLE));
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
        $this->assertFalse(Hilos::$sr?->isTableWindowOwed('ak-1', $table::TABLE));
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
        $this->assertTrue(Hilos::$sr?->isTableWindowOwed('ak-1', $table::TABLE));

        $table->failQuery = false;
        $this->flushChange('b', 'Beta renamed again');

        $this->assertCount(1, $this->payloadsNamed(SignalTypeConstants::TABLE_WINDOW));
    }

    /**
     * A table that refused the catch-up once refuses it for every later change of the same flush;
     * trying again for each would be a full query, and no line.
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
            $logged,
            substr_count($this->writtenLog(), 'Browser window skipped a table that failed to build'),
        );
        $this->assertSame([], $this->payloadsNamed(SignalTypeConstants::TABLE_VIEWPORT_DELTA));
        $this->assertTrue(Hilos::$sr?->isTableWindowOwed('ak-1', $table::TABLE));
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
     * Only a built window pays the debt; the next change is an ordinary delta again.
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
        $this->assertFalse(Hilos::$sr?->isTableWindowOwed('ak-1', $table::TABLE));
        $this->drain();

        $this->flushChange('a', 'Alpha renamed');

        $this->assertSame([], $this->payloadsNamed(SignalTypeConstants::TABLE_WINDOW));
        $this->assertCount(1, $this->payloadsNamed(SignalTypeConstants::TABLE_VIEWPORT_DELTA));
    }

    /**
     * A refusal does not pay a window that froze. The debt stands, the tab is not told it froze
     * a second time, and the next change the table builds brings the one window and no delta.
     */
    public function testARefusedWindowRequestKeepsTheWindowOwed(): void
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
        $this->assertSame([], $this->payloadsNamed(SignalTypeConstants::TABLE_VIEWPORT_FROZEN));
        $this->assertTrue(Hilos::$sr?->isTableWindowOwed('ak-1', $table::TABLE));
        $this->drain();

        $table->failQuery = false;
        $this->flushChange('b');

        $tableFrames = array_values(array_filter(
            array_column($this->queued(), 0),
            static fn(string $name): bool => str_starts_with($name, 'table_'),
        ));
        $this->assertSame([SignalTypeConstants::TABLE_WINDOW], $tableFrames);
        $this->assertFalse(Hilos::$sr?->isTableWindowOwed('ak-1', $table::TABLE));
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
        $this->assertTrue(Hilos::$sr?->isTableWindowOwed('ak-1', $table::TABLE));
    }

    public function testTheChangeThatFreezesTheWindowStillCarriesItsProgress(): void
    {
        $table = $this->boot(failOn: ['a']);
        $table->progressOn = ['a'];

        $this->flushChange('a');

        $this->assertCount(1, $this->payloadsNamed(SignalTypeConstants::TABLE_VIEWPORT_FROZEN));
        $this->assertCount(1, $this->payloadsNamed(SignalTypeConstants::TABLE_PROGRESS));
    }

    public function testARefusalOwesAWindowThatNeverFroze(): void
    {
        $table = $this->boot();
        $table->failQuery = true;

        $viewport = Hilos::$sr?->getTableViewport('ak-1', $table::TABLE);
        $this->assertNotNull($viewport);
        $delivered = (new FreezeUnitContext())->sendTableWindow(FreezeUnitContext::PAGE, 'ak-1', $viewport);

        $this->assertFalse($delivered);
        $this->assertCount(1, $this->payloadsNamed(SignalTypeConstants::TABLE_WINDOW_REFUSED));
        $this->assertSame([], $this->payloadsNamed(SignalTypeConstants::TABLE_VIEWPORT_FROZEN));
        $this->assertTrue(Hilos::$sr?->isTableWindowOwed('ak-1', $table::TABLE));
    }

    /**
     * The first change the table builds after a refusal is the whole window, with the counts
     * beside the filters, and none of the live frames a window that was never owed would get.
     */
    public function testTheFirstChangeTheTableBuildsBringsARefusedWindowBackWhole(): void
    {
        $table = $this->boot();
        $table->failQuery = true;
        Hilos::$sr?->setTableFacets('ak-1', $table::TABLE, [FreezeUnitTable::FILTER_LABEL => ['Alpha', 'Beta']]);
        $viewport = Hilos::$sr?->getTableViewport('ak-1', $table::TABLE);
        $this->assertNotNull($viewport);
        (new FreezeUnitContext())->sendTableWindow(FreezeUnitContext::PAGE, 'ak-1', $viewport);
        $this->drain();

        $table->failQuery = false;
        $this->flushChange('a');

        $names = array_column($this->queued(), 0);
        $this->assertSame(
            [SignalTypeConstants::TABLE_WINDOW, SignalTypeConstants::TABLE_FACET_COUNTS],
            array_values(array_filter($names, static fn(string $name): bool => str_starts_with($name, 'table_'))),
        );
        $this->assertFalse(Hilos::$sr?->isTableWindowOwed('ak-1', $table::TABLE));
    }

    /**
     * Taking the stand lever off is not itself a fact. While the window still cannot be built,
     * a change of its source sends nothing and writes nothing, and the debt stands.
     */
    public function testARefusedWindowWhoseWindowStillCannotBeBuiltIsSentNothing(): void
    {
        $table = $this->boot();
        $table->failQuery = true;
        $viewport = Hilos::$sr?->getTableViewport('ak-1', $table::TABLE);
        $this->assertNotNull($viewport);
        (new FreezeUnitContext())->sendTableWindow(FreezeUnitContext::PAGE, 'ak-1', $viewport);
        $this->drain();
        $logged = $this->writtenLog();

        $this->flushChange('a');

        $this->assertSame([], array_filter(
            array_column($this->queued(), 0),
            static fn(string $name): bool => str_starts_with($name, 'table_') && $name !== SignalTypeConstants::TABLE_PROGRESS,
        ));
        $this->assertSame($logged, $this->writtenLog());
        $this->assertTrue(Hilos::$sr?->isTableWindowOwed('ak-1', $table::TABLE));
    }

    public function testAChangeTheTableCannotBuildForARefusedWindowSaysNothing(): void
    {
        $table = $this->boot();
        $table->failQuery = true;
        $viewport = Hilos::$sr?->getTableViewport('ak-1', $table::TABLE);
        $this->assertNotNull($viewport);
        (new FreezeUnitContext())->sendTableWindow(FreezeUnitContext::PAGE, 'ak-1', $viewport);
        $this->drain();
        $logged = $this->writtenLog();

        $table->failQuery = false;
        $table->failOn = ['a'];
        $this->flushChange('a');

        $this->assertSame([], array_filter(
            array_column($this->queued(), 0),
            static fn(string $name): bool => str_starts_with($name, 'table_'),
        ));
        $this->assertSame($logged, $this->writtenLog());
        $this->assertTrue(Hilos::$sr?->isTableWindowOwed('ak-1', $table::TABLE));
    }

    /**
     * The numbers of a refused window arrive with its catch-up, beside the rows they count; a
     * recount while it is still owed would move a total under rows the tab does not hold.
     */
    public function testARefusedWindowGetsNoRecountedTotal(): void
    {
        $table = $this->boot(search: 'a');
        $table->failQuery = true;
        $viewport = Hilos::$sr?->getTableViewport('ak-1', $table::TABLE);
        $this->assertNotNull($viewport);
        $this->assertFalse((new FreezeUnitContext())->sendTableWindow(FreezeUnitContext::PAGE, 'ak-1', $viewport));
        $this->drain();

        $context = new FreezeUnitContext();
        $context->record(SourceChange::rtUpdated(FreezeUnitRtContext::ROWS, 'z', ['name' => 'Zeta alpha']));
        $context->flushToSignalRouter();

        $this->assertSame([], $this->payloadsNamed(SignalTypeConstants::TABLE_VIEWPORT_COUNT));
        $this->assertSame([], $this->payloadsNamed(SignalTypeConstants::TABLE_WINDOW));
        $this->assertSame(7, Hilos::$sr?->getTableViewport('ak-1', $table::TABLE)?->totalCount());
        $this->assertTrue(Hilos::$sr?->isTableWindowOwed('ak-1', $table::TABLE));
    }

    public function testAFreshnessMoveTheTableBuildsBringsARefusedWindowBack(): void
    {
        $table = $this->boot();
        $table->failQuery = true;
        $viewport = Hilos::$sr?->getTableViewport('ak-1', $table::TABLE);
        $this->assertNotNull($viewport);
        (new FreezeUnitContext())->sendTableWindow(FreezeUnitContext::PAGE, 'ak-1', $viewport);
        $this->drain();

        $table->failQuery = false;
        $context = new FreezeUnitContext();
        $context->recordSourceStaleness(FreezeUnitRtContext::ROWS, ['b']);
        $context->flushToSignalRouter();

        $this->assertCount(1, $this->payloadsNamed(SignalTypeConstants::TABLE_WINDOW));
        $this->assertSame([], $this->payloadsNamed(SignalTypeConstants::TABLE_VIEWPORT_DELTA));
        $this->assertFalse(Hilos::$sr?->isTableWindowOwed('ak-1', $table::TABLE));
    }

    public function testAFrozenWindowWhoseChangesKeepFailingWritesOneLine(): void
    {
        $this->boot(failOn: ['a']);

        $this->flushChange('a');
        $this->flushChange('a');

        $this->assertCount(1, $this->payloadsNamed(SignalTypeConstants::TABLE_VIEWPORT_FROZEN));
        $this->assertSame(
            1,
            substr_count($this->writtenLog(), 'Viewport delta skipped a change the table failed to build'),
        );
    }

    /**
     * A bar is not part of the window: work running on a refused table keeps being shown, and a
     * bar alone does not pay the debt.
     */
    public function testARefusedWindowStillGetsItsProgress(): void
    {
        $table = $this->boot();
        $table->failQuery = true;
        $viewport = Hilos::$sr?->getTableViewport('ak-1', $table::TABLE);
        $this->assertNotNull($viewport);
        (new FreezeUnitContext())->sendTableWindow(FreezeUnitContext::PAGE, 'ak-1', $viewport);
        $this->drain();

        $context = new FreezeUnitContext();
        $context->record(SourceChange::rtUpdated(FreezeUnitTable::PROGRESS_SOURCE, 'run-1', []));
        $context->flushToSignalRouter();

        $bars = $this->payloadsNamed(SignalTypeConstants::TABLE_PROGRESS);
        $this->assertCount(1, $bars);
        $this->assertInstanceOf(TableProgressSignalData::class, $bars[0]);
        $this->assertSame($table::TABLE, $bars[0]->tableKey);
        $this->assertTrue(Hilos::$sr?->isTableWindowOwed('ak-1', $table::TABLE));
        $this->assertSame([], $this->payloadsNamed(SignalTypeConstants::TABLE_WINDOW));
        $this->assertSame([], $this->payloadsNamed(SignalTypeConstants::TABLE_VIEWPORT_FROZEN));
    }

    /**
     * The database coming back is a fact of its own: with no change in the flush, the refused window
     * comes back whole, with the counts beside its filters, and nothing live goes beside it.
     */
    public function testTheDatabaseFactBringsARefusedWindowBackWhole(): void
    {
        $table = $this->boot();
        Hilos::$sr?->setTableFacets('ak-1', $table::TABLE, [FreezeUnitTable::FILTER_LABEL => ['Alpha', 'Beta']]);
        $this->refuseTheWindow($table);

        $table->failQuery = false;
        $this->flushTheDatabaseFact();

        $this->assertSame(
            [SignalTypeConstants::TABLE_WINDOW, SignalTypeConstants::TABLE_FACET_COUNTS],
            $this->tableFrameNames(),
        );
        $window = $this->payloadsNamed(SignalTypeConstants::TABLE_WINDOW)[0];
        $this->assertInstanceOf(TableWindowSignalData::class, $window);
        $this->assertSame($table::TABLE, $window->tableKey);
        $this->assertSame(['a', 'b'], array_map(static fn(array $row): string => (string) $row[PagePayload::rowKey], $window->rows));
        $this->assertFalse(Hilos::$sr?->isTableWindowOwed('ak-1', $table::TABLE));
    }

    public function testTheDatabaseFactBringsAFrozenWindowBackWhole(): void
    {
        $table = $this->boot(failOn: ['a']);
        $this->flushChange('a');
        $this->drain();

        $table->failOn = [];
        $this->flushTheDatabaseFact();

        $this->assertSame([SignalTypeConstants::TABLE_WINDOW], $this->tableFrameNames());
        $this->assertFalse(Hilos::$sr?->isTableWindowOwed('ak-1', $table::TABLE));
    }

    /**
     * A fact tries a debt once: the try that fails says nothing and writes nothing, and the fact is
     * spent on it — the next flush, with the table healthy again, has nothing to try the debt with.
     */
    public function testTheDatabaseFactWhoseWindowStillCannotBeBuiltSendsNothing(): void
    {
        $table = $this->boot();
        $this->refuseTheWindow($table);
        $logged = $this->writtenLog();

        $context = new FreezeUnitContext();
        $context->recordDatabaseBack();
        $context->flushToSignalRouter();

        $this->assertSame([], $this->tableFrameNames());
        $this->assertSame($logged, $this->writtenLog());
        $this->assertTrue(Hilos::$sr?->isTableWindowOwed('ak-1', $table::TABLE));

        $table->failQuery = false;
        $context->flushToSignalRouter();

        $this->assertSame([], $this->tableFrameNames());
        $this->assertTrue(Hilos::$sr?->isTableWindowOwed('ak-1', $table::TABLE));
    }

    /**
     * A table that came back is not itself a fact: a flush with no change and no database fact
     * leaves the owed window alone.
     */
    public function testAFlushWithoutTheDatabaseFactLeavesAnOwedWindowAlone(): void
    {
        $table = $this->boot();
        $this->refuseTheWindow($table);

        $table->failQuery = false;
        (new FreezeUnitContext())->flushToSignalRouter();

        $this->assertSame([], $this->tableFrameNames());
        $this->assertTrue(Hilos::$sr?->isTableWindowOwed('ak-1', $table::TABLE));
    }

    /**
     * The try comes after the changes of its flush: the window a change caught up holds that change
     * already and owes nothing, so it goes once, and no delta of the same change follows it.
     */
    public function testAWindowAChangeCaughtUpIsNotTriedAgainByTheFact(): void
    {
        $table = $this->boot();
        $this->refuseTheWindow($table);

        $table->failQuery = false;
        $context = new FreezeUnitContext();
        $context->record(SourceChange::rtUpdated(FreezeUnitRtContext::ROWS, 'a', ['name' => 'Alpha renamed']));
        $context->recordDatabaseBack();
        $context->flushToSignalRouter();

        $this->assertSame([SignalTypeConstants::TABLE_WINDOW], $this->tableFrameNames());
        $this->assertFalse(Hilos::$sr?->isTableWindowOwed('ak-1', $table::TABLE));
    }

    /**
     * A window whose catch-up could not be built in this flush is set aside until it ends; the fact
     * does not ask the table for it a second time.
     */
    public function testAWindowWhoseCatchUpFailedInThisFlushIsNotTriedAgainByTheFact(): void
    {
        $table = $this->boot();
        $this->refuseTheWindow($table);
        $logged = $this->writtenLog();
        $queries = $table->queries;

        $context = new FreezeUnitContext();
        $context->record(SourceChange::rtUpdated(FreezeUnitRtContext::ROWS, 'a', ['name' => 'Alpha renamed']));
        $context->recordDatabaseBack();
        $context->flushToSignalRouter();

        $this->assertSame(1, $table->queries - $queries, 'the change asked for the window, the fact did not');
        $this->assertSame([], $this->tableFrameNames());
        $this->assertSame($logged, $this->writtenLog());
        $this->assertTrue(Hilos::$sr?->isTableWindowOwed('ak-1', $table::TABLE));
    }

    /**
     * A window a change caught up whole is left alone for the rest of the flush (P-588): it was
     * built from the table as it is, so it holds a row created later in the same flush already, and
     * a count for that row on top of it would move the total a second time.
     */
    public function testAWindowCaughtUpWholeIsNotCountedAgainForARowCreatedInTheSameFlush(): void
    {
        $table = $this->boot();
        $this->refuseTheWindow($table);

        $table->failQuery = false;
        Hilos::$rt->addRow(FreezeUnitState::create('z', 'Zeta'));
        $table->rows[] = new FreezeUnitRow('z', 'Zeta');
        $context = new FreezeUnitContext();
        $context->record(SourceChange::rtUpdated(FreezeUnitRtContext::ROWS, 'a', ['name' => 'Alpha renamed']));
        $context->record(SourceChange::rtCreated(FreezeUnitRtContext::ROWS, 'z', ['id' => 'z', 'name' => 'Zeta']));
        $context->flushToSignalRouter();

        $windows = $this->payloadsNamed(SignalTypeConstants::TABLE_WINDOW);
        $this->assertCount(1, $windows);
        $this->assertInstanceOf(TableWindowSignalData::class, $windows[0]);
        $this->assertSame(3, $windows[0]->totalCount);
        $this->assertSame([], $this->payloadsNamed(SignalTypeConstants::TABLE_VIEWPORT_COUNT));
        $this->assertSame([SignalTypeConstants::TABLE_WINDOW], $this->tableFrameNames());
        $this->assertSame(3, Hilos::$sr?->getTableViewport('ak-1', $table::TABLE)?->totalCount());
    }

    /**
     * The same for a row deleted outside the window: the window caught up whole no longer counts it,
     * and a count for its removal on top of it would take it away a second time (P-588).
     */
    public function testAWindowCaughtUpWholeIsNotCountedAgainForARowDeletedInTheSameFlush(): void
    {
        $table = $this->boot();
        $this->refuseTheWindow($table);

        $table->failQuery = false;
        $context = new FreezeUnitContext();
        $context->record(SourceChange::rtUpdated(FreezeUnitRtContext::ROWS, 'a', ['name' => 'Alpha renamed']));
        $context->record(SourceChange::rtDeleted(FreezeUnitRtContext::ROWS, 'y'));
        $context->flushToSignalRouter();

        $windows = $this->payloadsNamed(SignalTypeConstants::TABLE_WINDOW);
        $this->assertCount(1, $windows);
        $this->assertInstanceOf(TableWindowSignalData::class, $windows[0]);
        $this->assertSame(2, $windows[0]->totalCount);
        $this->assertSame([], $this->payloadsNamed(SignalTypeConstants::TABLE_VIEWPORT_COUNT));
        $this->assertSame([SignalTypeConstants::TABLE_WINDOW], $this->tableFrameNames());
        $this->assertSame(2, Hilos::$sr?->getTableViewport('ak-1', $table::TABLE)?->totalCount());
    }

    public function testTheDatabaseFactLeavesAHealthyNeighborAlone(): void
    {
        $table = $this->boot(withNeighbor: true);
        $this->refuseTheWindow($table);

        $table->failQuery = false;
        $this->flushTheDatabaseFact();

        $this->assertSame([SignalTypeConstants::TABLE_WINDOW], $this->tableFrameNames());
        $window = $this->payloadsNamed(SignalTypeConstants::TABLE_WINDOW)[0];
        $this->assertInstanceOf(TableWindowSignalData::class, $window);
        $this->assertSame($table::TABLE, $window->tableKey);
    }

    public function testTheDatabaseFactWithNoDebtSendsNothing(): void
    {
        $this->boot(withNeighbor: true);

        $this->flushTheDatabaseFact();

        $this->assertSame([], $this->queued());
    }

    /**
     * A change the table cannot build but can name the row of freezes only the window holding that
     * row (HIL-1353): the tab whose window does not show the row lives on, owes nothing, and the
     * journal is told it skipped the change.
     */
    public function testAChangeWhoseRowTheTableNamesFreezesOnlyTheWindowHoldingIt(): void
    {
        $table = $this->boot(failOn: ['a']);
        $table->namesRows = true;
        $this->openTab('ak-2', ['b']);

        $this->flushChange('a');

        $this->assertCount(1, $this->payloadsNamed(SignalTypeConstants::TABLE_VIEWPORT_FROZEN));
        $this->assertTrue(Hilos::$sr?->isTableWindowOwed('ak-1', $table::TABLE));
        $this->assertSame([], $this->tableFrameNames('ak-2'));
        $this->assertFalse(Hilos::$sr?->isTableWindowOwed('ak-2', $table::TABLE));
        $logged = $this->writtenLog();
        $this->assertSame(1, substr_count($logged, 'Viewport delta skipped a change the table failed to build: '));
        $this->assertSame(1, substr_count($logged, self::SKIPPED_DELTA_LINE));
        $this->assertStringContainsString('table=' . $table::TABLE . ', row=a, ', $logged);
    }

    /**
     * A table that does not name the row keeps the road it was on before the question existed: the
     * window of every reader freezes.
     */
    public function testAChangeWhoseRowTheTableDoesNotNameFreezesEveryWindow(): void
    {
        $table = $this->boot(failOn: ['a']);
        $this->openTab('ak-2', ['b']);

        $this->flushChange('a');

        $this->assertCount(1, $this->payloadsNamed(SignalTypeConstants::TABLE_VIEWPORT_FROZEN));
        $this->assertCount(1, $this->payloadsNamed(SignalTypeConstants::TABLE_VIEWPORT_FROZEN, 'ak-2'));
        $this->assertTrue(Hilos::$sr?->isTableWindowOwed('ak-2', $table::TABLE));
        $this->assertStringNotContainsString(self::SKIPPED_DELTA_LINE, $this->writtenLog());
    }

    /**
     * A table that throws while naming the row cannot say which windows hold it, so every one of them
     * freezes.
     */
    public function testATableThatFailsToNameTheRowFreezesEveryWindow(): void
    {
        $table = $this->boot(failOn: ['a']);
        $table->namesRows = true;
        $table->namingFails = true;
        $this->openTab('ak-2', ['b']);

        $this->flushChange('a');

        $this->assertCount(1, $this->payloadsNamed(SignalTypeConstants::TABLE_VIEWPORT_FROZEN));
        $this->assertCount(1, $this->payloadsNamed(SignalTypeConstants::TABLE_VIEWPORT_FROZEN, 'ak-2'));
        $this->assertTrue(Hilos::$sr?->isTableWindowOwed('ak-2', $table::TABLE));
        $this->assertStringNotContainsString(self::SKIPPED_DELTA_LINE, $this->writtenLog());
    }

    /**
     * A row held in focus under an open dialog is a row the window holds: the dialog would go on
     * showing a body the change never reached.
     */
    public function testAWindowHoldingTheRowInFocusFreezesToo(): void
    {
        $table = $this->boot(failOn: ['a']);
        $table->namesRows = true;
        $this->openTab('ak-2', ['b']);
        Hilos::$sr?->setTableFocus('ak-2', $table::TABLE, 'a');

        $this->flushChange('a');

        $this->assertCount(1, $this->payloadsNamed(SignalTypeConstants::TABLE_VIEWPORT_FROZEN));
        $this->assertCount(1, $this->payloadsNamed(SignalTypeConstants::TABLE_VIEWPORT_FROZEN, 'ak-2'));
        $this->assertTrue(Hilos::$sr?->isTableWindowOwed('ak-2', $table::TABLE));
    }

    /**
     * A window that skipped a change is not set aside: the next change of the same flush reaches it
     * as usual, while the window that froze is left alone until the flush ends.
     */
    public function testAWindowThatSkippedAChangeIsSentTheNextChangeOfTheSameFlush(): void
    {
        $table = $this->boot(failOn: ['a']);
        $table->namesRows = true;
        $this->openTab('ak-2', ['b']);

        $this->flushChanges(
            SourceChange::rtUpdated(FreezeUnitRtContext::ROWS, 'a', ['name' => 'Alpha renamed']),
            SourceChange::rtUpdated(FreezeUnitRtContext::ROWS, 'b', ['name' => 'Beta renamed']),
        );

        $deltas = $this->payloadsNamed(SignalTypeConstants::TABLE_VIEWPORT_DELTA, 'ak-2');
        $this->assertCount(1, $deltas);
        $this->assertInstanceOf(TableViewportDeltaDTO::class, $deltas[0]);
        $this->assertSame('b', (string) $deltas[0]->rowKey);
        $this->assertSame([SignalTypeConstants::TABLE_VIEWPORT_FROZEN], $this->tableFrameNames());
    }

    /**
     * The defect is the table's and not the window's: however many windows skip the change, the
     * journal is told once.
     */
    public function testThreeTabsWithoutTheRowWriteOneLine(): void
    {
        $table = $this->boot(failOn: ['a']);
        $table->namesRows = true;
        $this->openTab('ak-2', ['b']);
        $this->openTab('ak-3', ['b']);
        $this->openTab('ak-4', []);

        $this->flushChange('a');

        $this->assertSame(1, substr_count($this->writtenLog(), self::SKIPPED_DELTA_LINE));
        foreach (['ak-2', 'ak-3', 'ak-4'] as $acceptKey) {
            $this->assertSame([], $this->tableFrameNames($acceptKey), $acceptKey);
            $this->assertFalse(Hilos::$sr?->isTableWindowOwed($acceptKey, $table::TABLE), $acceptKey);
        }
    }

    /**
     * A created row no window holds may still belong to every window's set, so a window that skipped
     * its create is recounted at the end of the flush.
     */
    public function testASkippedCreateRecountsTheWindowsTotal(): void
    {
        $table = $this->boot(failOn: ['c']);
        $table->namesRows = true;
        $this->openTab('ak-2', ['b']);
        Hilos::$rt->addRow(FreezeUnitState::create('c', 'Gamma'));
        $table->rows[] = new FreezeUnitRow('c', 'Gamma');

        $this->flushChanges(SourceChange::rtCreated(FreezeUnitRtContext::ROWS, 'c', ['id' => 'c', 'name' => 'Gamma']));

        $counts = $this->payloadsNamed(SignalTypeConstants::TABLE_VIEWPORT_COUNT, 'ak-2');
        $this->assertCount(1, $counts);
        $this->assertInstanceOf(TableViewportCountDTO::class, $counts[0]);
        $this->assertSame(3, $counts[0]->totalCount);
        $this->assertSame([SignalTypeConstants::TABLE_VIEWPORT_COUNT], $this->tableFrameNames('ak-2'));
        $this->assertSame(3, Hilos::$sr?->getTableViewport('ak-2', $table::TABLE)?->totalCount());
        $this->assertFalse(Hilos::$sr?->isTableWindowOwed('ak-2', $table::TABLE));
    }

    /**
     * An edit of a row outside a window that narrows nothing moves no total, so skipping it costs
     * no query.
     */
    public function testASkippedEditInAWindowWithoutSearchCostsNoQuery(): void
    {
        $table = $this->boot(failOn: ['a']);
        $table->namesRows = true;
        $this->openTab('ak-2', ['b']);
        $queries = $table->queries;

        $this->flushChange('a');

        $this->assertSame($queries, $table->queries);
        $this->assertSame([], $this->payloadsNamed(SignalTypeConstants::TABLE_VIEWPORT_COUNT, 'ak-2'));
    }

    /**
     * In a window that narrows its set by a search, the edited row may have entered or left the set,
     * and whether it was in it before is not known; the window is recounted.
     */
    public function testASkippedEditInASearchingWindowRecountsIt(): void
    {
        $table = $this->boot(failOn: ['a']);
        $table->namesRows = true;
        $this->openTab('ak-2', ['b'], search: 'Beta');
        $queries = $table->queries;

        $this->flushChange('a');

        $this->assertGreaterThan($queries, $table->queries);
        $counts = $this->payloadsNamed(SignalTypeConstants::TABLE_VIEWPORT_COUNT, 'ak-2');
        $this->assertCount(1, $counts);
        $this->assertInstanceOf(TableViewportCountDTO::class, $counts[0]);
        $this->assertSame(1, $counts[0]->totalCount);
    }

    /**
     * Whether the edit moved a filter option cannot be known without the row's previous record, so
     * the counts beside the filters the tab declared arrive at the end of the flush.
     */
    public function testASkippedEditBringsTheDeclaredFacetCounts(): void
    {
        $table = $this->boot(failOn: ['a']);
        $table->namesRows = true;
        $this->openTab('ak-2', ['b']);
        Hilos::$sr?->setTableFacets('ak-2', $table::TABLE, [FreezeUnitTable::FILTER_LABEL => ['Alpha', 'Beta']]);

        $this->flushChange('a');

        $this->assertSame([SignalTypeConstants::TABLE_FACET_COUNTS], $this->tableFrameNames('ak-2'));
        $facets = $this->payloadsNamed(SignalTypeConstants::TABLE_FACET_COUNTS, 'ak-2');
        $this->assertInstanceOf(TableFacetCountsSignalData::class, $facets[0]);
        $this->assertSame($table::TABLE, $facets[0]->tableKey);
    }

    /**
     * A window owed whole keeps its debt when it skips a change: the build failed, which is no proof
     * its road is back, and its numbers arrive with its catch-up.
     */
    public function testAnOwedWindowThatSkipsAChangeGetsNothingAndStaysOwed(): void
    {
        $table = $this->boot(failOn: ['c']);
        $table->namesRows = true;
        $this->openTab('ak-2', ['b']);
        $this->refuseTheWindow($table, 'ak-2');
        $table->failQuery = false;
        Hilos::$rt->addRow(FreezeUnitState::create('c', 'Gamma'));
        $table->rows[] = new FreezeUnitRow('c', 'Gamma');

        $this->flushChanges(SourceChange::rtCreated(FreezeUnitRtContext::ROWS, 'c', ['id' => 'c', 'name' => 'Gamma']));

        $this->assertSame([], $this->tableFrameNames('ak-2'));
        $this->assertTrue(Hilos::$sr?->isTableWindowOwed('ak-2', $table::TABLE));
        $this->assertSame([SignalTypeConstants::TABLE_VIEWPORT_COUNT], $this->tableFrameNames());
    }

    /**
     * A freshness move the table cannot build freezes only the window holding the row; the window
     * without it skips the move with a line of its own, and is recounted for nothing, since freshness
     * moves no number.
     */
    public function testAFreshnessMoveWhoseRowTheTableNamesFreezesOnlyTheWindowHoldingIt(): void
    {
        $table = $this->boot(failOn: ['a']);
        $table->namesRows = true;
        $this->openTab('ak-2', ['b'], search: 'Beta');
        Hilos::$sr?->setTableFacets('ak-2', $table::TABLE, [FreezeUnitTable::FILTER_LABEL => ['Alpha', 'Beta']]);
        $queries = $table->queries;

        $context = new FreezeUnitContext();
        $context->recordSourceStaleness(FreezeUnitRtContext::ROWS, ['a']);
        $context->flushToSignalRouter();

        $this->assertCount(1, $this->payloadsNamed(SignalTypeConstants::TABLE_VIEWPORT_FROZEN));
        $this->assertSame([], $this->tableFrameNames('ak-2'));
        $this->assertFalse(Hilos::$sr?->isTableWindowOwed('ak-2', $table::TABLE));
        $this->assertSame($queries, $table->queries);
        $logged = $this->writtenLog();
        $this->assertSame(1, substr_count($logged, 'Viewport staleness skipped a row the table failed to build: '));
        $this->assertSame(
            1,
            substr_count($logged, 'Viewport staleness skipped a row the table failed to build in windows that do not hold it'),
        );
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
     * A window holding its rows as placeholder bodies, so any edit of them reads as a change.
     *
     * @param string $tableKey Table the window is on
     * @param ?string $search Search the window narrows its set by, or null for none
     * @param list<string> $holds Rows the window holds
     * @return TableViewportSubscription Window with those rows delivered
     */
    private static function viewportOf(string $tableKey, ?string $search, array $holds = ['a', 'b']): TableViewportSubscription
    {
        $viewport = new TableViewportSubscription(
            tableKey: $tableKey,
            filter: $search === null ? [] : [TableConstants::FILTER_KEY_SEARCH => $search],
            limit: 10,
        );
        $window = [];
        foreach ($holds as $rowKey) {
            $window[$rowKey] = [PagePayload::rowKey => $rowKey, PagePayload::slots => []];
        }
        $viewport->recordWindow($window, 7, true, null, null);

        return $viewport;
    }

    /**
     * Opens one more tab on the page, whose window on the table holds only the rows named.
     *
     * @param string $acceptKey Connection of the tab
     * @param list<string> $holds Rows its window holds
     * @param ?string $search Search its window narrows its set by, or null for none
     */
    private function openTab(string $acceptKey, array $holds, ?string $search = null): void
    {
        Hilos::$sr?->subscribeToPage(
            FreezeUnitContext::PAGE,
            new WebSocketPageSubscribeSignalDTO($acceptKey, FreezeUnitContext::PAGE),
        );
        Hilos::$sr?->setTableViewport($acceptKey, self::viewportOf(FreezeUnitTable::TABLE, $search, $holds));
    }

    /**
     * Refuses a connection's window and empties the queue of the refusal.
     *
     * The table is left refusing its window, so the test decides when it comes back.
     *
     * @param FreezeUnitTable $table Table whose window is refused
     * @param string $acceptKey Connection whose window is refused
     */
    private function refuseTheWindow(FreezeUnitTable $table, string $acceptKey = 'ak-1'): void
    {
        $table->failQuery = true;
        $viewport = Hilos::$sr?->getTableViewport($acceptKey, $table::TABLE);
        $this->assertNotNull($viewport);
        $this->assertFalse((new FreezeUnitContext())->sendTableWindow(FreezeUnitContext::PAGE, $acceptKey, $viewport));
        $this->drain();
    }

    /**
     * Records one edit of a row and flushes it through a fresh browser context.
     *
     * @param string $rowKey Row the edit is about
     * @param string $name New name the edit carries
     */
    private function flushChange(string $rowKey, string $name = 'Renamed'): void
    {
        $this->flushChanges(SourceChange::rtUpdated(FreezeUnitRtContext::ROWS, $rowKey, ['name' => $name]));
    }

    /**
     * Records the changes in their order and flushes them through one fresh browser context.
     *
     * @param SourceChange ...$changes Changes of one flush
     */
    private function flushChanges(SourceChange ...$changes): void
    {
        $context = new FreezeUnitContext();
        foreach ($changes as $change) {
            $context->record($change);
        }
        $context->flushToSignalRouter();
    }

    /**
     * Flushes a fresh browser context that holds the database fact and no change.
     */
    private function flushTheDatabaseFact(): void
    {
        $context = new FreezeUnitContext();
        $context->recordDatabaseBack();
        $context->flushToSignalRouter();
    }

    /**
     * The names of the table frames queued to one tab since the last drain.
     *
     * @param string $acceptKey Tab the frames went to
     * @return list<string> Names that start with table_, in the order they were queued
     */
    private function tableFrameNames(string $acceptKey = 'ak-1'): array
    {
        return array_values(array_filter(
            array_column($this->queued($acceptKey), 0),
            static fn(string $name): bool => str_starts_with($name, 'table_'),
        ));
    }

    /**
     * Every signal queued to one tab since the last drain, in the order they were queued.
     *
     * Each signal taken off the router must be addressed to a tab subscribed to the page.
     *
     * @param string $acceptKey Tab the signals went to
     * @return list<array{0: string, 1: mixed}> Signal name and its addressed payload
     */
    private function queued(string $acceptKey = 'ak-1'): array
    {
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
            $this->assertArrayHasKey($signal->data->targetAcceptKey, Hilos::$sr->getPageSubscriptions());
            $this->taken[] = [$signal->data->targetAcceptKey, $signal->signalName->getName(), $signal->data->data];
        }

        $queued = [];
        foreach ($this->taken as [$target, $name, $payload]) {
            if ($target === $acceptKey) {
                $queued[] = [$name, $payload];
            }
        }

        return $queued;
    }

    /**
     * The payloads of one name among the signals queued to one tab since the last drain.
     *
     * @param string $name Signal name to keep
     * @param string $acceptKey Tab the signals went to
     * @return list<mixed> Payloads of that name, in the order they were queued
     */
    private function payloadsNamed(string $name, string $acceptKey = 'ak-1'): array
    {
        $payloads = [];
        foreach ($this->queued($acceptKey) as [$queuedName, $payload]) {
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

    /** Message the refused naming of a row carries */
    public const string NAMING_FAILURE = 'the project row namer gave up';

    /** @var list<string> Row keys whose change reports work on the table beside the row */
    public array $progressOn = [];

    public bool $failQuery = false;

    /** Whether the table names the row of a change it fails to build */
    public bool $namesRows = false;

    /** Whether naming the row of a change throws */
    public bool $namingFails = false;

    /** How many times the window's rows were asked for, served or refused */
    public int $queries = 0;

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
     * Names the row a change of the runtime rows touches, when the fixture was asked to.
     *
     * @param SourceChange $change Source change the build threw for
     * @return ?string Row key, or null when the fixture names nothing
     * @throws RuntimeException When the fixture was asked to fail at naming the row
     */
    public function rowKeyForSourceEvent(SourceChange $change): ?string
    {
        if ($change->sourceKey !== FreezeUnitRtContext::ROWS) {
            return null;
        }
        if ($this->namingFails) {
            throw new RuntimeException(self::NAMING_FAILURE);
        }

        return $this->namesRows ? (string) $change->sourceId : null;
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
        $this->queries++;
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
