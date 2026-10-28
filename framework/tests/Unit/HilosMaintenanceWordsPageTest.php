<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\AdminViewMode\HiddenValue;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\Table\DTO\TableWindowSignalData;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\TableConstants;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Hilos;
use Hilos\Pages\Maintenance\AbstractHilosMaintenancePage;
use Hilos\Pages\Maintenance\AbstractHilosMaintenanceWordsPage;
use Hilos\Pages\Maintenance\DTO\MaintenanceEnableActionDTO;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Tables\ProtectedMode\HilosMaintenanceWordsTable;
use Hilos\Tables\ProtectedMode\HilosMaintenanceWordsTableRow;
use Hilos\Tests\Unit\Fixtures\IdentityTestBrowser;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Words page has no mutations and uses the standard page/table subscription path (HIL-1677).
 */
final class HilosMaintenanceWordsPageTest extends TestCase
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
        MaintenanceWordsUnitHilos::initBrowser(new IdentityTestBrowser(1, true));
        Hilos::$table = new MaintenanceWordsUnitTables();
        Hilos::$table->configure();
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

    public function testWordsOwnsNoActionsAndRequiresAdmin(): void
    {
        self::assertSame('hilos_maintenance_words', AbstractHilosMaintenanceWordsPage::PAGE);
        self::assertSame(PageReach::ROUTE, AbstractHilosMaintenanceWordsPage::REACH);
        self::assertSame(PageAccessLevel::ADMIN, AbstractHilosMaintenanceWordsPage::ACCESS_LEVEL);
        self::assertSame([], AbstractHilosMaintenanceWordsPage::ACTIONS);
        self::assertSame([], AbstractHilosMaintenanceWordsPage::SIGNALS);
        self::assertSame([], AbstractHilosMaintenanceWordsPage::READING_ACTIONS);
        self::assertFalse(is_subclass_of(AbstractHilosMaintenanceWordsPage::class, AbstractHilosMaintenancePage::class));

        $words = new ReflectionClass(AbstractHilosMaintenanceWordsPage::class);
        foreach (['markStale', 'onAgentTick', 'removeSubscriber', 'reset'] as $hook) {
            self::assertFalse($words->hasMethod($hook), $hook);
        }
        foreach (['onSubscribeBeforeResponse', 'onSubscribeAfterResponse', 'onUnsubscribe'] as $hook) {
            self::assertSame(AbstractPage::class, $words->getMethod($hook)->getDeclaringClass()->getName());
        }
    }

    public function testTheFirstTableWindowRidesTheWordsPageResponseThroughTopology(): void
    {
        $factory = new HilosPageFactory($this->createMock(PageAgentInterface::class), MaintenanceWordsUnitHilos::class);

        $factory->getPage(HilosPageConstants::HILOS_MAINTENANCE_WORDS)->onSubscribe('words', new PageRouteParams([]));

        $signal = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($signal);
        self::assertSame(SignalTypeConstants::PAGE_RESPONSE, $signal->signalName->getName());
        self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $reply = $signal->data->data;
        self::assertInstanceOf(PageResponseSignalData::class, $reply);

        $wire = $reply->toArray();
        self::assertSame(HilosPageConstants::HILOS_MAINTENANCE_WORDS, $wire[PageResponseSignalData::page]);
        $window = $wire[PageResponseSignalData::payload][PagePayload::windows][HilosMaintenanceWordsTable::TABLE];
        self::assertCount(3, $window[TableWindowSignalData::rows]);
        self::assertSame(3, $window[TableWindowSignalData::totalCount]);
        self::assertSame(TableConstants::DEFAULT_WINDOW_SIZE, $window[TableWindowSignalData::limit]);
        self::assertNotNull(Hilos::$sr->getTableViewport('words', HilosMaintenanceWordsTable::TABLE));
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testViewerDeliveryViaRealPersonalDataBridgeShowsAllFieldsWithoutFalseMutation(): void
    {
        MaintenanceWordsUnitHilos::initBrowser(new WordsViewerTestBrowser(true));
        $factory = new HilosPageFactory($this->createMock(PageAgentInterface::class), MaintenanceWordsUnitHilos::class);

        $factory->getPage(HilosPageConstants::HILOS_MAINTENANCE_WORDS)->onSubscribe('words-viewer', new PageRouteParams([]));

        $signal = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($signal);
        self::assertSame(SignalTypeConstants::PAGE_RESPONSE, $signal->signalName->getName());
        self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $reply = $signal->data->data;
        self::assertInstanceOf(PageResponseSignalData::class, $reply);

        $wire = $reply->toArray();
        $window = $wire[PageResponseSignalData::payload][PagePayload::windows][HilosMaintenanceWordsTable::TABLE];
        self::assertCount(3, $window[TableWindowSignalData::rows]);

        foreach ($window[TableWindowSignalData::rows] as $rowEnvelope) {
            $stub = $rowEnvelope[PagePayload::slots]['stub'];
            self::assertFalse(HiddenValue::isMark($stub[HilosMaintenanceWordsTableRow::operation]));
            self::assertFalse(HiddenValue::isMark($stub[HilosMaintenanceWordsTableRow::usesDefault]));
            if ($stub[HilosMaintenanceWordsTableRow::title] !== null) {
                self::assertFalse(HiddenValue::isMark($stub[HilosMaintenanceWordsTableRow::title]));
            }
            if ($stub[HilosMaintenanceWordsTableRow::message] !== null) {
                self::assertFalse(HiddenValue::isMark($stub[HilosMaintenanceWordsTableRow::message]));
            }
            if ($stub[HilosMaintenanceWordsTableRow::bannerMessage] !== null) {
                self::assertFalse(HiddenValue::isMark($stub[HilosMaintenanceWordsTableRow::bannerMessage]));
            }
        }

        // Table produces no mutations
        $mutation = (new HilosMaintenanceWordsTable())->buildMutationForSourceEvent(
            new SourceChange(SourceChange::KIND_DB, 'any', '1', TableMutationType::Update, [])
        );
        self::assertNull($mutation);
    }

    public function testWordsPageRefusesAnyAction(): void
    {
        $this->expectException(AgentUnknownActionException::class);
        $page = new MaintenanceWordsUnitPage($this->createMock(PageAgentInterface::class));
        $page->onAction('words', HilosSignalConstants::MAINTENANCE_ENABLE, new MaintenanceEnableActionDTO());
    }
}

