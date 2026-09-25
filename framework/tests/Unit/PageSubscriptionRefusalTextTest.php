<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\HttpConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Action\ActionFailureReason;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\AbstractPageFactory;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Page\Exception\InvalidPageRouteParamException;
use Hilos\Core\Page\Exception\PageForbiddenException;
use Hilos\Core\Page\Exception\PageNotFoundException;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageErrorCode;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Hilos;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUpdateSubscriptionSignalDTO;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the text a refused page subscription carries across the wire (HIL-956).
 *
 * A refusal's own message is written for the journal - a guard's canonical phrase, a route
 * param's name and value - so the frame carries the subscription placeholder while its HTTP
 * and error codes stay what they were, and the words stay in the log the router writes.
 */
final class PageSubscriptionRefusalTextTest extends TestCase
{
    /** Temporary main log file the journal assertion reads its line back from */
    private string $logFile = '';

    protected function setUp(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::resetBrowser();

        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-subscription-refusal-text');
        Logger::setLogFile($this->logFile);
    }

    protected function tearDown(): void
    {
        Hilos::$sr = null;

        Logger::resetLogFile();
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }

        parent::tearDown();
    }

    public function testAGuardDenialTravelsAsThePlaceholder(): void
    {
        $this->subscribeAnonymouslyToTheAdminPage();

        $frame = $this->takeSubscriptionError();
        $this->assertSame(SignalConstants::SUBSCRIPTION_FAILED_REASON, $frame->message);
        $this->assertSame(401, $frame->httpCode);
        $this->assertSame('unauthorized', $frame->errorCode);
    }

    public function testTheRefusedWordsStayInTheJournal(): void
    {
        $this->subscribeAnonymouslyToTheAdminPage();

        $lines = $this->writtenLines();
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('Authentication required', $lines[0]);
        $this->assertStringNotContainsString('ERROR', $lines[0]);
    }

    public function testAMalformedRouteParamDoesNotNameItselfOnTheWire(): void
    {
        $router = new PageSignalRouter(
            new SubscriptionRefusalTextTestPageFactory(new SubscriptionRefusalTextTestAgent()),
            new ActionRouteConfig(),
        );
        Hilos::$sr->subscribeToPage(
            SubscriptionRefusalTextTestUserPage::PAGE,
            new WebSocketPageSubscribeSignalDTO('ak-1', SubscriptionRefusalTextTestUserPage::PAGE),
        );

        $router->dispatchPageUpdateSubscription(
            new WebSocketPageUpdateSubscriptionSignalDTO(
                'ak-1',
                SubscriptionRefusalTextTestUserPage::PAGE,
                [SubscriptionRefusalTextTestUserPage::USER_ID_PARAM => 'not-an-id'],
            ),
            'websocket',
            SubscriptionRefusalTextTestUserPage::PAGE,
        );

        $frame = $this->takeSubscriptionError();
        $this->assertSame(SignalConstants::SUBSCRIPTION_FAILED_REASON, $frame->message);
        $this->assertSame(400, $frame->httpCode);
        $this->assertSame('invalid_page_route_param', $frame->errorCode);
        $this->assertStringNotContainsString(SubscriptionRefusalTextTestUserPage::USER_ID_PARAM, $frame->message);
    }

    public function testAnUnservedPageIsRefusedByTheFallbackAgent(): void
    {
        $router = new PageSignalRouter(
            new SubscriptionRefusalTextTestPageFactory(new SubscriptionRefusalTextTestAgent()),
            new ActionRouteConfig(),
        );

        $router->dispatchPageSubscribe(
            new WebSocketPageSubscribeSignalDTO('ak-1', 'unserved_page'),
            'websocket',
            'unserved_page',
        );

        $frame = $this->takeSubscriptionError();
        $this->assertSame('unserved_page', $frame->page);
        $this->assertSame(HttpConstants::HTTP_NOT_FOUND, $frame->httpCode);
        $this->assertSame(PageErrorCode::NOT_SERVED, $frame->errorCode);
        $this->assertSame(SignalConstants::SUBSCRIPTION_FAILED_REASON, $frame->message);
    }

    public function testTheFamilyDoorIsShutForSubscriptionExceptions(): void
    {
        $this->assertFalse(ActionFailureReason::isPersonFacing(new PageForbiddenException('Access forbidden')));
    }

    /**
     * Subscribes a connection nobody is signed in on to the admin-level page, with no browser
     * context mounted, so the access gate refuses it before the page builds anything.
     */
    private function subscribeAnonymouslyToTheAdminPage(): void
    {
        $router = new PageSignalRouter(
            new SubscriptionRefusalTextTestPageFactory(new SubscriptionRefusalTextTestAgent()),
            new ActionRouteConfig(),
        );

        $router->dispatchPageSubscribe(
            new WebSocketPageSubscribeSignalDTO('ak-1', SubscriptionRefusalTextTestAdminPage::PAGE),
            'websocket',
            SubscriptionRefusalTextTestAdminPage::PAGE,
        );
    }

    /**
     * Takes the only queued signal and asserts it is the subscription error sent to the subscriber.
     *
     * @return PageSubscriptionErrorSignalData The frame's payload
     */
    private function takeSubscriptionError(): PageSubscriptionErrorSignalData
    {
        $signal = Hilos::$sr->getNextQueuedSignal();

        $this->assertNotNull($signal);
        $this->assertSame(SignalConstants::SUBSCRIPTION_PAGE_ERROR, $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertSame('ak-1', $signal->data->targetAcceptKey);
        $this->assertInstanceOf(PageSubscriptionErrorSignalData::class, $signal->data->data);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());

        return $signal->data->data;
    }

    /**
     * Reads back the journal lines written since the case started.
     *
     * @return list<string> Written lines, empty when the journal stayed silent
     */
    private function writtenLines(): array
    {
        if (!is_file($this->logFile)) {
            return [];
        }

        $written = rtrim((string)file_get_contents($this->logFile), "\n");
        if ($written === '') {
            return [];
        }

        return explode("\n", $written);
    }
}

