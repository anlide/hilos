<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserPageBindings;
use Hilos\Core\Browser\Config\BrowserPageConfig;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\Table\Definition\SelfSnapshotTable;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\Definition\WindowScopedViewportTable;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\DTO\TableViewportDeltaDTO;
use Hilos\Core\Table\DTO\TableWindowSignalData;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Hilos;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A table whose rows depend on the subject of its window builds each change for one window (HIL-1477).
 *
 * The names tables of the i18n section open a window on one language or one country, carried in
 * the window's filter, and the row of a language reads differently in the window of each subject.
 * Until this leaf a viewport table built one mutation per change and knew nothing of the window,
 * so such a row was not expressible at all. Now the table answers for one window, by that window's
 * query, with a list — and every mutation of the list travels the road a single-row change takes.
 *
 * What pulls against itself is that the list belongs to the rows while the freeze, the catch-up
 * and the facet mark belong to the window: several mutations must make several deltas, but a
 * frozen window must still be caught up once, and a throw must freeze only the window it was
 * thrown for.
 */
final class BrowserContextWindowScopedTableTest extends TestCase
{
    /** Temporary main log file, so the freeze line does not reach the shared journal */
    private string $logFile = '';

    /** @var list<array{0: string, 1: string, 2: mixed}> Signals taken off the router, by connection, name and payload */
    private array $taken = [];

    protected function setUp(): void
    {
        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'hilos-window-scoped-log');
        Logger::setLogFile($this->logFile);

