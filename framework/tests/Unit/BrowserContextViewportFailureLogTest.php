<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserPageBindings;
use Hilos\Core\Browser\Config\BrowserPageConfig;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\Table\Definition\SelfSnapshotTable;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\InMemoryTableFilter;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\TableConstants;
use Hilos\Hilos;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * What the server says when a viewport window quietly stops moving (HIL-681).
 *
 * Two doors in BrowserContext swallow a Throwable and return: the delta path around
 * buildMutationForSourceEvent() and the count path around getPage(). Both are entrances for
 * project code — a row may refuse its own payload, and buildMutationForSourceEvent() is
 * declared @throws Throwable — and both failures are per-connection: ONE window freezes on
 * its old rows or its old total while every neighbour stays live. Until these lines existed,
 * the complaint "my list does not update and my colleague's does" left nothing in the journal
 * at all, because a refusal was indistinguishable from the routine "this table is not
 * touched" one line below.
 *
 * The containment itself is not on trial here — HIL-592 asks for it to be kept, so both
 * catches still swallow and still return, and these tests assert exactly that alongside the
 * line. What is on trial is that the failure is now addressable: which table, which page,
 * WHICH connection, and what threw where.
 */
final class BrowserContextViewportFailureLogTest extends TestCase
{
    /** Temporary main log file the assertions read the written lines back from */
    private string $logFile = '';

    protected function setUp(): void
    {
        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-viewport-failure-log');
        Logger::setLogFile($this->logFile);
    }

    protected function tearDown(): void
    {
        Logger::resetLogFile();

        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }

        Hilos::$sr = null;
        Hilos::$table = null;

