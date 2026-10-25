<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use ArrayObject;
use Generator;
use Hilos\AdminViewMode\HiddenValue;
use Hilos\Backup\Anonymization\PiiRegistry;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserFieldKey;
use Hilos\Core\Browser\Config\BrowserPageBindings;
use Hilos\Core\Browser\Config\BrowserPageConfig;
use Hilos\Core\Browser\Config\BrowserSourceConfig;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\Hilos;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/** Tests the optional conversion through the real snapshot and fan-out builders. */
final class BrowserContextSqlMomentsTest extends TestCase
{
    private string $originalTimeZone;
    private ArrayObject $rows;
    private ArrayObject $children;

    protected function setUp(): void
    {
        $this->originalTimeZone = date_default_timezone_get();
        date_default_timezone_set(TimeConstants::UTC_TIME_ZONE);
        $this->rows = new ArrayObject([
            '1' => [
                'id' => '1',
                'createdAt' => '2026-01-01 00:00:00',
                'expiresAt' => null,
                'updatedAt' => '2026-01-02 00:00:00',
                'invalidAt' => 'unreadable SQL datetime',
            ],
        ]);
        $this->children = new ArrayObject([
            '2' => ['id' => '2', 'createdAt' => '2026-01-02 00:00:00', 'expiresAt' => null],
            '3' => ['id' => '3', 'createdAt' => '2026-01-01 00:00:00', 'expiresAt' => null],
        ]);
        Hilos::$db = new SqlMomentsDbContext($this->rows, $this->children);
        Hilos::$sr = new SignalRouter();
    }

    protected function tearDown(): void
    {
        Hilos::$db = null;
        Hilos::$sr = null;
        Hilos::resetBrowser();
        date_default_timezone_set($this->originalTimeZone);

        parent::tearDown();
    }

    public function testSnapshotConvertsOnlyDeclaredSourceFieldsBeforeMapping(): void
    {
        $snapshot = $this->snapshot(new SqlMomentsBrowserContext());

        $this->assertSame([
            PagePayload::tables => [SqlMomentsBrowserContext::TABLE => [PagePayload::rows => [[
                PagePayload::rowKey => '1',
                PagePayload::slots => [SqlMomentsDbContext::ROWS => [
                    'id' => '1',
                    'created' => 1767225600000,
                    'expiresAt' => null,
                    'updatedAt' => '2026-01-02 00:00:00',
                    'invalidAt' => 0,
                    'computedAt' => '2026-01-03 00:00:00',
                ]],
            ]]]],
        ], $snapshot->toArray());
        $this->assertSame('2026-01-01 00:00:00', $this->rows['1']['createdAt']);
    }

    public function testSqlFieldsWithoutTheDeclarationKeepTheirValues(): void
    {
        $snapshot = $this->snapshot(new SqlMomentsBrowserContext(sqlMoments: null));

        $this->assertSame([
            'id' => '1',
            'created' => '2026-01-01 00:00:00',
            'expiresAt' => null,
            'updatedAt' => '2026-01-02 00:00:00',
            'invalidAt' => 'unreadable SQL datetime',
            'computedAt' => '2026-01-03 00:00:00',
        ], $this->fragment($snapshot));
    }

    public function testWhereStillMatchesTheOriginalSqlValue(): void
    {
        $snapshot = $this->snapshot(new SqlMomentsBrowserContext(where: ['createdAt' => '2026-01-01 00:00:00']));

        $this->assertSame(1767225600000, $this->fragment($snapshot)['created']);
    }

    /**
     * @param string $anchorField Plain source field or mapped target used by VIA
     * @param bool $many Whether the joined fragment is a list
     * @param bool $dbLookup Whether the child source exercises the DB lookup path
     */
    #[DataProvider('viaCases')]
    public function testSnapshotJoinsTheOriginalSqlMoment(string $anchorField, bool $many, bool $dbLookup): void
    {
        if ($dbLookup) {
            $this->useDbJoinLookup(1);
        }
        $snapshot = $this->snapshot(new SqlMomentsBrowserContext(viaField: $anchorField, many: $many));

        $this->assertViaPayload($snapshot, $anchorField, $many, '3', 1767225600000);
        $this->assertSame('2026-01-01 00:00:00', $this->rows['1']['createdAt']);
        $this->assertSame('2026-01-01 00:00:00', $this->children['3']['createdAt']);
    }