        Hilos::$sr = new SignalRouter();
        foreach (['ak-en', 'ak-de'] as $acceptKey) {
            Hilos::$sr->subscribeToPage(
                WindowScopedUnitContext::PAGE,
                new WebSocketPageSubscribeSignalDTO($acceptKey, WindowScopedUnitContext::PAGE),
            );
        }
    }

    protected function tearDown(): void
    {
        Logger::resetLogFile();
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }

        Hilos::$sr = null;
        Hilos::$table = null;
        Hilos::resetBrowser();

        parent::tearDown();
    }

    public function testOneChangeBuildsADifferentRowInTheWindowOfEachSubject(): void
    {
        $this->boot();

        $this->flushChange('a', 'Alpha');

        $this->assertSame(['a' => 'en:Alpha'], $this->updatedLabels('ak-en'));
        $this->assertSame(['a' => 'de:Alpha'], $this->updatedLabels('ak-de'));
    }

    public function testEveryMutationOfOneChangeIsItsOwnDelta(): void
    {
        $this->boot();

        $this->flushChange(WindowScopedUnitTable::EVERY_ROW, 'Renamed');

        $this->assertSame(['a' => 'en:Renamed', 'b' => 'en:Renamed'], $this->updatedLabels('ak-en'));
        $this->assertSame(['a' => 'de:Renamed', 'b' => 'de:Renamed'], $this->updatedLabels('ak-de'));
    }

    public function testAChangeThatTouchesNoRowOfTheWindowSendsItNothing(): void
    {
        $table = $this->boot();
        $table->touchedSubjects = ['en'];

        $this->flushChange('a', 'Alpha');

        $this->assertSame(['a' => 'en:Alpha'], $this->updatedLabels('ak-en'));
        $this->assertSame([], $this->namedFor('ak-de', null));
    }

    public function testAThrowFreezesOnlyTheWindowItWasThrownFor(): void
    {
        $table = $this->boot();
        $table->failOn = ['de'];

        $this->flushChange('a', 'Alpha');

        $this->assertSame(['a' => 'en:Alpha'], $this->updatedLabels('ak-en'));
        $this->assertSame([], $this->namedFor('ak-en', SignalTypeConstants::TABLE_VIEWPORT_FROZEN));
        $this->assertCount(1, $this->namedFor('ak-de', SignalTypeConstants::TABLE_VIEWPORT_FROZEN));
        $this->assertSame([], $this->namedFor('ak-de', SignalTypeConstants::TABLE_VIEWPORT_DELTA));
        $this->assertFalse(Hilos::$sr?->isTableViewportFrozen('ak-en', WindowScopedUnitTable::TABLE));
        $this->assertTrue(Hilos::$sr?->isTableViewportFrozen('ak-de', WindowScopedUnitTable::TABLE));
    }

    /**
     * The catch-up belongs to the window and not to the rows: a change of two rows that proves the
     * road is back brings the whole window once, and no delta beside it.
     */
    public function testAFrozenWindowIsCaughtUpOnceByAChangeOfSeveralRows(): void
    {
        $table = $this->boot();
        $table->failOn = ['de'];
        $this->flushChange('a', 'Alpha');
        $this->taken = [];

        $table->failOn = [];
        $this->flushChange(WindowScopedUnitTable::EVERY_ROW, 'Renamed');

        $windows = $this->namedFor('ak-de', SignalTypeConstants::TABLE_WINDOW);
        $this->assertCount(1, $windows);
        $this->assertInstanceOf(TableWindowSignalData::class, $windows[0]);
        $this->assertSame(
            ['de:Renamed', 'de:Renamed'],
            array_map(static fn(array $row): string => $row[PagePayload::slots][WindowScopedUnitTable::SLOT]['label'], $windows[0]->rows),
        );
        $this->assertSame([], $this->namedFor('ak-de', SignalTypeConstants::TABLE_VIEWPORT_DELTA));
        $this->assertFalse(Hilos::$sr?->isTableViewportFrozen('ak-de', WindowScopedUnitTable::TABLE));
    }

    public function testAnOrdinaryTableOnTheSamePageStillBuildsOneRowForEveryWindow(): void
    {
        $this->boot(withPlain: true);

        $this->flushChange('a', 'Alpha');

        $plain = array_values(array_filter(
            $this->namedFor('ak-en', SignalTypeConstants::TABLE_VIEWPORT_DELTA),
            static fn(TableViewportDeltaDTO $delta): bool => $delta->tableKey === WindowScopedUnitPlainTable::TABLE,
        ));
        $this->assertCount(1, $plain);
        $this->assertSame('a', (string) $plain[0]->rowKey);
        $this->assertSame('Alpha', $plain[0]->row[PagePayload::slots][WindowScopedUnitTable::SLOT]['label']);
    }

    /**
     * A freshness move is rebuilt through the same door, so one address that reaches several rows
     * of the window tells each of them its own list.
     */
    public function testAFreshnessMoveTellsEveryRowOfTheWindowItReached(): void
    {
        $table = $this->boot();
        $table->staleSlots = [WindowScopedUnitTable::SLOT];

        $context = new WindowScopedUnitContext();
        $context->recordSourceStaleness(WindowScopedUnitTable::SOURCE, [WindowScopedUnitTable::EVERY_ROW]);
        $context->flushToSignalRouter();

        $stale = $this->namedFor('ak-en', SignalTypeConstants::TABLE_VIEWPORT_DELTA);
        $this->assertSame(['a', 'b'], array_map(static fn(TableViewportDeltaDTO $delta): string => (string) $delta->rowKey, $stale));
        foreach ($stale as $delta) {
            $this->assertSame(TableViewportDeltaDTO::KIND_ROW_STALE, $delta->kind);
            $this->assertSame([WindowScopedUnitTable::SLOT], $delta->staleSources);
        }
    }

    /**
     * Registers the tables and opens a window per subject, each holding rows a and b.
     *
     * @param bool $withPlain Whether an ordinary viewport table on the same page holds a window too
     * @return WindowScopedUnitTable The window-scoped table the windows are on
     */
    private function boot(bool $withPlain = false): WindowScopedUnitTable
    {
        $table = new WindowScopedUnitTable();
        Hilos::$table = new WindowScopedUnitTableContext($table, new WindowScopedUnitPlainTable());
        Hilos::$table->configure();

        Hilos::$sr?->setTableViewport('ak-en', self::viewportOf(WindowScopedUnitTable::TABLE, ['subject' => 'en']));
        Hilos::$sr?->setTableViewport('ak-de', self::viewportOf(WindowScopedUnitTable::TABLE, ['subject' => 'de']));
        if ($withPlain) {
            Hilos::$sr?->setTableViewport('ak-en', self::viewportOf(WindowScopedUnitPlainTable::TABLE, []));
        }

        return $table;
    }

    /**
     * A window holding rows a and b as placeholder bodies, so any edit of them reads as a change.
     *
     * @param string $tableKey Table the window is on
     * @param array<string, string> $filter Filter the window was opened with
     * @return TableViewportSubscription Window with rows a and b delivered
     */
    private static function viewportOf(string $tableKey, array $filter): TableViewportSubscription
    {
        $viewport = new TableViewportSubscription(tableKey: $tableKey, filter: $filter, limit: 10);
        $window = [];
        foreach (WindowScopedUnitTable::ROWS as $rowKey) {
            $window[$rowKey] = [PagePayload::rowKey => $rowKey, PagePayload::slots => []];
        }
        $viewport->recordWindow($window, count(WindowScopedUnitTable::ROWS), true, null, null);

        return $viewport;
    }

    /**
     * Records one edit of the source and flushes it through a fresh browser context.
     *
     * @param string $rowKey Source row the edit is about, or the mark that it reaches every row
     * @param string $name New name the edit carries
     */
    private function flushChange(string $rowKey, string $name): void
    {
        $context = new WindowScopedUnitContext();
        $context->record(SourceChange::rtUpdated(WindowScopedUnitTable::SOURCE, $rowKey, ['name' => $name]));
        $context->flushToSignalRouter();
    }

    /**
     * Labels of the rows the window-scoped table's deltas updated for one connection, by row key.
     *
     * @param string $acceptKey Connection the deltas were addressed to
     * @return array<string, string> Updated label by row key, in the order the deltas were queued
     */
    private function updatedLabels(string $acceptKey): array
    {
        $labels = [];
        foreach ($this->namedFor($acceptKey, SignalTypeConstants::TABLE_VIEWPORT_DELTA) as $delta) {
            $this->assertInstanceOf(TableViewportDeltaDTO::class, $delta);
            $this->assertSame(TableViewportDeltaDTO::KIND_ROW_UPDATED, $delta->kind);
            $labels[(string) $delta->rowKey] = $delta->row[PagePayload::slots][WindowScopedUnitTable::SLOT]['label'];
        }

        return $labels;
    }

    /**
     * The payloads queued for one connection since the last reset, of one name or of every table name.
     *
     * @param string $acceptKey Connection the signals were addressed to
     * @param ?string $name Signal name to keep, or null for every table signal
     * @return list<mixed> Payloads, in the order they were queued
     */
    private function namedFor(string $acceptKey, ?string $name): array
    {
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
            $this->taken[] = [$signal->data->targetAcceptKey, $signal->signalName->getName(), $signal->data->data];
        }

        $payloads = [];
        foreach ($this->taken as [$target, $queuedName, $payload]) {
            $wanted = $name === null ? str_starts_with($queuedName, 'table_') : $queuedName === $name;
            if ($target === $acceptKey && $wanted) {
                $payloads[] = $payload;
            }
        }

        return $payloads;
    }
}

