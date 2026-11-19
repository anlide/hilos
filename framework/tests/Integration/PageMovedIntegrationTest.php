<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserPageBindings;
use Hilos\Core\Browser\Config\BrowserPageConfig;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\AbstractPageFactory;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\Exception\InvalidPageRouteParamException;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\Exception\PageMovedException;
use Hilos\Core\Page\Exception\PageNotFoundException;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageResendOutcome;
use Hilos\Core\Page\PageResender;
use Hilos\Core\Page\PageResource;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Object\Item\Setting;
use Hilos\Database\Schema\Schema;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Hilos;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;

/**
 * A page that lives at another address answers with page_moved, opened or open (HIL-2190).
 *
 * The fixture page reads its address from the value column of a real settings row, declared
 * with addressedBy. Opening it at a value the row no longer holds is answered with the new
 * address; renaming the row through its collection layer moves the open window after the flush.
 *
 * Uses the commit-capable lifecycle because the write's announcement must reach the browser
 * source bus; an always-rollback root would hold that announcement until cleanup.
 */
final class PageMovedIntegrationTest extends FrameworkIntegrationTestCase
{
    private const array TABLES = ['hilos_setting'];

    private const string OWNER_AGENT_ID = 'test-agent:page-moved-integration';

    private ?HilosDbContext $previousDb = null;

    private ?SignalRouter $previousSignalRouter = null;

    private ?SettingsAccessor $previousSettings = null;

    private ?BrowserContext $previousBrowser = null;

    private PageMovedIntegrationTestBrowser $browser;

    private PageSignalRouter $router;

    /** Prepares the settings table, the real writer and the browser fan-out before each case. */
    protected function setUp(): void
    {
        parent::setUp();
        self::runStubs(down: true);
        self::runStubs(down: false);
        Schema::reset();
        Schema::initialize();

        $this->previousDb = Hilos::$db;
        $this->previousSignalRouter = Hilos::$sr;
        $this->previousSettings = Hilos::$setting;
        $this->previousBrowser = Hilos::$browser;
        $db = new PageMovedIntegrationTestDbContext();
        $db->configure();
        Hilos::$db = $db;
        Hilos::$sr = new SignalRouter();
        Hilos::$setting = new SettingsAccessor(PageMovedIntegrationTestSettingsCatalog::class);
        TruthSourceRegistry::register(HilosDbContext::settings, TruthSourceKeys::all(), self::OWNER_AGENT_ID);
        ExecutionContext::setCurrentAgentId(self::OWNER_AGENT_ID);
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        SourceChangeBus::subscribe(new PageMovedIntegrationTestBrowserSubscriber());

        $this->router = new PageSignalRouter(
            new PageMovedIntegrationTestPageFactory(new PageMovedIntegrationTestAgent()),
            new ActionRouteConfig([]),
        );
        $this->browser = new PageMovedIntegrationTestBrowser();
        $this->browser->bindPageResender(new PageMovedIntegrationTestPort($this->router));
        Hilos::initBrowser($this->browser);
    }

    /** Releases the fixture globals, the claim and the physical table. */
    protected function tearDown(): void
    {
        ExecutionContext::setCurrentAgentId(null);
        TruthSourceRegistry::unregisterAgent(self::OWNER_AGENT_ID);
        SourceChangeBus::reset();
        Hilos::$db = $this->previousDb;
        Hilos::$sr = $this->previousSignalRouter;
        Hilos::$setting = $this->previousSettings;
        if ($this->previousBrowser === null) {
            Hilos::resetBrowser();
        } else {
            Hilos::initBrowser($this->previousBrowser);
        }
        self::runStubs(down: true);
        parent::tearDown();
    }