    /**
     * @param string $anchorField Plain source field or mapped target used by VIA
     * @param bool $many Whether the joined fragment is a list
     * @param bool $dbLookup Whether the child source exercises the DB lookup path
     */
    #[DataProvider('viaCases')]
    public function testLiveRebuildJoinsTheOriginalSqlMoment(string $anchorField, bool $many, bool $dbLookup): void
    {
        if ($dbLookup) {
            $this->useDbJoinLookup(4);
        }
        $context = new SqlMomentsBrowserContext(viaField: $anchorField, many: $many);
        $this->snapshot($context);
        Hilos::$sr->subscribeToPage(
            SqlMomentsBrowserContext::PAGE,
            new WebSocketPageSubscribeSignalDTO('ak-sql', SqlMomentsBrowserContext::PAGE),
        );

        $context->record(SourceChange::dbUpdated(SqlMomentsDbContext::ROWS, '1', ['createdAt' => '2026-01-01 00:00:00']));
        $this->assertSame([], $context->flushToSignalRouter());
        $signal = Hilos::$sr->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
        $this->assertViaPayload($signal->data->data->payload, $anchorField, $many, '3', 1767225600000);

        $this->rows['1']['createdAt'] = '2026-01-02 00:00:00';
        $context->record(SourceChange::dbUpdated(SqlMomentsDbContext::ROWS, '1', ['createdAt' => '2026-01-02 00:00:00']));
        $this->assertSame([], $context->flushToSignalRouter());
        $signal = Hilos::$sr->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
        $this->assertViaPayload($signal->data->data->payload, $anchorField, $many, '2', 1767312000000);
        $this->assertSame($this->snapshot($context)->toArray(), $signal->data->data->payload->toArray());
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    /** @return iterable<string, array{string, bool, bool}> VIA addressing, cardinality and lookup boundary */
    public static function viaCases(): iterable
    {
        yield 'plain single' => ['createdAt', false, false];
        yield 'mapped single' => ['created', false, false];
        yield 'plain many' => ['createdAt', true, false];
        yield 'mapped many' => ['created', true, false];
        yield 'DB plain single' => ['createdAt', false, true];
        yield 'DB mapped single' => ['created', false, true];
        yield 'DB plain many' => ['createdAt', true, true];
        yield 'DB mapped many' => ['created', true, true];
    }

    public function testLiveRebuildAndSnapshotUseTheSameConversion(): void
    {
        $context = new SqlMomentsBrowserContext();
        $snapshot = $this->snapshot($context);
        Hilos::$sr->subscribeToPage(
            SqlMomentsBrowserContext::PAGE,
            new WebSocketPageSubscribeSignalDTO('ak-sql', SqlMomentsBrowserContext::PAGE),
        );

        $context->record(SourceChange::dbUpdated(SqlMomentsDbContext::ROWS, '1', ['createdAt' => '2026-01-01 00:00:00']));
        $this->assertSame([], $context->flushToSignalRouter());
        $signal = Hilos::$sr->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
        $this->assertSame($snapshot->toArray(), $signal->data->data->payload->toArray());

        $this->rows['1']['expiresAt'] = '2026-01-02 00:00:00';
        $context->record(SourceChange::dbUpdated(SqlMomentsDbContext::ROWS, '1', ['expiresAt' => '2026-01-02 00:00:00']));
        $this->assertSame([], $context->flushToSignalRouter());
        $signal = Hilos::$sr->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
        $live = $signal->data->data->payload;
        $this->assertSame($this->snapshot($context)->toArray(), $live->toArray());
        $this->assertSame(1767312000000, $this->fragment($live)['expiresAt']);
    }

    public function testSqlMomentsDoesNotOpenPersonalFieldsToAViewer(): void
    {
        $context = new SqlMomentsBrowserContext(viewer: true);
        $context->bindHilosFacade(SqlMomentsHilos::class);
        $snapshot = $this->snapshot($context);

        $this->assertSame(HiddenValue::mark(), $this->fragment($snapshot)['created']);
    }

    /** @param mixed $value Non-string source value that must refuse projection */
    #[DataProvider('invalidMomentValues')]
    public function testNonNullSqlMomentMustBeAString(mixed $value): void
    {
        $this->rows['1']['createdAt'] = $value;

        $this->expectException(PageInternalErrorException::class);
        $this->expectExceptionMessage('sqlMoments field createdAt must be a string or null');

        $this->snapshot(new SqlMomentsBrowserContext());
    }

    /** @return iterable<string, array{mixed}> Ambiguous source value types */
    public static function invalidMomentValues(): iterable
    {
        yield 'integer' => [1767225600000];
        yield 'float' => [1767225600000.0];
        yield 'bool' => [false];
        yield 'array' => [[]];
        yield 'object' => [new stdClass()];
    }

    /** @param int $lookupCount Number of snapshot and live row builds */
    private function useDbJoinLookup(int $lookupCount): void
    {
        $children = $this->createMock(DbCollection::class);
        $children->method('isKeyColumn')->with('createdAt')->willReturn(false);
        $children->expects($this->exactly($lookupCount))
            ->method('whereColumnIs')
            ->with('createdAt', $this->logicalOr(
                $this->identicalTo('2026-01-01 00:00:00'),
                $this->identicalTo('2026-01-02 00:00:00'),
            ))
            ->willReturnSelf();
        $children->method('getIterator')->willReturnCallback(function (): Generator {
            yield from $this->children;
        });
        Hilos::$db = new SqlMomentsDbContext($this->rows, $children);
    }

    /**
     * @param PagePayload $payload Public snapshot or live rebuild
     * @param string $anchorField Plain source field or mapped target used by VIA
     * @param bool $many Whether the joined fragment is a list
     * @param string $childId Only child whose raw SQL moment matches the anchor
     * @param int $createdAt Expected UTC epoch milliseconds
     */
    private function assertViaPayload(PagePayload $payload, string $anchorField, bool $many, string $childId, int $createdAt): void
    {
        $child = ['id' => $childId, 'childCreated' => $createdAt, 'expiresAt' => null, 'anchorCreated' => $createdAt];
        $this->assertSame([
            PagePayload::tables => [SqlMomentsBrowserContext::TABLE => [PagePayload::rows => [[
                PagePayload::rowKey => '1',
                PagePayload::slots => [
                    SqlMomentsDbContext::ROWS => [
                        'id' => '1',
                        $anchorField => $createdAt,
                        'expiresAt' => null,
                        'updatedAt' => '2026-01-02 00:00:00',
                        'invalidAt' => 0,
                        'computedAt' => '2026-01-03 00:00:00',
                    ],
                    SqlMomentsDbContext::CHILDREN => $many ? [$child] : $child,
                ],
            ]]]],
        ], $payload->toArray());
    }

    /**
     * @param BrowserContext $context Real browser builder with fixture metadata
     * @return PagePayload Public subscription snapshot
     */
    private function snapshot(BrowserContext $context): PagePayload
    {
        return $context->buildSubscribeSnapshot(SqlMomentsBrowserContext::PAGE, 'ak-sql', new PageRouteParams([]));
    }

    /**
     * @param PagePayload $snapshot Public browser payload
     * @return array<string, mixed> Projected DB fragment of its first row
     */
    private function fragment(PagePayload $snapshot): array
    {
        $rows = $snapshot->tables[SqlMomentsBrowserContext::TABLE][PagePayload::rows];

        return $rows[0][PagePayload::slots][SqlMomentsDbContext::ROWS];
    }
}

/** A DB source boundary with in-test rows; no ORM model or database is needed for projection. */
final class SqlMomentsDbContext extends HilosDbContext
{
    public const string ROWS = 'sqlMomentRows';
    public const string CHILDREN = 'sqlMomentChildren';

