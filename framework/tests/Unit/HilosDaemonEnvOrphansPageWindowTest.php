<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\NodeRole;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\Table\DTO\TableWindowDescriptorDTO;
use Hilos\Core\Table\DTO\TableWindowSignalData;
use Hilos\DaemonSection\LocalNodeEnvironment;
use Hilos\DaemonSection\NodeEnvironmentOrphan;
use Hilos\DaemonSection\NodeEnvironmentReading;
use Hilos\Hilos;
use Hilos\Pages\Daemon\AbstractHilosDaemonEnvOrphansPage;
use Hilos\Runtime\State\Item\HilosClusterNode;
use Hilos\Runtime\State\Item\TableRefusalRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Tables\Daemon\HilosDaemonEnvOrphansTable;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The orphans page opens its node window inside the first page_response.
 *
 * The snapshot is the real browser one. A cold entry has no reported window and
 * no held window, so the page pins filter.node before that snapshot runs.
 */
final class HilosDaemonEnvOrphansPageWindowTest extends TestCase
{
    private const string NODE = 'n1';
    private const string OTHER = 'n2';
    private const string SECRET = 'raw-orphan-secret';

    private ?SignalRouter $previousRouter = null;
    private ?BrowserContext $previousBrowser = null;
    private ?RtContext $previousRt = null;
    private ?TableContext $previousTable = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousRouter = Hilos::$sr;
        $this->previousBrowser = Hilos::$browser;
        $this->previousRt = Hilos::$rt;
        $this->previousTable = Hilos::$table;
        Hilos::$sr = new SignalRouter();
        Hilos::$browser = new DaemonEnvOrphansWindowBrowser();
        Hilos::$browser->bindHilosFacade(DaemonEnvOrphansWindowHilos::class);
        Hilos::$rt = new DaemonEnvOrphansWindowRtContext();
        Hilos::$rt->mountFeatureRuntime([]);
        RtTruthSourceRegistry::registerDaemon(HilosClusterNode::RT_COLLECTION);
        RtTruthSourceRegistry::registerDaemon(TableRefusalRuntime::RT_ITEM);
        Hilos::$table = new DaemonEnvOrphansWindowTables();
        Hilos::$table->configure();
        Hilos::$rt->hilosClusterNodes->actions->publish(self::NODE, NodeRole::Slave->value, [], null, true, 42.0);
        Hilos::$rt->hilosClusterNodes->actions->publish(self::OTHER, NodeRole::Slave->value, [], null, true, 43.0);
    }

    protected function tearDown(): void
    {
        LocalNodeEnvironment::forget(self::NODE);
        LocalNodeEnvironment::forget(self::OTHER);
        RtTruthSourceRegistry::unregisterDaemon(TableRefusalRuntime::RT_ITEM);
        RtTruthSourceRegistry::unregisterDaemon(HilosClusterNode::RT_COLLECTION);
        Hilos::$table = $this->previousTable;
        Hilos::$rt = $this->previousRt;
        Hilos::$browser = $this->previousBrowser;
        Hilos::$sr = $this->previousRouter;
        parent::tearDown();
    }

    public function testColdEntryAnswersWithRowsAndPinsTheNodeFilterBeforeTheSnapshot(): void
    {
        LocalNodeEnvironment::hold($this->reading(self::NODE));

        $answer = $this->subscribe('cold', self::NODE);

        self::assertSame([HilosDaemonEnvOrphansTable::FILTER_NODE => self::NODE], $this->browser()->filterAtSnapshot);
        $rows = $answer->payload->windows[HilosDaemonEnvOrphansTable::TABLE][TableWindowSignalData::rows];
        self::assertCount(2, $rows);
        $encoded = (string)json_encode($rows);
        self::assertStringNotContainsString(self::SECRET, $encoded);
        self::assertStringContainsString('"kind":"secret"', $encoded);
        self::assertArrayHasKey('node', $answer->payload->data);
        self::assertArrayHasKey('nodeSilent', $answer->payload->data);
        self::assertArrayNotHasKey('environment', $answer->payload->data);
        self::assertSame(
            [HilosDaemonEnvOrphansTable::FILTER_NODE => self::NODE],
            Hilos::$sr->getTableViewport('cold', HilosDaemonEnvOrphansTable::TABLE)?->filter,
        );
    }

    public function testAHeldWindowForThisNodeKeepsItsPlaceAndSize(): void
    {
        LocalNodeEnvironment::hold($this->reading(self::NODE));
        $held = new TableViewportSubscription(
            tableKey: HilosDaemonEnvOrphansTable::TABLE,
            filter: [HilosDaemonEnvOrphansTable::FILTER_NODE => self::NODE],
            limit: 1,
        );
        Hilos::$sr->setTableViewport('held', $held);

        $answer = $this->subscribe('held', self::NODE);

        self::assertSame($held, Hilos::$sr->getTableViewport('held', HilosDaemonEnvOrphansTable::TABLE));
        self::assertSame(1, $held->limit);
        self::assertCount(1, $answer->payload->windows[HilosDaemonEnvOrphansTable::TABLE][TableWindowSignalData::rows]);
    }

    public function testAReportedWindowIsServedByTheSnapshotRatherThanThePreparedDefault(): void
    {
        LocalNodeEnvironment::hold($this->reading(self::NODE));
        Hilos::$sr->reportTableWindows('reported', [
            HilosDaemonEnvOrphansTable::TABLE => new TableWindowDescriptorDTO(
                filter: [HilosDaemonEnvOrphansTable::FILTER_NODE => self::NODE],
                limit: 1,
            ),
        ]);

        $answer = $this->subscribe('reported', self::NODE);

        self::assertSame([HilosDaemonEnvOrphansTable::FILTER_NODE => self::NODE], $this->browser()->filterAtSnapshot);
        self::assertSame(1, Hilos::$sr->getTableViewport('reported', HilosDaemonEnvOrphansTable::TABLE)?->limit);
        self::assertCount(1, $answer->payload->windows[HilosDaemonEnvOrphansTable::TABLE][TableWindowSignalData::rows]);
    }

    public function testAnotherNodeGetsNoRowsFromTheLocalReading(): void
    {
        LocalNodeEnvironment::hold($this->reading(self::NODE));

        $answer = $this->subscribe('foreign', self::OTHER);

        self::assertSame([HilosDaemonEnvOrphansTable::FILTER_NODE => self::OTHER], $this->browser()->filterAtSnapshot);
        self::assertSame([], $answer->payload->windows[HilosDaemonEnvOrphansTable::TABLE][TableWindowSignalData::rows]);
        self::assertStringNotContainsString(self::SECRET, (string)json_encode($answer->payload->toArray()));
    }

    public function testAViewerWindowHidesTheValueWithoutItsLength(): void
    {
        LocalNodeEnvironment::hold($this->reading(self::NODE));
        $this->browser()->viewer = true;

        $answer = $this->subscribe('viewer', self::NODE);

        $encoded = (string)json_encode($answer->payload->windows[HilosDaemonEnvOrphansTable::TABLE][TableWindowSignalData::rows]);
        self::assertStringContainsString('"_hidden":true', $encoded);
        self::assertStringNotContainsString(self::SECRET, $encoded);
        self::assertStringNotContainsString('length', $encoded);
    }

    public function testARefusedWindowIsNamedBesideAnEmptyWindowsSection(): void
    {
        LocalNodeEnvironment::hold($this->reading(self::NODE));
        Hilos::$rt->hilosTableRefusalRuntime?->actions->set(HilosDaemonEnvOrphansTable::TABLE);
        while (Hilos::$sr->getNextQueuedSignal() !== null) {
        }

        ob_start();
        try {
            $answer = $this->subscribe('refused', self::NODE);
        } finally {
            ob_end_clean();
        }

        self::assertArrayNotHasKey(HilosDaemonEnvOrphansTable::TABLE, $answer->payload->windows);
        self::assertArrayHasKey(HilosDaemonEnvOrphansTable::TABLE, $answer->payload->refusedWindows);
    }

    public function testAFailedSubscribeLeavesNoWindowAndNoAnswer(): void
    {
        $page = new DaemonEnvOrphansWindowPage(new DaemonEnvOrphansWindowAgent());

        try {
            $page->onSubscribe('missing', new PageRouteParams([
                HilosPageRouteParams::HILOS_DAEMON_NODE_ID => 'missing-node',
            ]));
            self::fail('An unknown node must be refused before any answer');
        } catch (PageResourceNotFoundException $e) {
            self::assertSame('Unknown Daemon node: missing-node', $e->getMessage());
        }

        self::assertNull(Hilos::$sr->getTableViewport('missing', HilosDaemonEnvOrphansTable::TABLE));
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    private function subscribe(string $acceptKey, string $nodeId): PageResponseSignalData
    {
        $page = new DaemonEnvOrphansWindowPage(new DaemonEnvOrphansWindowAgent());
        $page->onSubscribe($acceptKey, new PageRouteParams([
            HilosPageRouteParams::HILOS_DAEMON_NODE_ID => $nodeId,
        ]));
        $signal = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($signal);
        self::assertSame(SignalTypeConstants::PAGE_RESPONSE, $signal->signalName->getName());
        self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
        self::assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
        $answer = $signal->data->data;
        self::assertNull(Hilos::$sr->getNextQueuedSignal());

        return $answer;
    }

    private function browser(): DaemonEnvOrphansWindowBrowser
    {
        $browser = Hilos::$browser;
        self::assertInstanceOf(DaemonEnvOrphansWindowBrowser::class, $browser);

        return $browser;
    }

    private function reading(string $nodeId): NodeEnvironmentReading
    {
        return new NodeEnvironmentReading($nodeId, 10, [], [
            new NodeEnvironmentOrphan('RAW_ORPHAN', self::SECRET),
            new NodeEnvironmentOrphan('SECOND', 'other-secret'),
        ]);
    }
}