/**
 * Serves one page with the window-scoped table and an ordinary table beside it.
 */
final class WindowScopedUnitContext extends BrowserContext
{
    public const string PAGE = 'window_scoped_page';

    private const string SIGNAL = 'window_scoped_signal';

    /**
     * @param string $page Page name from the subscription mirror
     * @return ?BrowserPageConfig Test page metadata, or null when absent
     */
    protected function resolveBrowserPageConfig(string $page): ?BrowserPageConfig
    {
        return $page === self::PAGE ? BrowserPageConfig::fromArray([BrowserConfigKey::SIGNAL => self::SIGNAL]) : null;
    }

    /**
     * @param string $page Page name from the subscription mirror
     * @return BrowserPageBindings Test page table bindings
     */
    protected function resolveBrowserPageBindings(string $page): BrowserPageBindings
    {
        return $page === self::PAGE
            ? BrowserPageBindings::fromArray([WindowScopedUnitTable::TABLE => [], WindowScopedUnitPlainTable::TABLE => []])
            : BrowserPageBindings::empty();
    }
}

final class WindowScopedUnitTableContext extends TableContext
{
    public function __construct(
        private readonly WindowScopedUnitTable $table,
        private readonly WindowScopedUnitPlainTable $plain,
    ) {
    }

    public function configure(): void
    {
        $this->register(WindowScopedUnitTable::TABLE, $this->table);
        $this->register(WindowScopedUnitPlainTable::TABLE, $this->plain);
    }
}

/**
 * A table whose row reads "<subject>:<name>": the same source row differs in the window of each subject.
 */
final class WindowScopedUnitTable extends TableDefinition implements WindowScopedViewportTable
{
    public const string TABLE = 'windowScopedTable';
    public const string SLOT = 'windowScopedRows';
    public const string SOURCE = 'windowScopedSource';
    public const string FILTER_SUBJECT = 'subject';

    /** Source row key whose change reaches every row of a window */
    public const string EVERY_ROW = '*';

    /** Row keys of every window */
    public const array ROWS = ['a', 'b'];

    /** @var list<string> Subjects whose window build throws */
    public array $failOn = [];

    /** @var ?list<string> Subjects whose window a change touches, or null for every subject */
    public ?array $touchedSubjects = null;

    /** @var list<string> Slots every row names as fallen behind */
    public array $staleSlots = [];