    /**
     * @param ArrayObject $rows Anchor rows shared with the test
     * @param ArrayObject|DbCollection $children Iterable or DB lookup boundary for joined rows
     */
    public function __construct(
        private readonly ArrayObject $rows,
        private readonly ArrayObject|DbCollection $children = new ArrayObject(),
    ) {
        parent::__construct();
    }

    /**
     * @param string $name Collection key
     * @return ArrayObject|DbCollection Fixture collection for the declared source
     */
    public function __get(string $name)
    {
        return $name === self::CHILDREN ? $this->children : $this->rows;
    }
}

final class SqlMomentsHilos extends Hilos
{
    public const array PAGES = [SqlMomentsBrowserContext::PAGE => AbstractPage::class];

    /** @return HilosDbContext Empty fixture database boundary */
    protected static function createDb(): HilosDbContext
    {
        return new SqlMomentsDbContext(new ArrayObject());
    }
}

final class SqlMomentsBrowserContext extends BrowserContext
{
    public const string PAGE = 'sql_moments_page';
    public const string TABLE = 'sqlMomentsTable';

    /**
     * @param ?list<string> $sqlMoments Optional source-field declaration, null to omit it
     * @param bool $viewer Whether the fixture connection is a viewer
     * @param array<string, mixed> $where Source predicates for the fixture row
     * @param ?string $viaField Plain or mapped anchor field used by the optional child source
     * @param bool $many Whether the optional child source projects a list
     */
    public function __construct(
        private readonly ?array $sqlMoments = ['createdAt', 'expiresAt', 'invalidAt'],
        private readonly bool $viewer = false,
        private readonly array $where = [],
        private readonly ?string $viaField = null,
        private readonly bool $many = false,
    ) {
        parent::__construct();
    }

