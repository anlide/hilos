<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserPageBindings;
use Hilos\Core\Browser\Config\BrowserPageConfig;
use Hilos\Core\Browser\Config\BrowserSourceConfig;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Database\Context\DbContext;
use Hilos\Hilos;
use Hilos\Runtime\RtStaleness;
use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Collection\RtCollection;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\RtItem;
use PHPUnit\Framework\TestCase;

/**
 * The freshness a table row carries per source (HIL-800).
 *
 * A table is assembled out of several sources, and on a cluster one of them can fall behind
 * while the connection and every other column stay live. The row says which of its sources
 * that happened to, so a view can mark those cells and leave the rest alone; showing
 * yesterday's number beside today's with nothing to tell them apart is the outcome this
 * exists to prevent.
 *
 * Two things are pinned here, and they are the two halves of "the mark is about the source,
 * not about the content". The row names the frozen source and only that one — a database
 * source is never named, because a cluster shares one database and its rows have no second
 * copy to fall behind. And the mark stays out of the delivered row's digest, so a link
 * dropping raises no content delta for a row whose content did not move.
 */
final class BrowserContextRowStalenessTest extends TestCase
{
    protected function setUp(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = new RowStalenessRtContext();
        Hilos::$rt->configure();
        Hilos::$db = new RowStalenessDbContext();
    }

    protected function tearDown(): void
    {
        RtStaleness::reset();
        Hilos::$sr = null;
        Hilos::$rt = null;
        Hilos::$db = null;
        Hilos::resetBrowser();

        parent::tearDown();
    }

    public function testRowNamesTheFrozenRuntimeSourceAndNeverTheDatabaseOne(): void
    {
        Hilos::$rt->addRow(RowStalenessState::create('1', 'Ada'));
        // Both collection keys are marked, and only one of them can answer for a row: the
        // database half is what a mark on a shared database would be - a contradiction the
        // row must not repeat back to the client.
        RtStaleness::mark(RowStalenessRtContext::ROWS, ['1'], 1000.0);
        RtStaleness::mark(RowStalenessDbContext::NOTES, ['1'], 1000.0);

        $rows = $this->snapshotRows();

        $this->assertCount(1, $rows);
        $this->assertSame([RowStalenessRtContext::ROWS], $rows[0][PagePayload::staleSources]);
        $this->assertSame(
            [RowStalenessRtContext::ROWS, RowStalenessDbContext::NOTES],
            array_keys($rows[0][PagePayload::slots]),
            'the frozen source is named beside the fresh one, not instead of it',
        );
    }

    public function testARowWhoseSourcesAreAllCurrentCarriesNoFreshnessKeyAtAll(): void
    {
        Hilos::$rt->addRow(RowStalenessState::create('1', 'Ada'));

        $rows = $this->snapshotRows();

        $this->assertCount(1, $rows);
        $this->assertArrayNotHasKey(PagePayload::staleSources, $rows[0]);
    }

    public function testOnlyTheFrozenRowOfACollectionIsNamed(): void
    {
        Hilos::$rt->addRow(RowStalenessState::create('1', 'Ada'));
        Hilos::$rt->addRow(RowStalenessState::create('2', 'Grace'));
        RtStaleness::mark(RowStalenessRtContext::ROWS, ['2'], 1000.0);

        $rows = $this->snapshotRows();

        $this->assertCount(2, $rows);
        $this->assertArrayNotHasKey(PagePayload::staleSources, $rows[0]);
        $this->assertSame([RowStalenessRtContext::ROWS], $rows[1][PagePayload::staleSources]);
    }

    /**
     * The digest answers "is this the same content", and freshness is not content: counted in,
     * a link dropping would raise a content delta for every row of the window.
     */
    public function testTheFreshnessListStaysOutOfTheDeliveredRowDigest(): void
    {
        $viewport = new TableViewportSubscription('rows');
        $fresh = [
            PagePayload::rowKey => '1',
            PagePayload::slots => [RowStalenessRtContext::ROWS => ['id' => '1', 'name' => 'Ada']],
        ];
        $frozen = [...$fresh, PagePayload::staleSources => [RowStalenessRtContext::ROWS]];

        $viewport->recordWindow(['1' => $fresh], 1, true, null, null);

        $this->assertTrue($viewport->matchesRow('1', $frozen), 'the mark appearing is not a content change');

        $viewport->recordWindow(['1' => $frozen], 1, true, null, null);

        $this->assertTrue($viewport->matchesRow('1', $fresh), 'the mark clearing is not a content change either');
        $this->assertFalse(
            $viewport->matchesRow('1', [
                PagePayload::rowKey => '1',
                PagePayload::slots => [RowStalenessRtContext::ROWS => ['id' => '1', 'name' => 'Grace']],
            ]),
            'a value that did move is still seen',
        );
    }

