<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\AdminViewMode\HiddenValue;
use Hilos\AdminViewMode\WireField;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Database\DatabaseException;
use Hilos\Database\Pages\PageCatalogConstants;
use Hilos\Hilos;
use Hilos\Pages\AbstractHilosDashboardPage;
use Hilos\Tests\Unit\Fixtures\IdentityTestBrowser;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Tests the page's own answer to a viewer of the admin view mode (HIL-1250).
 *
 * The page's own `data` is hidden by the page's declaration of it, its other sections whole; the shell
 * the framework lays over the page - label, lead, breadcrumb, children - is the page catalog and stays,
 * and for a viewer it wins over a key of the page's own with the same name. An admin gets the answer
 * exactly as before, the page's own key winning. The dashboard declares its sections, which are the
 * catalog too, so the landing of the admin section is not empty for a viewer.
 *
 * And the frame of a page's own subscriber set, which asks the page gate on every send (HIL-1251): a
 * connection the gate refuses is sent nothing, a viewer the hidden copy, an admin the frame itself; a
 * gate that cannot decide sends nothing and says so in the journal.
 */
final class AbstractPageAdminViewModeTest extends TestCase
{
    /** Temporary main log file the journal lines are read back from */
    private string $logFile = '';

    protected function setUp(): void
    {
        Hilos::$sr = new SignalRouter();
        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-page-admin-view-mode');
        Logger::setLogFile($this->logFile);
    }

    protected function tearDown(): void
    {
        Logger::resetLogFile();
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
        Hilos::$sr = null;
        Hilos::initBrowser();
        Hilos::resetBrowser();

        parent::tearDown();
    }

    public function testAViewerGetsTheShellAndOnlyWhatThePageDeclared(): void
    {
        Hilos::initBrowser(new PageViewerTestBrowser(viewer: true));

        $payload = $this->answer(new PageViewerTestPage(new PageViewerTestAgent()));

        $this->assertSame(
            [
                PagePayload::entities => ['currentUser' => HiddenValue::mark()],
                PagePayload::data => self::shell() + [
                    'declared' => 'shown',
                    'undeclared' => HiddenValue::mark(),
                ],
            ],
            $payload,
        );
    }

    public function testAnAdminGetsThePageAsBeforeWithItsOwnKeyWinning(): void
    {
        Hilos::initBrowser(new PageViewerTestBrowser(viewer: false));

        $payload = $this->answer(new PageViewerTestPage(new PageViewerTestAgent()));

        $this->assertSame(
            [
                PagePayload::entities => ['currentUser' => ['id' => 7, 'name' => 'Ada']],
                PagePayload::data => [
                    'declared' => 'shown',
                    'undeclared' => 'secret',
                    PageCatalogConstants::WIRE_PAGE_LABEL => 'Mine',
                ] + self::shell(),
            ],
            $payload,
        );
    }

    public function testTheDashboardShowsAViewerItsSections(): void
    {
        Hilos::initBrowser(new PageViewerTestBrowser(viewer: false));
        $adminData = $this->answer(new PageViewerTestDashboardPage(new PageViewerTestAgent()))[PagePayload::data];
        Hilos::initBrowser(new PageViewerTestBrowser(viewer: true));

        $viewerData = $this->answer(new PageViewerTestDashboardPage(new PageViewerTestAgent()))[PagePayload::data];

        $this->assertIsArray($viewerData);
        $this->assertArrayHasKey(PageCatalogConstants::WIRE_DASHBOARD_SECTIONS, $viewerData);
        $this->assertFalse(HiddenValue::isMark($viewerData[PageCatalogConstants::WIRE_DASHBOARD_SECTIONS]));
        // The same keys and values; the order differs, the shell going first for a viewer.
        $this->assertEquals($adminData, $viewerData);
    }

    public function testAFrameOfTheOwnSetGoesToAnAdminAsItIs(): void
    {
        Hilos::$browser = new IdentityTestBrowser(userId: 7, admin: true);
        $frame = self::frame();

        $this->assertSame($frame, PageViewerTestPage::frameFor('ak-1', $frame));
    }

    public function testAFrameOfTheOwnSetGoesToAViewerHidden(): void
    {
        Hilos::initBrowser(new PageViewerTestBrowser(viewer: true));

        $sent = PageViewerTestPage::frameFor('ak-1', self::frame());

        $this->assertInstanceOf(SignalData::class, $sent);
        $this->assertSame(['declared' => 'shown', 'undeclared' => HiddenValue::mark()], $sent->toArray());
    }

    public function testAFrameOfTheOwnSetIsNotSentToAConnectionTheGateRefuses(): void
    {
        Hilos::$browser = new IdentityTestBrowser(userId: 5, admin: false);
        $this->assertNull(PageViewerTestPage::frameFor('ak-1', self::frame()));

        Hilos::$browser = new IdentityTestBrowser(userId: null, admin: false);
        $this->assertNull(PageViewerTestPage::frameFor('ak-1', self::frame()));
    }