/**
 * Page only an administrator may open, so an anonymous subscriber is refused by the access gate.
 */
final class SubscriptionRefusalTextTestAdminPage extends AbstractPage
{
    public const string PAGE = 'subscription_refusal_text_admin_page';

    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::ADMIN;

    /**
     * Contributes nothing; the refused subscription never reaches it.
     *
     * @param string $acceptKey WebSocket accept key of the subscribing connection (unused)
     * @param PageRouteParams $params Route params from page subscription (unused)
     * @return ?PagePayload Always null
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): ?PagePayload
    {
        return null;
    }
}

/**
 * Public page that reads a typed user id on every subscription update.
 */
final class SubscriptionRefusalTextTestUserPage extends AbstractPage
{
    public const string PAGE = 'subscription_refusal_text_user_page';

    /** Route param the update reads as a positive integer */
    public const string USER_ID_PARAM = 'userId';

    /**
     * Contributes nothing; the case only updates a subscription written by hand.
     *
     * @param string $acceptKey WebSocket accept key of the subscribing connection (unused)
     * @param PageRouteParams $params Route params from page subscription (unused)
     * @return ?PagePayload Always null
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): ?PagePayload
    {
        return null;
    }

    /**
     * Reads the user id, which refuses a value that is not a positive integer.
     *
     * @param string $acceptKey WebSocket accept key (unused)
     * @param PageRouteParams $params Merged route params for the subscription
     * @throws InvalidPageRouteParamException When the user id is not a positive integer
     */
    public function onUpdateSubscription(string $acceptKey, PageRouteParams $params): void
    {
        $params->getPositiveInt(self::USER_ID_PARAM);
    }
}

/**
 * Page factory fixture exposing the two refusal test pages.
 *
 * @extends AbstractPageFactory<SubscriptionRefusalTextTestAgent>
 */
final class SubscriptionRefusalTextTestPageFactory extends AbstractPageFactory
{
    /**
     * Creates the requested refusal test page.
     *
     * @param string $pageName Page name
     * @return AbstractPage Test page instance
     * @throws PageNotFoundException When an unexpected page is requested
     */
    protected function createPage(string $pageName): AbstractPage
    {
        return match ($pageName) {
            SubscriptionRefusalTextTestAdminPage::PAGE => new SubscriptionRefusalTextTestAdminPage($this->agent),
            SubscriptionRefusalTextTestUserPage::PAGE => new SubscriptionRefusalTextTestUserPage($this->agent),
            default => throw new PageNotFoundException($pageName),
        };
    }

    /**
     * Reports whether the page is one of the refusal test pages.
     *
     * @param string $pageName Page name
     * @return bool True for the two test pages
     */
    public function hasPage(string $pageName): bool
    {
        return in_array($pageName, [SubscriptionRefusalTextTestAdminPage::PAGE, SubscriptionRefusalTextTestUserPage::PAGE], true);
    }
}

final class SubscriptionRefusalTextTestAgent implements PageAgentInterface
{
    /**
     * Returns the fixture agent id.
     *
     * @return string Agent id
     */
    public function getId(): string
    {
        return 'test-agent';
    }

    /**
     * Returns the fixture signal source for page helpers.
     *
     * @return SignalSourceInterface Signal source
     */
    public function getAgentSignalSource(): SignalSourceInterface
    {
        return new SignalSource(SignalSource::AGENT, 'test');
    }
}