final class DaemonEnvOrphansWindowBrowser extends BrowserContext
{
    public bool $viewer = false;

    /** @var ?array<string, mixed> Filter already held when the snapshot starts */
    public ?array $filterAtSnapshot = null;

    public function isAdminViewModeViewer(string $pageClass, string $acceptKey): bool
    {
        return $this->viewer;
    }

    public function buildSubscribeSnapshot(string $page, string $acceptKey, PageRouteParams $params): PagePayload
    {
        $this->filterAtSnapshot = Hilos::$sr?->getTableViewport($acceptKey, HilosDaemonEnvOrphansTable::TABLE)?->filter;

        return parent::buildSubscribeSnapshot($page, $acceptKey, $params);
    }
}

abstract class DaemonEnvOrphansWindowHilos extends Hilos
{
    public const array PAGES = [
        HilosPageConstants::HILOS_DAEMON_ENV_ORPHANS => DaemonEnvOrphansWindowPage::class,
    ];

    public const array TABLES = [
        HilosDaemonEnvOrphansTable::TABLE => HilosDaemonEnvOrphansTable::class,
    ];

    public const array PAGE_TABLES = [
        HilosPageConstants::HILOS_DAEMON_ENV_ORPHANS => [
            HilosDaemonEnvOrphansTable::TABLE => [],
        ],
    ];
}

final class DaemonEnvOrphansWindowTables extends TableContext
{
    public function configure(): void
    {
        $this->register(HilosDaemonEnvOrphansTable::TABLE, new HilosDaemonEnvOrphansTable());
    }
}

final class DaemonEnvOrphansWindowRtContext extends RtContext
{
    public function configure(): void
    {
    }
}

final class DaemonEnvOrphansWindowAgent implements PageAgentInterface
{
    public function getId(): string
    {
        return 'daemon-env-orphans-window';
    }

    public function getAgentSignalSource(): SignalSourceInterface
    {
        return new SignalSource(SignalSource::AGENT, $this->getId());
    }
}

final class DaemonEnvOrphansWindowPage extends AbstractHilosDaemonEnvOrphansPage
{
}
