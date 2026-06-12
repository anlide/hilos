<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserFieldKey;
use Hilos\Core\Browser\Config\BrowserPageConfig;
use Hilos\Core\Browser\Config\BrowserPageTableBindings;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;
use Hilos\Core\Browser\Config\BrowserSourceConfig;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Database\Context\DbContext;
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
        Hilos::resetBrowser();

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
        $this->assertSame(SignalTypeConstants::PAGE_RESPONSE, $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertSame('ak-1', $signal->data->targetAcceptKey);
        $this->assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
        $this->assertSame(
            [
                PageResponseSignalData::page => BrowserContextEmitSignalsTestContext::PAGE,
                PageResponseSignalData::payload => [
                    PagePayload::tables => [
                        BrowserContextEmitSignalsTestContext::TABLE => [
                            PagePayload::rows => [
                                [
                                    PagePayload::rowKey => '1',
                                    PagePayload::slots => [
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
        $this->assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
        $this->assertSame(
            [
                PageResponseSignalData::page => BrowserContextEmitSignalsTestContext::PAGE,
                PageResponseSignalData::payload => [
                    PagePayload::tables => [
                        BrowserContextEmitSignalsTestContext::TABLE => [
                            PagePayload::deleted => ['1'],
                        ],
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
        $this->assertSame(SignalTypeConstants::PAGE_RESPONSE, $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertSame('ak-1', $signal->data->targetAcceptKey);
        $this->assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
        $this->assertSame(
            [
                PageResponseSignalData::page => BrowserContextEmitSignalsTestContext::PAGE,
                PageResponseSignalData::payload => [
                    PagePayload::tables => [
                        BrowserContextEmitSignalsTestContext::TABLE => [
                            PagePayload::rows => [
                                [
                                    PagePayload::rowKey => '1',
                                    PagePayload::slots => [
                                        BrowserContextEmitSignalsTestRtContext::ROWS => [
                                            'id' => '1',
                                            'displayName' => 'Ada',
                                            'computedLabel' => 'row-1',
                                        ],
                                    ],
                                ],
                                [
                                    PagePayload::rowKey => '2',
                                    PagePayload::slots => [
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
        $this->assertSame(SignalTypeConstants::PAGE_RESPONSE, $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
        $this->assertSame(
            [
                PageResponseSignalData::page => BrowserContextTopologyHooksTestContext::PAGE,
                PageResponseSignalData::payload => [
                    PagePayload::tables => [
                        BrowserContextTopologyHooksTestContext::TABLE => [
                            PagePayload::rows => [
                                [
                                    PagePayload::rowKey => '1',
                                    PagePayload::slots => [
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
            ],
            $signal->data->data->toArray(),
        );
    }

    public function testInitBrowserBindsDefaultResolversToProjectTopologyRegistry(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = new BrowserContextEmitSignalsTestRtContext();
        Hilos::$rt->configure();
        Hilos::$rt->addRow(BrowserContextEmitSignalsTestState::create('1', 'Ada'));

        $context = new BrowserContextRegistryTopologyTestContext();
        BrowserContextRegistryTopologyTestHilos::initBrowser($context);
        $context->subscribeSnapshot(
            BrowserContextRegistryTopologyTestPage::PAGE,
            'ak-1',
            new PageRouteParams([]),
        );

        $signal = Hilos::$sr->getNextQueuedSignal();

        $this->assertNotNull($signal);
        $this->assertSame(SignalTypeConstants::PAGE_RESPONSE, $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
        $this->assertSame(
            [
                PageResponseSignalData::page => BrowserContextRegistryTopologyTestPage::PAGE,
                PageResponseSignalData::payload => [
                    PagePayload::tables => [
                        BrowserContextRegistryTopologyTestTable::TABLE => [
                            PagePayload::rows => [
                                [
                                    PagePayload::rowKey => '1',
                                    PagePayload::slots => [
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

    /**
     * Resolves test page browser metadata.
     *
     * @param string $page Page name from the subscription mirror
     * @return ?BrowserPageConfig Test page metadata, or null when absent
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
     * Resolves test page table bindings.
     *
     * @param string $page Page name from the subscription mirror
     * @return BrowserPageTableBindings Test page table bindings
     */
    protected function resolveBrowserPageTables(string $page): BrowserPageTableBindings
    {
        if ($page !== self::PAGE) {
            return BrowserPageTableBindings::empty();
        }

        return BrowserPageTableBindings::fromArray([
            self::TABLE => [],
        ]);
    }

    /**
     * Resolves test browser-only table config.
     *
     * @param string $tableKey Browser table key
     * @return ?BrowserSourceConfig Test browser-only table config
     */
    protected function resolveBrowserOnlyTableConfig(string $tableKey): ?BrowserSourceConfig
    {
        if ($tableKey !== self::TABLE) {
            return null;
        }

        return BrowserSourceConfig::fromArray([
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
        ]);
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

    /**
     * Reads page browser metadata through the protected hook.
     *
     * @param string $page Page name from the subscription mirror
     * @return ?BrowserPageConfig Browser page metadata
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
     * Reads page table bindings through the protected topology hook.
     *
     * @param string $page Page name from the subscription mirror
     * @return BrowserPageTableBindings Browser page table bindings
     */
    protected function resolveBrowserPageTables(string $page): BrowserPageTableBindings
    {
        if ($page !== self::PAGE) {
            return BrowserPageTableBindings::empty();
        }

        return BrowserPageTableBindings::fromArray([
            self::TABLE => [],
        ]);
    }

    /**
     * Reads browser-only table topology through the protected hook.
     *
     * @param string $tableKey Browser table key
     * @return ?BrowserSourceConfig Browser-only table config
     */
    protected function resolveBrowserOnlyTableConfig(string $tableKey): ?BrowserSourceConfig
    {
        if ($tableKey !== self::TABLE) {
            return null;
        }

        return BrowserSourceConfig::fromArray([
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
        ]);
    }
}

final class BrowserContextRegistryTopologyTestContext extends BrowserContext
{
}

final class BrowserContextRegistryTopologyTestPage extends AbstractPage
{
    public const string PAGE = 'registry_browser_page';
    public const string SIGNAL = 'registry_browser_signal';

    public const string SUBSCRIPTION_AGENT_TYPE = 'registry_agent';

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => self::SIGNAL,
    ];
}

final class BrowserContextRegistryTopologyTestTable
{
    public const string TABLE = 'registryRows';

    private const array SOURCE = [
        BrowserSourceKey::TYPE => BrowserSourceType::RT,
        BrowserSourceKey::KEY => BrowserContextEmitSignalsTestRtContext::ROWS,
    ];

    public const array BROWSER = [
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

final class BrowserContextRegistryTopologyTestHilos extends Hilos
{
    public const array PAGES = [
        BrowserContextRegistryTopologyTestPage::PAGE => BrowserContextRegistryTopologyTestPage::class,
    ];

    public const array BROWSER_TABLES = [
        BrowserContextRegistryTopologyTestTable::TABLE => BrowserContextRegistryTopologyTestTable::class,
    ];

    public const array PAGE_TABLES = [
        BrowserContextRegistryTopologyTestPage::PAGE => [
            BrowserContextRegistryTopologyTestTable::TABLE => [],
        ],
    ];

    /**
     * Creates a no-op DB context for the abstract facade contract.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new BrowserContextRegistryTopologyTestDbContext();
    }
}

final class BrowserContextRegistryTopologyTestDbContext extends DbContext
{
    /**
     * No-op DB configuration for browser topology tests.
     */
    public function configure(): void
    {
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
        return new static(
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