    /**
     * Serves the test page to one subscriber and reads the table rows out of the page answer.
     *
     * @return list<array<string, mixed>> Wire rows of the page's only table
     */
    private function snapshotRows(): array
    {
        new RowStalenessBrowserContext()->subscribeSnapshot(
            RowStalenessBrowserContext::PAGE,
            'ak-1',
            new PageRouteParams([]),
        );

        $signal = Hilos::$sr?->getNextQueuedSignal();

        $this->assertNotNull($signal);
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertInstanceOf(PageResponseSignalData::class, $signal->data->data);

        $payload = $signal->data->data->toArray();
        $rows = $payload[PageResponseSignalData::payload][PagePayload::tables][RowStalenessBrowserContext::TABLE]
            [PagePayload::rows] ?? [];

        $this->assertIsArray($rows);

        return array_values($rows);
    }
}

/**
 * A page with one table assembled out of a runtime source and a database source.
 */
final class RowStalenessBrowserContext extends BrowserContext
{
    public const string PAGE = 'staleness_browser_page';
    public const string SIGNAL = 'staleness_browser_signal';
    public const string TABLE = 'stalenessRows';

    /**
     * Resolves the test page's browser metadata.
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
     * Resolves the test page's table bindings.
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
            self::TABLE => [],
        ]);
    }

    /**
     * Resolves the test table's browser-only config: a runtime anchor and a database join.
     *
     * @param string $browserKey Browser table key
     * @return ?BrowserSourceConfig Test browser-only table config
     */
    protected function resolveBrowserOnlyConfig(string $browserKey): ?BrowserSourceConfig
    {
        if ($browserKey !== self::TABLE) {
            return null;
        }

        return BrowserSourceConfig::fromArray([
            BrowserTableConfigKey::ROWS => [
                [
                    BrowserTableFieldKey::SOURCE => [
                        BrowserSourceKey::TYPE => BrowserSourceType::RT,
                        BrowserSourceKey::KEY => RowStalenessRtContext::ROWS,
                    ],
                    BrowserTableFieldKey::ROW_KEY => 'id',
                    BrowserTableFieldKey::FIELDS => ['id', 'name'],
                ],
                [
                    BrowserTableFieldKey::SOURCE => [
                        BrowserSourceKey::TYPE => BrowserSourceType::DB,
                        BrowserSourceKey::KEY => RowStalenessDbContext::NOTES,
                    ],
                    BrowserTableFieldKey::ROW_KEY => 'id',
                    BrowserTableFieldKey::FIELDS => ['note'],
                ],
            ],
        ]);
    }
}

/**
 * A database context whose one collection is a plain row set: the browser reads it the same
 * way, and the row it produces is what matters here rather than the query behind it.
 */
final class RowStalenessDbContext extends DbContext
{
    public const string NOTES = 'stalenessNotes';

    /**
     * @param string $name Collection name being read
     * @return list<array<string, mixed>> Rows of the fixture collection, empty for any other name
     */
    public function __get(string $name)
    {
        return $name === self::NOTES
            ? [['id' => '1', 'note' => 'first'], ['id' => '2', 'note' => 'second']]
            : [];
    }

    public function configure(): void
    {
    }
}

final class RowStalenessRtContext extends RtContext
{
    public const string ROWS = 'stalenessPeople';

    public function configure(): void
    {
        $this->_stateCollections[self::ROWS] = RowStalenessStates::init();
        $this->setRepresent(self::ROWS, RowStalenessCollection::class);
    }

    public function addRow(RowStalenessState $row): void
    {
        $this->_stateCollections[self::ROWS]->add($row);
    }
}

final class RowStalenessStates extends RtStates
{
    public const string STATE_CLASS = RowStalenessState::class;
}

final class RowStalenessState extends RtState
{
    private function __construct(
        public readonly string $id,
        public readonly string $name,
    ) {
        parent::__construct();
    }

    public static function create(string $id, string $name): self
    {
        return new self($id, $name);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public static function fromRow(array $row): static
    {
        return new static((string) $row['id'], (string) $row['name']);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
        ];
    }
}

final class RowStalenessCollection extends RtCollection
{
    protected function createRtItem(RtState $state): RtItem
    {
        return new RowStalenessItem($state);
    }
}

final class RowStalenessItem extends RtItem
{
    public function __get(string $name): mixed
    {
        $data = $this->toArray();

        return $data[$name] ?? parent::__get($name);
    }

    public function toArray(): array
    {
        return $this->getState()->toArray();
    }
}