    /**
     * Updates the named row, or every row, with the subject of the window in its label.
     *
     * @param SourceChange $change Source change that may affect this table
     * @param TableQueryDTO $window Query the window was served by
     * @return list<TableRowMutationDTO> Row mutations for this window
     */
    public function buildMutationsForWindow(SourceChange $change, TableQueryDTO $window): array
    {
        $subject = $window->filter[self::FILTER_SUBJECT] ?? null;
        if ($change->sourceKey !== self::SOURCE
            || !is_string($subject)
            || ($this->touchedSubjects !== null && !in_array($subject, $this->touchedSubjects, true))
        ) {
            return [];
        }
        if (in_array($subject, $this->failOn, true)) {
            throw new RuntimeException("the window of {$subject} could not be built");
        }

        $name = $change->row['name'] ?? 'unchanged';
        $rowKeys = (string) $change->sourceId === self::EVERY_ROW ? self::ROWS : [(string) $change->sourceId];

        return array_map(
            fn(string $rowKey): TableRowMutationDTO => $this->mutation(
                TableMutationType::Update,
                $rowKey,
                new WindowScopedUnitRow($rowKey, "{$subject}:{$name}"),
            ),
            $rowKeys,
        );
    }

    /**
     * @param AbstractTableRow $row Window-scoped row
     * @return array{rowKey: int|string, sources: array<string, mixed>, staleSources?: list<string>} Internal browser-row envelope
     * @throws TableRowKeyMissingException When the row is a placeholder and carries no key
     */
    public function browserRow(AbstractTableRow $row): array
    {
        $envelope = [
            BrowserPageSignalData::rowKey => $row->requireRowKey(),
            BrowserPageSignalData::sources => [self::SLOT => $row->toArray()],
        ];
        if ($this->staleSlots !== []) {
            $envelope[BrowserPageSignalData::staleSources] = $this->staleSlots;
        }

        return $envelope;
    }

    /**
     * @param string|int $rowKey Row key to place against the set
     * @param TableQueryDTO $query Window query whose filter names the subject
     * @return bool Whether the subject's window holds the row
     */
    public function containsRow(string|int $rowKey, TableQueryDTO $query): bool
    {
        return in_array((string) $rowKey, self::ROWS, true);
    }

    protected function init(): void
    {
        $this->setRowClass(WindowScopedUnitRow::class);
    }

    /**
     * @param TableQueryDTO $query Window query whose filter names the subject
     * @return TableSnapshotDTO Rows labelled with the subject
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $subject = $query->filter[self::FILTER_SUBJECT] ?? null;
        $rows = is_string($subject)
            ? array_map(static fn(string $rowKey): array => ['key' => $rowKey, 'label' => "{$subject}:Renamed"], self::ROWS)
            : [];

        return $this->filterInMemory($rows, $query);
    }
}

/**
 * An ordinary viewport table over the same source: one row per change, whatever the window.
 */
final class WindowScopedUnitPlainTable extends TableDefinition implements SelfSnapshotTable
{
    public const string TABLE = 'windowScopedPlainTable';

    /**
     * @param SourceChange $change Source change that may affect this table
     * @return ?TableRowMutationDTO Update of the named row, or null for another source
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        if ($change->sourceKey !== WindowScopedUnitTable::SOURCE) {
            return null;
        }

        $name = $change->row['name'] ?? 'unchanged';

        return $this->mutation(
            TableMutationType::Update,
            $change->sourceId,
            new WindowScopedUnitRow((string) $change->sourceId, (string) $name),
        );
    }

    /**
     * @param AbstractTableRow $row Plain row
     * @return array{rowKey: int|string, sources: array<string, mixed>} Internal browser-row envelope
     * @throws TableRowKeyMissingException When the row is a placeholder and carries no key
     */
    public function browserRow(AbstractTableRow $row): array
    {
        return [
            BrowserPageSignalData::rowKey => $row->requireRowKey(),
            BrowserPageSignalData::sources => [WindowScopedUnitTable::SLOT => $row->toArray()],
        ];
    }

    protected function init(): void
    {
        $this->setRowClass(WindowScopedUnitRow::class);
    }

    /**
     * @param TableQueryDTO $query Window query parameters
     * @return TableSnapshotDTO Rows a and b
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        return $this->filterInMemory(
            array_map(static fn(string $rowKey): array => ['key' => $rowKey, 'label' => $rowKey], WindowScopedUnitTable::ROWS),
            $query,
        );
    }
}

final class WindowScopedUnitRow extends AbstractTableRow
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
