<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserFieldKey;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Hilos;
use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Collection\RtCollection;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\RtItem;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for browser signal emission from page-shaped configs.
 */
final class BrowserContextEmitSignalsTest extends TestCase
{
    public function tearDown(): void
    {
        Hilos::$db = null;
        Hilos::$rt = null;
        Hilos::$sr = null;
        Hilos::$table = null;

        parent::tearDown();
    }

    public function testFlushQueuesPageShapedBrowserRowSignalForSubscribedPage(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = new BrowserContextEmitSignalsTestRtContext();
        Hilos::$rt->configure();
        Hilos::$rt->addRow(BrowserContextEmitSignalsTestState::create('1', 'Ada'));
        Hilos::$sr->subscribeToPage(
            BrowserContextEmitSignalsTestContext::PAGE,
            new WebSocketPageSubscribeSignalDTO('ak-1', BrowserContextEmitSignalsTestContext::PAGE),
        );

        $context = new BrowserContextEmitSignalsTestContext();
        $context->record(SourceChange::rtUpdated(BrowserContextEmitSignalsTestRtContext::ROWS, '1', ['name' => 'Ada']));
        $context->flushToSignalRouter();

        $signal = Hilos::$sr->getNextQueuedSignal();

        $this->assertNotNull($signal);
        $this->assertSame(SignalTypeConstants::WS_USER, $signal->signalType->getType());
        $this->assertSame(BrowserContextEmitSignalsTestContext::SIGNAL, $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertSame('ak-1', $signal->data->targetAcceptKey);
        $this->assertInstanceOf(BrowserPageSignalData::class, $signal->data->data);
        $this->assertSame(
            [
                BrowserPageSignalData::tables => [
                    BrowserContextEmitSignalsTestContext::TABLE => [
                        BrowserPageSignalData::rows => [
                            [
                                BrowserPageSignalData::rowKey => '1',
                                BrowserPageSignalData::sources => [
                                    BrowserContextEmitSignalsTestRtContext::ROWS => [
                                        'id' => '1',
                                        'displayName' => 'Ada',
                                        'computedLabel' => 'row-1',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            $signal->data->data->toArray(),
        );
    }

    public function testFlushQueuesPageShapedBrowserDeleteWhenRowIsGone(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = new BrowserContextEmitSignalsTestRtContext();
        Hilos::$rt->configure();
        Hilos::$sr->subscribeToPage(
            BrowserContextEmitSignalsTestContext::PAGE,
            new WebSocketPageSubscribeSignalDTO('ak-1', BrowserContextEmitSignalsTestContext::PAGE),
        );

        $context = new BrowserContextEmitSignalsTestContext();
        $context->record(SourceChange::rtDeleted(BrowserContextEmitSignalsTestRtContext::ROWS, '1', ['id' => '1']));
        $context->flushToSignalRouter();

        $signal = Hilos::$sr->getNextQueuedSignal();

        $this->assertNotNull($signal);
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertInstanceOf(BrowserPageSignalData::class, $signal->data->data);
        $this->assertSame(
            [
                BrowserPageSignalData::tables => [
                    BrowserContextEmitSignalsTestContext::TABLE => [
                        BrowserPageSignalData::deleted => ['1'],
                    ],
                ],
            ],
            $signal->data->data->toArray(),
        );
    }

    public function testFlushIgnoresBrowserRowUpdateOutsideTriggerFields(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = new BrowserContextEmitSignalsTestRtContext();
        Hilos::$rt->configure();
        Hilos::$rt->addRow(BrowserContextEmitSignalsTestState::create('1', 'Ada'));
        Hilos::$sr->subscribeToPage(
            BrowserContextEmitSignalsTestContext::PAGE,
            new WebSocketPageSubscribeSignalDTO('ak-1', BrowserContextEmitSignalsTestContext::PAGE),
        );

        $context = new BrowserContextEmitSignalsTestContext();
        $context->record(SourceChange::rtUpdated(BrowserContextEmitSignalsTestRtContext::ROWS, '1', ['ignored' => 'value']));
        $context->flushToSignalRouter();

        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testSubscribeSnapshotQueuesFullBrowserRowsForPage(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = new BrowserContextEmitSignalsTestRtContext();
        Hilos::$rt->configure();
        Hilos::$rt->addRow(BrowserContextEmitSignalsTestState::create('1', 'Ada'));
        Hilos::$rt->addRow(BrowserContextEmitSignalsTestState::create('2', 'Grace'));

        (new BrowserContextEmitSignalsTestContext())->subscribeSnapshot(
            BrowserContextEmitSignalsTestContext::PAGE,
            'ak-1',
            new PageRouteParams([]),
        );

        $signal = Hilos::$sr->getNextQueuedSignal();

        $this->assertNotNull($signal);
        $this->assertSame(SignalTypeConstants::WS_USER, $signal->signalType->getType());
        $this->assertSame(BrowserContextEmitSignalsTestContext::SIGNAL, $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertSame('ak-1', $signal->data->targetAcceptKey);
        $this->assertInstanceOf(BrowserPageSignalData::class, $signal->data->data);
        $this->assertSame(
            [
                BrowserPageSignalData::tables => [
                    BrowserContextEmitSignalsTestContext::TABLE => [
                        BrowserPageSignalData::rows => [
                            [
                                BrowserPageSignalData::rowKey => '1',
                                BrowserPageSignalData::sources => [
                                    BrowserContextEmitSignalsTestRtContext::ROWS => [
                                        'id' => '1',
                                        'displayName' => 'Ada',
                                        'computedLabel' => 'row-1',
                                    ],
                                ],
                            ],
                            [
                                BrowserPageSignalData::rowKey => '2',
                                BrowserPageSignalData::sources => [
                                    BrowserContextEmitSignalsTestRtContext::ROWS => [
                                        'id' => '2',
                                        'displayName' => 'Grace',
                                        'computedLabel' => 'row-2',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            $signal->data->data->toArray(),
        );
    }

    public function testSubscribeSnapshotCanUseProtectedTopologyHooksWithoutTableContext(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = new BrowserContextEmitSignalsTestRtContext();
        Hilos::$rt->configure();
        Hilos::$rt->addRow(BrowserContextEmitSignalsTestState::create('1', 'Ada'));

        (new BrowserContextTopologyHooksTestContext())->subscribeSnapshot(
            BrowserContextTopologyHooksTestContext::PAGE,
            'ak-1',
            new PageRouteParams([]),
        );

        $signal = Hilos::$sr->getNextQueuedSignal();

        $this->assertNull(Hilos::$table);
        $this->assertNotNull($signal);
        $this->assertSame(BrowserContextTopologyHooksTestContext::SIGNAL, $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertInstanceOf(BrowserPageSignalData::class, $signal->data->data);
        $this->assertSame(
            [
                BrowserPageSignalData::tables => [
                    BrowserContextTopologyHooksTestContext::TABLE => [
                        BrowserPageSignalData::rows => [
                            [
                                BrowserPageSignalData::rowKey => '1',
                                BrowserPageSignalData::sources => [
                                    BrowserContextEmitSignalsTestRtContext::ROWS => [
                                        'id' => '1',
                                        'displayName' => 'Ada',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            $signal->data->data->toArray(),
        );
    }
}

final class BrowserContextEmitSignalsTestContext extends BrowserContext
{
    public const string PAGE = 'unit_browser_page';
    public const string SIGNAL = 'unit_browser_signal';
    public const string TABLE = 'unitRows';

    private const array SOURCE = [
        BrowserSourceKey::TYPE => BrowserSourceType::RT,
        BrowserSourceKey::KEY => BrowserContextEmitSignalsTestRtContext::ROWS,
    ];

    public const array PAGES = [
        self::PAGE => [
            BrowserConfigKey::SIGNAL => self::SIGNAL,
            BrowserConfigKey::TABLES => [
                self::TABLE => [],
            ],
        ],
    ];

    public const array TABLES = [
        self::TABLE => [
            BrowserConfigKey::ROWS => [
                [
                    BrowserFieldKey::SOURCE => self::SOURCE,
                    BrowserFieldKey::ROW_KEY => 'id',
                    BrowserFieldKey::FIELDS => [
                        'id',
                        'name' => 'displayName',
                    ],
                    BrowserFieldKey::COMPUTED => [
                        'computedLabel',
                    ],
                    BrowserFieldKey::TRIGGERS => [
                        'name',
                    ],
                ],
            ],
        ],
    ];

    public function configure(): void
    {
    }

    /**
     * Computes the test-only label declared in the browser table config.
     *
     * @param string $tableKey Browser table key
     * @param string $field Computed field name
     * @param int|string $rowKey Logical browser table row key
     * @param string $acceptKey Subscriber accept key
     * @param array<string, string> $pageParams Current page subscription params
     * @param array<string, mixed> $tableParams Resolved table params
     * @param array<string, mixed> $sources Source fragments already built for the row
     * @return mixed Computed browser field value
     */
    protected function computeBrowserField(
        string $tableKey,
        string $field,
        int|string $rowKey,
        string $acceptKey,
        array $pageParams,
        array $tableParams,
        array $sources,
    ): mixed {
        return $field === 'computedLabel' ? "row-{$rowKey}" : null;
    }
}

final class BrowserContextTopologyHooksTestContext extends BrowserContext
{
    public const string PAGE = 'topology_browser_page';
    public const string SIGNAL = 'topology_browser_signal';
    public const string TABLE = 'topologyRows';

    private const array SOURCE = [
        BrowserSourceKey::TYPE => BrowserSourceType::RT,
        BrowserSourceKey::KEY => BrowserContextEmitSignalsTestRtContext::ROWS,
    ];

    public function configure(): void
    {
    }

    /**
     * Reads page browser topology through the protected hook instead of static::PAGES.
     *
     * @param string $page Page name from the subscription mirror
     * @return array<string, mixed> Browser page config
     */
    protected function resolveBrowserPageConfig(string $page): array
    {
        if ($page !== self::PAGE) {
            return [];
        }

        return [
            BrowserConfigKey::SIGNAL => self::SIGNAL,
            BrowserConfigKey::TABLES => [
                self::TABLE => [],
            ],
        ];
    }

    /**
     * Reads browser-only table topology through the protected hook instead of static::TABLES.
     *
     * @param string $tableKey Browser table key
     * @return ?array<string, mixed> Browser-only table config
     */
    protected function resolveBrowserOnlyTableConfig(string $tableKey): ?array
    {
        if ($tableKey !== self::TABLE) {
            return null;
        }

        return [
            BrowserConfigKey::ROWS => [
                [
                    BrowserFieldKey::SOURCE => self::SOURCE,
                    BrowserFieldKey::ROW_KEY => 'id',
                    BrowserFieldKey::FIELDS => [
                        'id',
                        'name' => 'displayName',
                    ],
                ],
            ],
        ];
    }
}

final class BrowserContextEmitSignalsTestRtContext extends RtContext
{
    public const string ROWS = 'unitRows';

    public function configure(): void
    {
        $this->_stateCollections[self::ROWS] = BrowserContextEmitSignalsTestStates::init();
        $this->setRepresent(self::ROWS, BrowserContextEmitSignalsTestCollection::class);
    }

    public function addRow(BrowserContextEmitSignalsTestState $row): void
    {
        $this->_stateCollections[self::ROWS]->add($row);
    }
}

final class BrowserContextEmitSignalsTestStates extends RtStates
{
    public const string STATE_CLASS = BrowserContextEmitSignalsTestState::class;
}

final class BrowserContextEmitSignalsTestState extends RtState
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
        return new self(
            (string) ($row['id'] ?? ''),
            (string) ($row['name'] ?? ''),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
        ];
    }
}

final class BrowserContextEmitSignalsTestCollection extends RtCollection
{
    protected function createRtItem(RtState &$state): RtItem
    {
        return new BrowserContextEmitSignalsTestItem($state);
    }
}

final class BrowserContextEmitSignalsTestItem extends RtItem
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