        parent::tearDown();
    }

    public function testADeltaLostToABrokenRowBuilderNamesTheWindowAndTheCause(): void
    {
        $viewport = new TableViewportSubscription(
            tableKey: ViewportFailureLogUnitTable::TABLE,
            offset: 0,
            limit: 10,
        );
        $viewport->recordWindow(['alpha'], 1);
        $context = $this->boot($viewport, throwOnMutation: true);

        $context->record(SourceChange::dbUpdated(ViewportFailureLogUnitTable::SOURCE_KEY, 'alpha', ['label' => 'Alpha']));
        $context->flushToSignalRouter();

        // The subscriber is told nothing at all — that silence is the defect the line describes.
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());

        $lines = $this->writtenLines();
        $this->assertCount(1, $lines);
        $line = $lines[0];
        $this->assertStringContainsString('Viewport delta skipped a change the table failed to build', $line);
        $this->assertStringContainsString('table=' . ViewportFailureLogUnitTable::TABLE, $line);
        $this->assertStringContainsString('page=' . ViewportFailureLogUnitContext::PAGE, $line);
        $this->assertStringContainsString('acceptKey=ak-1', $line);
        $this->assertStringContainsString(
            'source=' . SourceChange::KIND_DB . ':' . ViewportFailureLogUnitTable::SOURCE_KEY . '#alpha',
            $line,
        );
        $this->assertStringContainsString('exception=' . RuntimeException::class, $line);
        $this->assertStringContainsString('message=' . ViewportFailureLogUnitTable::MUTATION_FAILURE, $line);
        $this->assertStringContainsString('at=BrowserContextViewportFailureLogTest.php:', $line);
    }

    public function testACountRequeryThatFailedKeepsTheStaleTotalAndSaysSo(): void
    {
        // A search makes the total a re-query rather than arithmetic on the mutation type;
        // without one the count is derived from the type and getPage is never reached.
        $viewport = new TableViewportSubscription(
            tableKey: ViewportFailureLogUnitTable::TABLE,
            filter: [TableConstants::FILTER_KEY_SEARCH => 'alp'],
            offset: 0,
            limit: 10,
        );
        $viewport->recordWindow(['alpha'], 7);
        $context = $this->boot($viewport, throwOnQuery: true);

        // A row outside the delivered set: its edit shifts only the total, so the count path
        // is the whole story and no delta competes with it.
        $context->record(SourceChange::dbUpdated(ViewportFailureLogUnitTable::SOURCE_KEY, 'beta', ['label' => 'Beta']));
        $context->flushToSignalRouter();

        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
        $this->assertSame(7, $viewport->totalCount());

        $lines = $this->writtenLines();
        $this->assertCount(1, $lines);
        $line = $lines[0];
        $this->assertStringContainsString('Viewport count kept a stale total after its re-query failed', $line);
        $this->assertStringContainsString('table=' . ViewportFailureLogUnitTable::TABLE, $line);
        $this->assertStringContainsString('page=' . ViewportFailureLogUnitContext::PAGE, $line);
        $this->assertStringContainsString('acceptKey=ak-1', $line);
        $this->assertStringContainsString('exception=' . RuntimeException::class, $line);
        $this->assertStringContainsString('message=' . ViewportFailureLogUnitTable::QUERY_FAILURE, $line);
        $this->assertStringContainsString('at=BrowserContextViewportFailureLogTest.php:', $line);
    }

    public function testATableUntouchedByTheChangeStaysSilent(): void
    {
        $viewport = new TableViewportSubscription(
            tableKey: ViewportFailureLogUnitTable::TABLE,
            offset: 0,
            limit: 10,
        );
        $viewport->recordWindow(['alpha'], 1);
        $context = $this->boot($viewport);

        // Another collection entirely: the table answers null, which is the ordinary
        // every-second outcome and must not reach the journal.
        $context->record(SourceChange::dbUpdated('someOtherSource', 'alpha', ['label' => 'Alpha']));
        $context->flushToSignalRouter();

        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
        $this->assertSame([], $this->writtenLines());
    }

    /**
     * Boots the table registry, the page subscription and the connection's viewport.
     *
     * @param TableViewportSubscription $viewport Viewport to register for accept key ak-1
     * @param bool $throwOnMutation Whether the table refuses to build a row mutation
     * @param bool $throwOnQuery Whether the table refuses to serve a window
     * @return ViewportFailureLogUnitContext Booted browser context
     */
    private function boot(
        TableViewportSubscription $viewport,
        bool $throwOnMutation = false,
        bool $throwOnQuery = false,
    ): ViewportFailureLogUnitContext {
        Hilos::$sr = new SignalRouter();
        Hilos::$table = new ViewportFailureLogUnitTableContext($throwOnMutation, $throwOnQuery);
        Hilos::$table->configure();
        Hilos::$sr->subscribeToPage(
            ViewportFailureLogUnitContext::PAGE,
            new WebSocketPageSubscribeSignalDTO('ak-1', ViewportFailureLogUnitContext::PAGE),
        );
        Hilos::$sr->setTableViewport('ak-1', $viewport);

        return new ViewportFailureLogUnitContext();
    }

    /**
     * Reads back the journal lines written since the test started.
     *
     * @return list<string> Written lines, empty when the journal stayed silent
     */
    private function writtenLines(): array
    {
        if (!is_file($this->logFile)) {
            return [];
        }

        $written = rtrim((string)file_get_contents($this->logFile), "\n");
        if ($written === '') {
            return [];
        }

        return explode("\n", $written);
    }
}

final class ViewportFailureLogUnitContext extends BrowserContext
{
    public const string PAGE = 'viewport_failure_log_page';
    public const string SIGNAL = 'viewport_failure_log_signal';

    /**
     * Resolves the test page browser metadata.
     *
     * @param string $page Page name from the subscription mirror
     * @return ?BrowserPageConfig Test page metadata, or null when absent
     * @throws PageInternalErrorException When a page or source declaration is malformed
     */
    protected function resolveBrowserPageConfig(string $page): ?BrowserPageConfig
    {
        if ($page !== self::PAGE) {
            return null;
        }

        return BrowserPageConfig::fromArray([
            BrowserConfigKey::SIGNAL => self::SIGNAL,
        ]);
    }

