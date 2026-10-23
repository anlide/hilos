<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\Table\DTO\TableWindowSignalData;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\View\Collection\VerifierCircleMembers;
use Hilos\Hilos;
use Hilos\Pages\Maintenance\AbstractHilosMaintenanceCirclePage;
use Hilos\Pages\Maintenance\AbstractHilosMaintenancePage;
use Hilos\Pages\Maintenance\DTO\MaintenanceCircleAddActionDTO;
use Hilos\Pages\Maintenance\DTO\MaintenanceCircleRemoveActionDTO;
use Hilos\Pages\Maintenance\DTO\MaintenanceEnableActionDTO;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Tables\ProtectedMode\HilosVerifierCircleTable;
use Hilos\Tests\Unit\Fixtures\IdentityTestBrowser;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/** Circle has its own action host and uses the ordinary page/table subscription path. */
final class HilosMaintenanceCirclePageTest extends TestCase
{
    private ?HilosDbContext $previousDb;

    private ?RtContext $previousRt;

    private ?SignalRouter $previousRouter;

    private ?TableContext $previousTables;

    private ?BrowserContext $previousBrowser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDb = Hilos::$db;
        $this->previousRt = Hilos::$rt;
        $this->previousRouter = Hilos::$sr;
        $this->previousTables = Hilos::$table;
        $this->previousBrowser = Hilos::$browser;
        Hilos::$rt = null;
        Hilos::$sr = new SignalRouter();
        MaintenanceCircleUnitHilos::initBrowser(new IdentityTestBrowser(1, true));
    }

    protected function tearDown(): void
    {
        Hilos::$db = $this->previousDb;
        Hilos::$rt = $this->previousRt;
        Hilos::$sr = $this->previousRouter;
        Hilos::$table = $this->previousTables;
        Hilos::initBrowser($this->previousBrowser);
        parent::tearDown();
    }

    public function testCircleOwnsOnlyItsTwoMutationsAndRequiresAdmin(): void
    {
        self::assertSame('hilos_maintenance_circle', AbstractHilosMaintenanceCirclePage::PAGE);
        self::assertSame(PageReach::ROUTE, AbstractHilosMaintenanceCirclePage::REACH);
        self::assertSame(PageAccessLevel::ADMIN, AbstractHilosMaintenanceCirclePage::ACCESS_LEVEL);
        self::assertSame([
            HilosSignalConstants::MAINTENANCE_CIRCLE_ADD => MaintenanceCircleAddActionDTO::class,
            HilosSignalConstants::MAINTENANCE_CIRCLE_REMOVE => MaintenanceCircleRemoveActionDTO::class,
        ], AbstractHilosMaintenanceCirclePage::ACTIONS);
        self::assertSame([], AbstractHilosMaintenanceCirclePage::SIGNALS);
        self::assertSame([], AbstractHilosMaintenanceCirclePage::READING_ACTIONS);
        self::assertFalse(is_subclass_of(AbstractHilosMaintenanceCirclePage::class, AbstractHilosMaintenancePage::class));

        $circle = new ReflectionClass(AbstractHilosMaintenanceCirclePage::class);
        foreach (['markStale', 'onAgentTick', 'removeSubscriber', 'reset'] as $hook) {
            self::assertFalse($circle->hasMethod($hook), $hook);
        }
        foreach (['onSubscribeBeforeResponse', 'onSubscribeAfterResponse', 'onUnsubscribe'] as $hook) {
            self::assertSame(AbstractPage::class, $circle->getMethod($hook)->getDeclaringClass()->getName());
        }
    }

    public function testTheFirstTableWindowRidesTheCirclePageResponseThroughTopology(): void
    {
        $members = $this->createMock(VerifierCircleMembers::class);
        $members->expects(self::once())->method('listAll')->willReturn([]);
        $db = $this->createMock(HilosDbContext::class);
        $db->method('__get')->with(HilosDbContext::verifierCircle)->willReturn($members);
        Hilos::$db = $db;
        Hilos::$table = new MaintenanceCircleUnitTables();
        Hilos::$table->configure();
        $factory = new HilosPageFactory($this->createMock(PageAgentInterface::class), MaintenanceCircleUnitHilos::class);

        $factory->getPage(HilosPageConstants::HILOS_MAINTENANCE_CIRCLE)->onSubscribe('circle', new PageRouteParams([]));

        $signal = Hilos::$sr->getNextQueuedSignal();
        self::assertSame(SignalTypeConstants::PAGE_RESPONSE, $signal?->signalName->getName());
        self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $reply = $signal->data->data;
        self::assertInstanceOf(PageResponseSignalData::class, $reply);
        $wire = $reply->toArray();
        self::assertSame(HilosPageConstants::HILOS_MAINTENANCE_CIRCLE, $wire[PageResponseSignalData::page]);
        $window = $wire[PageResponseSignalData::payload][PagePayload::windows][HilosVerifierCircleTable::TABLE];
        self::assertSame([], $window[TableWindowSignalData::rows]);
        self::assertSame(0, $window[TableWindowSignalData::totalCount]);
        self::assertSame(10, $window[TableWindowSignalData::limit]);
        self::assertNotNull(Hilos::$sr->getTableViewport('circle', HilosVerifierCircleTable::TABLE));
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testTheLegacyOverviewSubscriptionDispatchesTheUnchangedActionToCircle(): void
    {
        Hilos::$sr->subscribeToPage(MaintenanceCircleUnitOverview::PAGE, new WebSocketPageSubscribeSignalDTO(
            'legacy', MaintenanceCircleUnitOverview::PAGE,
        ));
        $factory = new HilosPageFactory($this->createMock(PageAgentInterface::class), MaintenanceCircleUnitHilos::class);
        $router = new PageSignalRouter($factory, new ActionRouteConfig(MaintenanceCircleUnitHilos::getPageActionRoutes()));

        $router->dispatchAction(new WebSocketActionSignalDTO(
            'legacy', HilosSignalConstants::MAINTENANCE_CIRCLE_ADD, ['identifier' => ''], 'circle-add',
        ), 'websocket');

        $signal = Hilos::$sr->getNextQueuedSignal();
        self::assertSame(SignalConstants::ACTION_ERROR, $signal?->signalName->getName());
        self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $error = $signal->data->data;
        self::assertInstanceOf(PageActionErrorSignalData::class, $error);
        self::assertSame('circle-add', $error->requestId);
        self::assertSame(HilosSignalConstants::MAINTENANCE_CIRCLE_ADD, $error->action);
        self::assertSame('An address is required', $error->reason);
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testCircleRefusesAManualAction(): void
    {
        $this->expectException(AgentUnknownActionException::class);
        new MaintenanceCircleUnitPage($this->createMock(PageAgentInterface::class))->onAction(
            'circle', HilosSignalConstants::MAINTENANCE_ENABLE, new MaintenanceEnableActionDTO(),
        );
    }

    public function testOverviewNoLongerHandlesCircleEdits(): void
    {
        $this->expectException(AgentUnknownActionException::class);
        new MaintenanceCircleUnitOverview($this->createMock(PageAgentInterface::class))->onAction(
            'circle', HilosSignalConstants::MAINTENANCE_CIRCLE_ADD, new MaintenanceCircleAddActionDTO('ann@example.test'),
        );
    }

    public function testCircleChecksThePayloadTypeBeforeCallingTheHandler(): void
    {
        $this->expectException(InvalidActionPayloadException::class);
        new MaintenanceCircleUnitPage($this->createMock(PageAgentInterface::class))->onAction(
            'circle', HilosSignalConstants::MAINTENANCE_CIRCLE_ADD, new MaintenanceCircleRemoveActionDTO(7),
        );
    }
}

final class MaintenanceCircleUnitPage extends AbstractHilosMaintenanceCirclePage
{
    public const string SUBSCRIPTION_AGENT_TYPE = 'circle-test-index';
}

final class MaintenanceCircleUnitOverview extends AbstractHilosMaintenancePage
{
    public const string SUBSCRIPTION_AGENT_TYPE = MaintenanceCircleUnitPage::SUBSCRIPTION_AGENT_TYPE;
}

abstract class MaintenanceCircleUnitHilos extends Hilos
{
    public const array PAGES = [
        MaintenanceCircleUnitOverview::PAGE => MaintenanceCircleUnitOverview::class,
        MaintenanceCircleUnitPage::PAGE => MaintenanceCircleUnitPage::class,
    ];

    public const array TABLES = [HilosVerifierCircleTable::TABLE => HilosVerifierCircleTable::class];

    public const array PAGE_TABLES = [MaintenanceCircleUnitPage::PAGE => [HilosVerifierCircleTable::TABLE => []]];
}

final class MaintenanceCircleUnitTables extends TableContext
{
    /** Registers the real table from the test topology. */
    public function configure(): void
    {
        foreach (MaintenanceCircleUnitHilos::TABLES as $key => $class) {
            $this->register($key, new $class());
        }
    }
}