final class MaintenanceWordsUnitPage extends AbstractHilosMaintenanceWordsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = 'words-test-index';
}

abstract class MaintenanceWordsUnitHilos extends Hilos
{
    public const array PAGES = [
        MaintenanceWordsUnitPage::PAGE => MaintenanceWordsUnitPage::class,
    ];

    public const array TABLES = [
        HilosMaintenanceWordsTable::TABLE => HilosMaintenanceWordsTable::class,
    ];

    public const array PAGE_TABLES = [
        MaintenanceWordsUnitPage::PAGE => [
            HilosMaintenanceWordsTable::TABLE => [],
        ],
    ];

    protected static function createDb(): HilosDbContext
    {
        return new MaintenanceWordsUnitDbContext();
    }
}

final class MaintenanceWordsUnitDbContext extends HilosDbContext
{
    public function configure(): void
    {
    }
}

final class MaintenanceWordsUnitTables extends TableContext
{
    public function configure(): void
    {
        foreach (MaintenanceWordsUnitHilos::TABLES as $key => $class) {
            $this->register($key, new $class());
        }
    }
}

final class WordsViewerTestBrowser extends IdentityTestBrowser
{
    public function __construct(private readonly bool $viewer)
    {
        parent::__construct(1, true);
    }

    public function isAdminViewModeViewer(string $pageClass, string $acceptKey): bool
    {
        return $this->viewer;
    }
}