    public function testAFrameOfTheOwnSetIsNotSentWhenTheGateCannotDecide(): void
    {
        Hilos::$browser = new PageViewerTestFailingBrowser();

        $this->assertNull(PageViewerTestPage::frameFor('ak-1', self::frame()));
        $journal = (string)file_get_contents($this->logFile);
        $this->assertStringContainsString(
            'ERROR: Admin view mode: whether ak-1 may receive a frame of ' . PageViewerTestPage::class
                . ' could not be decided (The person table could not be read), so the frame is not sent.',
            $journal,
        );
    }

    /**
     * Subscribes the page and returns the payload of the answer it queued.
     *
     * @param AbstractPage $page Page to subscribe
     * @return array<string, mixed> Payload of the page_response frame
     */
    private function answer(AbstractPage $page): array
    {
        $page->onSubscribe('ak-1', new PageRouteParams([]));

        $signal = Hilos::$sr?->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertSame(SignalTypeConstants::PAGE_RESPONSE, $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertInstanceOf(PageResponseSignalData::class, $signal->data->data);

        return $signal->data->data->toArray()[PageResponseSignalData::payload];
    }

    /**
     * @return SignalData A frame of the page's own set, with a field it declares and one it does not
     */
    private static function frame(): SignalData
    {
        return new SignalData(['declared' => 'shown', 'undeclared' => 'secret']);
    }

    /**
     * @return array<string, mixed> The shell the catalog holds for the log-keys page
     */
    private static function shell(): array
    {
        return [
            PageCatalogConstants::WIRE_PAGE_LABEL => 'By key',
            PageCatalogConstants::WIRE_PAGE_LEAD => 'Log volume grouped by log key.',
            PageCatalogConstants::WIRE_PAGE_BREADCRUMB => [
                [
                    PageCatalogConstants::WIRE_CRUMB_PAGE => HilosPageConstants::HILOS_DASHBOARD,
                    PageCatalogConstants::WIRE_CRUMB_LABEL => 'Hilos',
                ],
                [
                    PageCatalogConstants::WIRE_CRUMB_PAGE => HilosPageConstants::HILOS_LOGS,
                    PageCatalogConstants::WIRE_CRUMB_LABEL => 'Logs',
                ],
                [
                    PageCatalogConstants::WIRE_CRUMB_PAGE => HilosPageConstants::HILOS_LOGS_KEYS,
                    PageCatalogConstants::WIRE_CRUMB_LABEL => 'By key',
                ],
            ],
            PageCatalogConstants::WIRE_PAGE_CHILDREN => [],
        ];
    }
}

/**
 * An admin page standing on a catalog entry, with data it declares, data it does not, and a key named like the shell.
 */
final class PageViewerTestPage extends AbstractPage
{
    public const string PAGE = HilosPageConstants::HILOS_LOGS_KEYS;

    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::ADMIN;

    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): ?PagePayload
    {
        return new PagePayload(
            entities: ['currentUser' => ['id' => 7, 'name' => 'Ada']],
            data: ['declared' => 'shown', 'undeclared' => 'secret', PageCatalogConstants::WIRE_PAGE_LABEL => 'Mine'],
        );
    }

    /**
     * @return array<string, WireField> One key declared not personal
     */
    protected function dataFields(): array
    {
        return ['declared' => WireField::notPersonal()];
    }

    /**
     * Opens the frame of the page's own subscriber set to the test, with the page's declaration.
     *
     * @param string $acceptKey Connection the frame goes to
     * @param SignalDataInterface $frame Frame as an admin receives it
     * @return ?SignalDataInterface What the connection is sent, or null for nothing
     */
    public static function frameFor(string $acceptKey, SignalDataInterface $frame): ?SignalDataInterface
    {
        return static::frameForViewer($acceptKey, $frame, ['declared' => WireField::notPersonal()]);
    }
}

final class PageViewerTestDashboardPage extends AbstractHilosDashboardPage
{
    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::ADMIN;
}

/**
 * A browser context whose one question answers as the test was told.
 */
final class PageViewerTestBrowser extends BrowserContext
{
    /**
     * @param bool $viewer What the one question answers
     */
    public function __construct(private readonly bool $viewer)
    {
        parent::__construct();
    }

    /**
     * @param string $pageClass Class of the page the frame belongs to
     * @param string $acceptKey Connection the frame goes to
     * @return bool The answer the test was built with
     */
    public function isAdminViewModeViewer(string $pageClass, string $acceptKey): bool
    {
        return $this->viewer;
    }
}

/**
 * A browser context whose connection is a signed-in person whose admin flag cannot be read.
 */
final class PageViewerTestFailingBrowser extends IdentityTestBrowser
{
    public function __construct()
    {
        parent::__construct(userId: 5, admin: true);
    }

    /**
     * @param int $userId Authenticated durable user id (unused)
     * @return bool Never returns
     * @throws DatabaseException Always
     */
    public function isAdmin(int $userId): bool
    {
        throw new DatabaseException('The person table could not be read');
    }
}

final class PageViewerTestAgent implements PageAgentInterface
{
    public function getId(): string
    {
        return 'page-viewer-test-agent';
    }

    public function getAgentSignalSource(): SignalSourceInterface
    {
        return new SignalSource(SignalSource::AGENT, 'test');
    }
}