    public function testASubscribeAtThePreviousAddressIsAnsweredWithTheNewOne(): void
    {
        $id = $this->addSetting(PageMovedIntegrationTestSettingsCatalog::FIRST, 'summer-meadow');
        $this->drainDbSignals();
        $params = ['id' => (string) $id, 'value' => 'morning-meadow'];
        $request = new WebSocketPageSubscribeSignalDTO(
            'ak-old',
            PageMovedIntegrationTestPage::PAGE,
            $params,
            deliveryId: 'delivery-old',
        );
        Hilos::$sr->subscribeToPage(PageMovedIntegrationTestPage::PAGE, $request);

        $this->router->dispatchPageSubscribe($request, SignalSource::WORKER, PageMovedIntegrationTestPage::PAGE);

        $frames = $this->takeFrames();
        self::assertSame([SignalConstants::PAGE_MOVED, SignalConstants::DELIVERY_COMPLETE], array_column($frames, 'name'));
        self::assertSame(['ak-old', 'ak-old'], array_column($frames, 'target'));
        self::assertEquals([
            'page' => PageMovedIntegrationTestPage::PAGE,
            'params' => (object) $params,
            'targetPage' => PageMovedIntegrationTestPage::PAGE,
            'targetParams' => (object) ['id' => (string) $id, 'value' => 'summer-meadow'],
        ], $frames[0]['data']);
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure('ak-old'));
        self::assertNull(Hilos::$sr->pageResource('ak-old'));
        self::assertSame(PageMovedIntegrationTestPage::PAGE, Hilos::$sr->pageSubscription('ak-old')?->page);
    }

    public function testRenamingTheRowMovesItsOpenWindowAfterTheFlush(): void
    {
        $id = $this->addSetting(PageMovedIntegrationTestSettingsCatalog::FIRST, 'morning-meadow');
        $otherId = $this->addSetting(PageMovedIntegrationTestSettingsCatalog::SECOND, 'evening-meadow');
        $this->drainDbSignals();
        $this->open('ak-renamed', $id, 'morning-meadow');
        $this->open('ak-other', $otherId, 'evening-meadow');
        self::assertSame([], $this->browser->flushToSignalRouter());
        $this->takeFrames();

        Hilos::$db->settings[$id]->actions->updateValue('summer-meadow');

        self::assertSame([], $this->browser->flushToSignalRouter());
        $frames = $this->takeFrames();
        self::assertSame([['target' => 'ak-renamed', 'name' => SignalConstants::PAGE_MOVED]], array_map(
            static fn (array $frame): array => ['target' => $frame['target'], 'name' => $frame['name']],
            $frames,
        ));
        self::assertEquals([
            'page' => PageMovedIntegrationTestPage::PAGE,
            'params' => (object) ['id' => (string) $id, 'value' => 'morning-meadow'],
            'targetPage' => PageMovedIntegrationTestPage::PAGE,
            'targetParams' => (object) ['id' => (string) $id, 'value' => 'summer-meadow'],
        ], $frames[0]['data']);
        self::assertNull(Hilos::$sr->pageResource('ak-renamed'));
        self::assertSame((string) $otherId, Hilos::$sr->pageResource('ak-other')?->idString);
        self::assertSame(
            [['value' => 'summer-meadow']],
            Database::sql('SELECT `value` FROM hilos_setting WHERE id = ' . $id)->rows(),
        );
    }

    /**
     * @param string $key Catalog key of the row
     * @param string $value Value the row starts with
     * @return int Primary id of the added row
     */
    private function addSetting(string $key, string $value): int
    {
        $id = Hilos::$db->settings->actions->add($key, $value, Hilos::$setting->catalog())->id;
        self::assertIsInt($id);

        return $id;
    }

    /**
     * Opens one window on its current address and takes its page_response.
     *
     * @param string $acceptKey Connection to subscribe
     * @param int $id Primary id of the row the window is about
     * @param string $value Address the window is opened at
     */
    private function open(string $acceptKey, int $id, string $value): void
    {
        $request = new WebSocketPageSubscribeSignalDTO(
            $acceptKey,
            PageMovedIntegrationTestPage::PAGE,
            ['id' => (string) $id, 'value' => $value],
        );
        Hilos::$sr->subscribeToPage(PageMovedIntegrationTestPage::PAGE, $request);
        $this->router->dispatchPageSubscribe($request, SignalSource::WORKER, PageMovedIntegrationTestPage::PAGE);
        $frames = $this->takeFrames();
        self::assertSame([SignalTypeConstants::PAGE_RESPONSE], array_column($frames, 'name'));
        self::assertSame((string) $id, Hilos::$sr->pageResource($acceptKey)?->idString);
    }

    /** Drops the DB sync announcements of the fixture writes. */
    private function drainDbSignals(): void
    {
        self::assertSame([], $this->takeFrames());
    }

    /** @return list<array{target: string, name: string, data: array<string, mixed>}> Addressed browser frames */
    private function takeFrames(): array
    {
        $frames = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalSource->getSource() === SignalSource::DB) {
                continue;
            }
            self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
            $frames[] = [
                'target' => $signal->data->targetAcceptKey,
                'name' => $signal->signalName->getName(),
                'data' => $signal->data->data->toArray(),
            ];
        }

        return $frames;
    }

    /** @param bool $down Whether to drop the existing stub tables */
    private static function runStubs(bool $down): void
    {
        // external-boundary: the up stub carries no suffix.
        $suffix = $down ? '_down' : '';
        foreach ($down ? array_reverse(self::TABLES) : self::TABLES as $table) {
            Database::sqlRun((string) file_get_contents(
                dirname(__DIR__, 2) . "/backend/Database/Migration/Stub/create_{$table}{$suffix}.sql"
            ));
        }
    }
}

