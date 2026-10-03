<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserGuardKey;
use Hilos\Core\Browser\Config\BrowserGuardType;
use Hilos\Core\Browser\Config\BrowserPageBindings;
use Hilos\Core\Browser\Config\BrowserPageConfig;
use Hilos\Core\Browser\Config\BrowserSourceConfig;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\AbstractPageFactory;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Page\Exception\PageNotFoundException;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageResendOutcome;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Database\Pages\PageCatalogConstants;
use Hilos\Hilos;
use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Collection\RtCollection;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\RtItem;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Tests the page router's half of the re-send after a failed delivery (HIL-1236).
 *
 * The client was told its page could not be delivered and wiped it, so the re-send has to bring
 * the whole page back: it runs the frame a subscribe runs - the verdict, then the page's one
 * answer with its own part, its identity and its browser part - and says what went out. Unlike
 * the re-decision of rights it skips no page, and like it, it books nothing: the subscription
 * the mirror holds is the one it held before.
 */
final class PageSignalRouterResendPageTest extends TestCase
{
    /** Temporary main log file, so the refusals and failures below do not print into the run */
    private string $logFile = '';

    private ?RtContext $previousRt = null;

    protected function setUp(): void
    {
        $this->previousRt = Hilos::$rt;
        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-resend-page');
        Logger::setLogFile($this->logFile);
        Hilos::$sr = new SignalRouter();
        $runtime = new ResendPageTestRtContext();
        $runtime->configure();
        $runtime->addRow(new ResendPageTestState('1', 'Ada'));
        Hilos::$rt = $runtime;
    }

    protected function tearDown(): void
    {
        Logger::resetLogFile();
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
        Hilos::$sr = null;
        Hilos::$rt = $this->previousRt;
        Hilos::initBrowser();
        Hilos::resetBrowser();

        parent::tearDown();
    }

    public function testAPageTheVerdictLetsThroughIsAnsweredWholeInOneFrame(): void
    {
        Hilos::initBrowser(new ResendPageTestBrowser(7));
        $this->subscribe(ResendPageTestPage::PAGE);
        $held = Hilos::$sr?->pageSubscription('ak-1');

        $outcome = $this->router()->resendPage(ResendPageTestPage::PAGE, 'ak-1', ['tab' => 'all']);

        $this->assertSame(PageResendOutcome::Answered, $outcome);
        $signal = Hilos::$sr?->getNextQueuedSignal();
        $this->assertSame(SignalTypeConstants::PAGE_RESPONSE, $signal?->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertSame('ak-1', $signal->data->targetAcceptKey);
        $this->assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
        $payload = $signal->data->data->payload;
        $this->assertSame('mine', $payload->data['own'] ?? null);
        $this->assertArrayHasKey(PageCatalogConstants::WIRE_PAGE_LABEL, $payload->data);
        $this->assertSame(
            [ResendPageTestBrowser::TABLE => [PagePayload::rows => [[
                PagePayload::rowKey => '1',
                PagePayload::slots => [ResendPageTestRtContext::ROWS => ['id' => '1', 'name' => 'Ada']],
            ]]]],
            $payload->tables,
        );
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
        // Not a re-subscribe: the mirror holds the subscription it held, params and all.
        $this->assertEquals($held, Hilos::$sr?->pageSubscription('ak-1'));
    }

    public function testAPageTheVerdictRefusesIsAnsweredWithTheRefusal(): void
    {
        Hilos::initBrowser(new ResendPageTestBrowser(null));
        $this->subscribe(ResendPageTestPage::PAGE);

        $outcome = $this->router()->resendPage(ResendPageTestPage::PAGE, 'ak-1', []);

        $this->assertSame(PageResendOutcome::Refused, $outcome);
        $this->assertSame(401, $this->onlySubscriptionError()->httpCode);
    }

    public function testAPageThatThrowsIsAnsweredWithTheInternalError(): void
    {
        Hilos::initBrowser(new ResendPageTestBrowser(7));
        $this->subscribe(ResendPageTestThrowingPage::PAGE);

        $outcome = $this->router()->resendPage(ResendPageTestThrowingPage::PAGE, 'ak-1', []);

        $this->assertSame(PageResendOutcome::Failed, $outcome);
        $this->assertSame('internal_error', $this->onlySubscriptionError()->errorCode);
    }

    /**
     * The re-decision of rights skips a PUBLIC page with no guards, because its answer cannot have
     * changed; a re-send may not, because the client no longer has that answer at all.
     */
    public function testAPublicPageWithNoGuardsIsReSentAll(): void
    {
        Hilos::initBrowser(new ResendPageTestBrowser(null));
        $this->subscribe(ResendPageTestOpenPage::PAGE);

        $outcome = $this->router()->resendPage(ResendPageTestOpenPage::PAGE, 'ak-1', []);

        $this->assertSame(PageResendOutcome::Answered, $outcome);
        $this->assertSame(SignalTypeConstants::PAGE_RESPONSE, Hilos::$sr?->getNextQueuedSignal()?->signalName->getName());
    }

    /**
     * Registers one live subscription of 'ak-1' in the router mirror.
     *
     * @param string $page Page the connection holds
     */
    private function subscribe(string $page): void
    {
        Hilos::$sr?->subscribeToPage($page, new WebSocketPageSubscribeSignalDTO('ak-1', $page, ['tab' => 'all']));
    }

    /**
     * @return PageSignalRouter Router over the fixture pages
     */
    private function router(): PageSignalRouter
    {
        return new PageSignalRouter(new ResendPageTestPageFactory(new ResendPageTestAgent()), new ActionRouteConfig());
    }

    /**
     * Asserts the queue holds one subscription error and nothing else, and returns it.
     *
     * @return PageSubscriptionErrorSignalData The refusal the connection was sent
     */
    private function onlySubscriptionError(): PageSubscriptionErrorSignalData
    {
        $signal = Hilos::$sr?->getNextQueuedSignal();
        $this->assertInstanceOf(SignalDTO::class, $signal);
        $this->assertSame(SignalConstants::SUBSCRIPTION_PAGE_ERROR, $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertInstanceOf(PageSubscriptionErrorSignalData::class, $signal->data->data);
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());

        return $signal->data->data;
    }
}

/**
 * A page on a catalog entry with a part of its own, its browser part a table guarded by sign-in.
 */
class ResendPageTestPage extends AbstractPage
{
    public const string PAGE = HilosPageConstants::HILOS_LOGS_KEYS;