    /**
     * Binds the test page to the failing viewport table.
     *
     * @param string $page Page name from the subscription mirror
     * @return BrowserPageBindings Test page table bindings
     */
    protected function resolveBrowserPageBindings(string $page): BrowserPageBindings
    {
        if ($page !== self::PAGE) {
            return BrowserPageBindings::empty();
        }

        return BrowserPageBindings::fromArray([
            ViewportFailureLogUnitTable::TABLE => [],
        ]);
    }
}

final class ViewportFailureLogUnitTableContext extends TableContext
{
    /**
     * @param bool $throwOnMutation Whether the table refuses to build a row mutation
     * @param bool $throwOnQuery Whether the table refuses to serve a window
     */
    public function __construct(
        private readonly bool $throwOnMutation = false,
        private readonly bool $throwOnQuery = false,
    ) {
    }

    public function configure(): void
    {
        $this->register(
            ViewportFailureLogUnitTable::TABLE,
            new ViewportFailureLogUnitTable($this->throwOnMutation, $this->throwOnQuery),
        );
    }
}

final class ViewportFailureLogUnitTable extends TableDefinition implements SelfSnapshotTable
{
    public const string TABLE = 'viewportFailureLogTable';
    public const string SLOT = 'viewportFailureLogRows';
    public const string SOURCE_KEY = 'viewportFailureLogSource';

    /** Message the refused row-mutation build carries into the journal line */
    public const string MUTATION_FAILURE = 'the project row builder gave up';

    /** Message the refused window query carries into the journal line */
    public const string QUERY_FAILURE = 'the project row source gave up';

    /**
     * @param bool $throwOnMutation Whether the table refuses to build a row mutation
     * @param bool $throwOnQuery Whether the table refuses to serve a window
     */
    public function __construct(
        private readonly bool $throwOnMutation = false,
        private readonly bool $throwOnQuery = false,
    ) {
        parent::__construct();
    }

    /**
     * Maps a source change of this table's own collection to an update mutation.
     *
     * @param SourceChange $change Source change that may affect this table
     * @return ?TableRowMutationDTO Row mutation, or null for another source
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        if ($change->sourceKey !== self::SOURCE_KEY) {
            return null;
        }
        if ($this->throwOnMutation) {
            throw new RuntimeException(self::MUTATION_FAILURE);
        }

        return $this->mutation($change->mutationType, $change->sourceId);
    }

    /**
     * Serializes a row into its internal browser-row envelope.
     *
     * @param AbstractTableRow $row Self-snapshot row
     * @return array{rowKey: int|string, sources: array<string, mixed>} Internal browser-row envelope
     * @throws TableRowKeyMissingException When the row is a placeholder and carries no key
     */
    public function browserRow(AbstractTableRow $row): array
    {
        return [
            BrowserPageSignalData::rowKey => $row->requireRowKey(),
            BrowserPageSignalData::sources => [
                self::SLOT => $row->toArray(),
            ],
        ];
    }

    /**
     * Configures the row class so makeRows rebuilds typed rows from the filter output.
     */
    protected function init(): void
    {
        $this->setRowClass(ViewportFailureLogUnitRow::class);
    }

    /**
     * Serves an empty window, or refuses it when the fixture was asked to.
     *
     * @param TableQueryDTO $query Window query parameters
     * @return TableSnapshotDTO Windowed snapshot
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        if ($this->throwOnQuery) {
            throw new RuntimeException(self::QUERY_FAILURE);
        }

        return InMemoryTableFilter::apply([], $query);
    }
}

final class ViewportFailureLogUnitRow extends AbstractTableRow
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
    ) {
    }

    public function getRowKey(): string
    {
        return $this->key;
    }

    /**
     * @return array<string, mixed> Row fields
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static Row instance
     */
    public static function fromArray(array $data): static
    {
        return new static(
            (string) $data['key'],
            (string) $data['label'],
        );
    }
}