/** Page addressed by the value of one settings row. */
final class PageMovedIntegrationTestPage extends AbstractPage
{
    public const string PAGE = 'page_moved_integration';

    /**
     * Names the row, and answers a value the row no longer holds with the address it holds now.
     *
     * @param string $acceptKey Subscribing connection (unused)
     * @param PageRouteParams $params Row id and the value the address was read at
     * @return PageResource The settings row, addressed by its value
     * @throws MissingPageRouteParamException When the id or the value is absent
     * @throws InvalidPageRouteParamException When the id is not a positive integer
     * @throws PageMovedException When the row holds another value now
     */
    protected function resolvePageResource(string $acceptKey, PageRouteParams $params): PageResource
    {
        $id = $params->requirePositiveInt('id');
        $current = (string) Hilos::$db->settings[$id]->value;
        if ($current !== $params->requireString('value')) {
            throw new PageMovedException(self::PAGE, ['id' => (string) $id, 'value' => $current]);
        }

        return PageResource::db(HilosDbContext::settings, $id)->addressedBy('value', Setting::value);
    }
}

/** @extends AbstractPageFactory<PageMovedIntegrationTestAgent> */
final class PageMovedIntegrationTestPageFactory extends AbstractPageFactory
{
    /**
     * @param string $pageName Page name
     * @return AbstractPage Fixture page
     * @throws PageNotFoundException When an unexpected page is requested
     */
    protected function createPage(string $pageName): AbstractPage
    {
        return match ($pageName) {
            PageMovedIntegrationTestPage::PAGE => new PageMovedIntegrationTestPage($this->agent),
            default => throw new PageNotFoundException($pageName),
        };
    }

    /**
     * @param string $pageName Page name
     * @return bool Whether this is the fixture page
     */
    public function hasPage(string $pageName): bool
    {
        return $pageName === PageMovedIntegrationTestPage::PAGE;
    }
}

/** Test agent for the page signal router. */
final class PageMovedIntegrationTestAgent implements PageAgentInterface
{
    /** @return string Fixture agent identity */
    public function getId(): string
    {
        return 'test-agent:page-moved';
    }

    /** @return SignalSourceInterface Fixture signal source */
    public function getAgentSignalSource(): SignalSourceInterface
    {
        return new SignalSource(SignalSource::AGENT, 'test');
    }
}