    /**
     * @param string $acceptKey WebSocket accept key of the subscribing connection (unused)
     * @param PageRouteParams $params Route params from page subscription (unused)
     * @return ?PagePayload The page's own part
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): ?PagePayload
    {
        return new PagePayload(data: ['own' => 'mine']);
    }
}

/**
 * A PUBLIC page declaring no guards: nothing about its answer turns on who is asking.
 */
final class ResendPageTestOpenPage extends ResendPageTestPage
{
    public const string PAGE = 'resend_page_open_page';
}

/**
 * A page that throws while building its own part.
 */
final class ResendPageTestThrowingPage extends ResendPageTestPage
{
    public const string PAGE = 'resend_page_throwing_page';

    /**
     * @param string $acceptKey WebSocket accept key of the subscribing connection (unused)
     * @param PageRouteParams $params Route params from page subscription (unused)
     * @return ?PagePayload Never returned
     * @throws LogicException Always, standing for a page broken about itself
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): ?PagePayload
    {
        throw new LogicException('This page cannot build its own part');
    }
}

/**
 * Serves the browser part of the fixture pages: one runtime table, guarded by sign-in on the first page.
 */
final class ResendPageTestBrowser extends BrowserContext
{
    public const string TABLE = 'resendPageRows';

    private const string SIGNAL = 'resend_page_signal';

    /**
     * @param ?int $currentUserId User behind every connection, or null for a guest
     */
    public function __construct(private readonly ?int $currentUserId)
    {
        parent::__construct();
    }

    /**
     * @param string $acceptKey Subscriber accept key (unused in the fixture)
     * @return ConnectionIdentity Settled identity carrying the injected user id
     */
    protected function resolveConnectionIdentity(string $acceptKey): ConnectionIdentity
    {
        return ConnectionIdentity::resolved($this->currentUserId);
    }

    /**
     * @param string $page Page name from the subscription mirror
     * @return ?BrowserPageConfig Page metadata, or null when absent
     * @throws PageInternalErrorException When a page or source declaration is malformed
     */
    protected function resolveBrowserPageConfig(string $page): ?BrowserPageConfig
    {
        return match ($page) {
            ResendPageTestPage::PAGE => BrowserPageConfig::fromArray([
                BrowserConfigKey::SIGNAL => self::SIGNAL,
                BrowserConfigKey::GUARDS => [[BrowserGuardKey::TYPE => BrowserGuardType::AUTHENTICATED]],
            ]),
            ResendPageTestOpenPage::PAGE, ResendPageTestThrowingPage::PAGE => BrowserPageConfig::fromArray([
                BrowserConfigKey::SIGNAL => self::SIGNAL,
            ]),
            default => null,
        };
    }