    /**
     * @param string $page Fixture page key
     * @return BrowserPageConfig Page with browser delivery enabled
     */
    protected function resolveBrowserPageConfig(string $page): BrowserPageConfig
    {
        return BrowserPageConfig::fromArray([BrowserConfigKey::SIGNAL => 'sql_moments_signal']);
    }

    /**
     * @param string $page Fixture page key
     * @return BrowserPageBindings Single fixture table binding
     */
    protected function resolveBrowserPageBindings(string $page): BrowserPageBindings
    {
        return BrowserPageBindings::fromArray([self::TABLE => []]);
    }

    /**
     * @param string $browserKey Fixture table key
     * @return BrowserSourceConfig Projection with optional SQL moments
     */
    protected function resolveBrowserOnlyConfig(string $browserKey): BrowserSourceConfig
    {
        $row = [
            BrowserTableFieldKey::SOURCE => [
                BrowserSourceKey::TYPE => BrowserSourceType::DB,
                BrowserSourceKey::KEY => SqlMomentsDbContext::ROWS,
            ],
            BrowserTableFieldKey::ROW_KEY => 'id',
            BrowserFieldKey::FIELDS => ['id', 'createdAt' => $this->viaField ?? 'created', 'expiresAt', 'updatedAt', 'invalidAt'],
            BrowserFieldKey::COMPUTED => ['computedAt'],
            BrowserFieldKey::TRIGGERS => ['createdAt', 'expiresAt'],
            BrowserFieldKey::WHERE => $this->where,
            BrowserFieldKey::NOT_PERSONAL => ['created'],
        ];
        if ($this->sqlMoments !== null) {
            $row[BrowserTableFieldKey::SQL_MOMENTS] = $this->sqlMoments;
        }

        $rows = [$row];
        if ($this->viaField !== null) {
            $rows[] = [
                BrowserFieldKey::SOURCE => [
                    BrowserSourceKey::TYPE => BrowserSourceType::DB,
                    BrowserSourceKey::KEY => SqlMomentsDbContext::CHILDREN,
                ],
                BrowserFieldKey::MANY => $this->many,
                BrowserFieldKey::VIA => ['createdAt' => $this->viaField],
                BrowserFieldKey::FIELDS => ['id', 'createdAt' => 'childCreated', 'expiresAt'],
                BrowserFieldKey::SQL_MOMENTS => ['createdAt', 'expiresAt'],
                BrowserFieldKey::COMPUTED => ['anchorCreated'],
            ];
        }

        return BrowserSourceConfig::fromArray([BrowserTableConfigKey::ROWS => $rows]);
    }

    /**
     * @param string $browserKey Fixture table key
     * @param string $field Computed field name
     * @param int|string $rowKey Logical row key
     * @param string $acceptKey Subscriber key
     * @param array<string, string> $pageParams Page parameters
     * @param array<string, mixed> $browserParams Source parameters
     * @param array<string, mixed> $sources Earlier source fragments
     * @return int|string Earlier serialized anchor value or an unchanged SQL-looking string
     */
    protected function computeBrowserField(
        string $browserKey,
        string $field,
        int|string $rowKey,
        string $acceptKey,
        array $pageParams,
        array $browserParams,
        array $sources,
    ): int|string {
        if ($field === 'anchorCreated') {
            return $sources[SqlMomentsDbContext::ROWS][$this->viaField];
        }

        return '2026-01-03 00:00:00';
    }

    /**
     * @param string $pageClass Page class registered by the fixture
     * @param string $acceptKey Fixture connection key
     * @return bool Injected viewer verdict
     */
    public function isAdminViewModeViewer(string $pageClass, string $acceptKey): bool
    {
        return $this->viewer;
    }

    /** @return ?PiiRegistry No fixture column is declared public */
    protected function viewerPiiRegistry(): ?PiiRegistry
    {
        return null;
    }
}