/** Browser context of one guest, with the fixture page and no tables. */
final class PageMovedIntegrationTestBrowser extends BrowserContext
{
    /**
     * @param string $acceptKey Receiving connection (unused)
     * @return ConnectionIdentity Settled guest
     */
    protected function resolveConnectionIdentity(string $acceptKey): ConnectionIdentity
    {
        return ConnectionIdentity::resolved(null);
    }

    /**
     * @param string $page Page name from the subscription mirror
     * @return ?BrowserPageConfig The fixture page's metadata, or null for any other
     */
    protected function resolveBrowserPageConfig(string $page): ?BrowserPageConfig
    {
        if ($page !== PageMovedIntegrationTestPage::PAGE) {
            return null;
        }

        return BrowserPageConfig::fromArray([BrowserConfigKey::SIGNAL => 'page_moved_integration_signal']);
    }

    /**
     * @param string $page Page name from the subscription mirror (unused)
     * @return BrowserPageBindings No tables
     */
    protected function resolveBrowserPageBindings(string $page): BrowserPageBindings
    {
        return BrowserPageBindings::empty();
    }
}

/** Hands the browser context the page router's own port, as the worker does. */
final class PageMovedIntegrationTestPort implements PageResender
{
    /** @param PageSignalRouter $router Router that forms the frames */
    public function __construct(private readonly PageSignalRouter $router)
    {
    }

    /**
     * @param string $page Page the subscription stands on
     * @param string $acceptKey Connection the page is owed to
     * @param array<string, mixed> $params Route params of the subscription
     * @param bool $reportRefusal Whether this deletion attempt may report its current domain refusal
     * @return PageResendOutcome What the router answered
     * @throws InvalidArgumentException When a frame of the answer cannot be named
     */
    public function resendPage(string $page, string $acceptKey, array $params, bool $reportRefusal = false): PageResendOutcome
    {
        return $this->router->resendPage($page, $acceptKey, $params, $reportRefusal);
    }

    /**
     * @param string $page Page the subscription stands on
     * @param string $acceptKey Connection the refusal is addressed to
     * @return PageResendOutcome What the router answered
     * @throws InvalidArgumentException When the subscription-error signal cannot be named
     */
    public function refuseDeletedResource(string $page, string $acceptKey): PageResendOutcome
    {
        return $this->router->refuseDeletedResource($page, $acceptKey);
    }

    /**
     * @param string $page Page the subscription stands on
     * @param string $acceptKey Connection the move is addressed to
     * @param array<string, string> $targetParams Every route param of the new address
     * @return PageResendOutcome What the router answered
     * @throws InvalidArgumentException When the page-moved signal cannot be named
     */
    public function moveRenamedResource(string $page, string $acceptKey, array $targetParams): PageResendOutcome
    {
        return $this->router->moveRenamedResource($page, $acceptKey, $targetParams);
    }
}

/** Settings catalog with the two string rows the fixture pages are addressed by. */
final class PageMovedIntegrationTestSettingsCatalog implements CatalogProviderInterface
{
    public const string FIRST = 'test.page_moved.first';

    public const string SECOND = 'test.page_moved.second';

    /** @return array<string, array<string, mixed>> Fixture string settings */
    public static function getCatalog(): array
    {
        return [
            self::FIRST => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_STRING,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => 'default-first',
            ],
            self::SECOND => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_STRING,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => 'default-second',
            ],
        ];
    }
}

/** Test database context. */
final class PageMovedIntegrationTestDbContext extends HilosDbContext
{
}

/** Bridges the actual ORM bus to the mounted browser without starting worker IPC. */
final class PageMovedIntegrationTestBrowserSubscriber implements SourceChangeSubscriberInterface
{
    /**
     * @param SourceChange $change Actual ORM fact
     * @param SourceChangeProvenance $provenance Origin supplied by the bus
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        if ($change->kind === SourceChange::KIND_DB) {
            Hilos::$browser?->record($change);
        }
    }
}