    /**
     * @param string $page Page name from the subscription mirror
     * @return BrowserPageBindings Page table bindings
     */
    protected function resolveBrowserPageBindings(string $page): BrowserPageBindings
    {
        return BrowserPageBindings::fromArray([self::TABLE => []]);
    }

    /**
     * @param string $browserKey Browser table key
     * @return ?BrowserSourceConfig The runtime table
     */
    protected function resolveBrowserOnlyConfig(string $browserKey): ?BrowserSourceConfig
    {
        if ($browserKey !== self::TABLE) {
            return null;
        }

        return BrowserSourceConfig::fromArray([
            BrowserTableConfigKey::ROWS => [[
                BrowserTableFieldKey::SOURCE => [
                    BrowserSourceKey::TYPE => BrowserSourceType::RT,
                    BrowserSourceKey::KEY => ResendPageTestRtContext::ROWS,
                ],
                BrowserTableFieldKey::ROW_KEY => 'id',
                BrowserTableFieldKey::FIELDS => ['id', 'name'],
            ]],
        ]);
    }
}

final class ResendPageTestRtContext extends RtContext
{
    public const string ROWS = 'resendPageSourceRows';

    public function configure(): void
    {
        $this->_stateCollections[self::ROWS] = ResendPageTestStates::init();
        $this->setRepresent(self::ROWS, ResendPageTestCollection::class);
    }

    /**
     * @param ResendPageTestState $row Row to add to the collection
     */
    public function addRow(ResendPageTestState $row): void
    {
        $this->_stateCollections[self::ROWS]->add($row);
    }
}

final class ResendPageTestStates extends RtStates
{
    public const string STATE_CLASS = ResendPageTestState::class;
}

final class ResendPageTestState extends RtState
{
    /**
     * @param string $id Row key
     * @param string $name Row label
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
    ) {
        parent::__construct();
    }

    /**
     * @return string Row key
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @param array<string, mixed> $row Raw row
     * @return static Row state
     */
    public static function fromRow(array $row): static
    {
        return new static((string)$row['id'], (string)$row['name']);
    }

    /**
     * @return array<string, mixed> Row payload
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name];
    }
}

final class ResendPageTestCollection extends RtCollection
{
    /**
     * @param RtState $state Backing state
     * @return RtItem View item over the state
     */
    protected function createRtItem(RtState $state): RtItem
    {
        return new ResendPageTestItem($state);
    }
}

final class ResendPageTestItem extends RtItem
{
    /**
     * @param string $name Field name
     * @return mixed Field value
     */
    public function __get(string $name): mixed
    {
        return $this->toArray()[$name] ?? parent::__get($name);
    }

    /**
     * @return array<string, mixed> Row payload
     */
    public function toArray(): array
    {
        return $this->getState()->toArray();
    }
}

/**
 * Page factory fixture holding the three re-send pages.
 *
 * @extends AbstractPageFactory<ResendPageTestAgent>
 */
final class ResendPageTestPageFactory extends AbstractPageFactory
{
    /**
     * @param string $pageName Page name
     * @return AbstractPage Fixture page
     * @throws PageNotFoundException When an unexpected page is requested
     */
    protected function createPage(string $pageName): AbstractPage
    {
        return match ($pageName) {
            ResendPageTestPage::PAGE => new ResendPageTestPage($this->agent),
            ResendPageTestOpenPage::PAGE => new ResendPageTestOpenPage($this->agent),
            ResendPageTestThrowingPage::PAGE => new ResendPageTestThrowingPage($this->agent),
            default => throw new PageNotFoundException($pageName),
        };
    }

    /**
     * @param string $pageName Page name
     * @return bool Whether this is one of the fixture pages
     */
    public function hasPage(string $pageName): bool
    {
        return in_array(
            $pageName,
            [ResendPageTestPage::PAGE, ResendPageTestOpenPage::PAGE, ResendPageTestThrowingPage::PAGE],
            true,
        );
    }
}

final class ResendPageTestAgent implements PageAgentInterface
{
    /**
     * @return string Agent id
     */
    public function getId(): string
    {
        return 'resend-page-test-agent';
    }

    /**
     * @return SignalSourceInterface Signal source for page helpers
     */
    public function getAgentSignalSource(): SignalSourceInterface
    {
        return new SignalSource(SignalSource::AGENT, 'test');
    }
}
