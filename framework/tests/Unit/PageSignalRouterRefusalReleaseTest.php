<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\HttpConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Analytics\AnalyticsCollector;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\AbstractPageFactory;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Page\Exception\PageForbiddenException;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Page\Exception\PageNotFoundException;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageResendOutcome;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalNameInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\SignalTypeInterface;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Hilos;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUpdateSubscriptionSignalDTO;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * A refusal or a failure releases what the page holds for the connection (HIL-1312).
 *
 * The subscription registration stays. The release runs before the error frame, including on a
 * silent re-send, and a release that itself throws still lets the refusal leave.
 */
final class PageSignalRouterRefusalReleaseTest extends TestCase
{
    private const string ACCEPT_KEY = 'ak-1';

    /** Temporary main log file, so the refusals below do not print into the run */
    private string $logFile = '';

    private RefusalReleaseTestSignalRouter $signals;

    private ?BrowserContext $previousBrowser = null;

    private ?AnalyticsCollector $previousAc = null;

    protected function setUp(): void
    {
        $this->previousBrowser = Hilos::$browser;
        $this->previousAc = Hilos::$ac;
        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-refusal-release');
        Logger::setLogFile($this->logFile);
        $this->signals = new RefusalReleaseTestSignalRouter();
        Hilos::$sr = $this->signals;
        Hilos::$ac = null;
        RefusalReleaseTestPage::resetState();
    }

    protected function tearDown(): void
    {
        Logger::resetLogFile();
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
        Hilos::$sr = null;
        Hilos::$ac = $this->previousAc;
        Hilos::$browser = $this->previousBrowser;
        RefusalReleaseTestPage::resetState();

        parent::tearDown();
    }

    public function testASubscribeRefusalReleasesBeforeTheErrorFrame(): void
    {
        $this->asPerson(admin: false);

        $this->router()->dispatchPageSubscribe(
            $this->subscribeFrame(),
            SignalSource::WORKER,
            RefusalReleaseTestPage::PAGE,
        );

        $this->assertSame(1, RefusalReleaseTestPage::$unsubscribeCalls);
        $this->assertSame(
            [RefusalReleaseTestSignalRouter::RELEASED, SignalConstants::SUBSCRIPTION_PAGE_ERROR],
            $this->signals->events,
        );
        $error = $this->onlySubscriptionError();
        $this->assertSame(HttpConstants::HTTP_FORBIDDEN, $error->httpCode);
        $this->assertSame(PageForbiddenException::ERROR_CODE, $error->errorCode);
    }

    public function testAReassessmentRefusalReleasesTheConnection(): void
    {
        $this->asPerson(admin: false);
        $this->holdSubscription();

        $this->router()->dispatchPageAccessReassess(
            $this->subscribeFrame(),
            SignalSource::WORKER,
            RefusalReleaseTestPage::PAGE,
        );

        $this->assertSame(1, RefusalReleaseTestPage::$unsubscribeCalls);
        $this->assertSame(HttpConstants::HTTP_FORBIDDEN, $this->onlySubscriptionError()->httpCode);
    }

    public function testASilentResendRefusalReleasesWithoutAnErrorFrame(): void
    {
        $this->asPerson(admin: false);
        $this->holdSubscription();
        Hilos::$sr?->markPageDeliveryFailure(self::ACCEPT_KEY);

        $outcome = $this->router()->resendPage(RefusalReleaseTestPage::PAGE, self::ACCEPT_KEY, []);

        $this->assertSame(PageResendOutcome::Refused, $outcome);
        $this->assertSame(1, RefusalReleaseTestPage::$unsubscribeCalls);
        $this->assertSame([RefusalReleaseTestSignalRouter::RELEASED], $this->signals->events);
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
        $this->assertTrue(Hilos::$sr?->hasPageDeliveryFailure(self::ACCEPT_KEY));
    }

    public function testAParamChangeRefusalReleasesTheConnection(): void
    {
        $this->asPerson(admin: false);
        $this->holdSubscription();

        $this->router()->dispatchPageUpdateSubscription(
            new WebSocketPageUpdateSubscriptionSignalDTO(self::ACCEPT_KEY, RefusalReleaseTestPage::PAGE, []),
            SignalSource::WORKER,
            RefusalReleaseTestPage::PAGE,
        );

        $this->assertSame(1, RefusalReleaseTestPage::$unsubscribeCalls);
        $this->assertSame(HttpConstants::HTTP_FORBIDDEN, $this->onlySubscriptionError()->httpCode);
    }

    public function testAnInternalFailureReleasesTheConnection(): void
    {
        $this->asPerson(admin: true);
        RefusalReleaseTestPage::$subscribeFailure = new PageInternalErrorException('The page broke');

        $this->router()->dispatchPageSubscribe(
            $this->subscribeFrame(),
            SignalSource::WORKER,
            RefusalReleaseTestPage::PAGE,
        );

        $this->assertSame(1, RefusalReleaseTestPage::$unsubscribeCalls);
        $this->assertSame('internal_error', $this->onlySubscriptionError()->errorCode);
    }

    public function testAnUnexpectedFailureReleasesTheConnection(): void
    {
        $this->asPerson(admin: true);
        RefusalReleaseTestPage::$subscribeFailure = new RuntimeException('The page threw');

        $this->router()->dispatchPageSubscribe(
            $this->subscribeFrame(),
            SignalSource::WORKER,
            RefusalReleaseTestPage::PAGE,
        );

        $this->assertSame(1, RefusalReleaseTestPage::$unsubscribeCalls);
        $this->assertSame('internal_error', $this->onlySubscriptionError()->errorCode);
    }

    public function testAnAcceptedSubscribeDoesNotRelease(): void
    {
        $this->asPerson(admin: true);

        $this->router()->dispatchPageSubscribe(
            $this->subscribeFrame(),
            SignalSource::WORKER,
            RefusalReleaseTestPage::PAGE,
        );

        $this->assertSame(0, RefusalReleaseTestPage::$unsubscribeCalls);
        $this->assertSame([SignalTypeConstants::PAGE_RESPONSE], $this->signals->events);
    }

    public function testAReleaseThatThrowsStillSendsTheRefusal(): void
    {
        $this->asPerson(admin: false);
        RefusalReleaseTestPage::$throwOnUnsubscribe = true;

        $this->router()->dispatchPageSubscribe(
            $this->subscribeFrame(),
            SignalSource::WORKER,
            RefusalReleaseTestPage::PAGE,
        );

        $this->assertSame(1, RefusalReleaseTestPage::$unsubscribeCalls);
        $this->assertSame(HttpConstants::HTTP_FORBIDDEN, $this->onlySubscriptionError()->httpCode);
        $this->assertStringContainsString(
            'Page release after a refusal failed: page=' . RefusalReleaseTestPage::PAGE
            . ', acceptKey=' . self::ACCEPT_KEY . ', release failed',
            (string)file_get_contents($this->logFile),
        );
    }

    public function testAReassessmentForAConnectionThatLeftDoesNotRelease(): void
    {
        $this->asPerson(admin: false);

        $this->router()->dispatchPageAccessReassess(
            $this->subscribeFrame(),
            SignalSource::WORKER,
            RefusalReleaseTestPage::PAGE,
        );

        $this->assertSame(0, RefusalReleaseTestPage::$unsubscribeCalls);
        $this->assertSame([], $this->signals->events);
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    /**
     * @param bool $admin Whether the connection acts as an administrator
     */
    private function asPerson(bool $admin): void
    {
        Hilos::initBrowser(new RefusalReleaseTestBrowser($admin));
    }

    /**
     * Registers the live subscription the re-decision and the update both require.
     */
    private function holdSubscription(): void
    {
        Hilos::$sr?->subscribeToPage(RefusalReleaseTestPage::PAGE, $this->subscribeFrame());
    }

    /**
     * @return WebSocketPageSubscribeSignalDTO Subscribe frame for the fixture connection
     */
    private function subscribeFrame(): WebSocketPageSubscribeSignalDTO
    {
        return new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, RefusalReleaseTestPage::PAGE);
    }

    /**
     * @return PageSignalRouter Router over the fixture page
     */
    private function router(): PageSignalRouter
    {
        return new PageSignalRouter(new RefusalReleaseTestPageFactory(new RefusalReleaseTestAgent()), new ActionRouteConfig());
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
 * Records each queued signal name, and the release the page reports, in the order they happen.
 */
final class RefusalReleaseTestSignalRouter extends SignalRouter
{
    /** Mark the page writes when its unsubscribe runs, ahead of any frame that follows */
    public const string RELEASED = 'unsubscribe';

    /** @var list<string> Release mark and signal names, oldest first */
    public array $events = [];

    /**
     * Records the signal name, then queues it.
     *
     * @param SignalSourceInterface $signalSource Signal source identifier
     * @param SignalTypeInterface $signalType Signal type
     * @param SignalNameInterface $signalName Signal name
     * @param SignalDataInterface $signalData Signal data DTO
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function queueSignal(
        SignalSourceInterface $signalSource,
        SignalTypeInterface $signalType,
        SignalNameInterface $signalName,
        SignalDataInterface $signalData,
    ): void {
        $this->events[] = $signalName->getName();
        parent::queueSignal($signalSource, $signalType, $signalName, $signalData);
    }
}

/**
 * An admin page that counts how often a refusal releases its connection.
 */
final class RefusalReleaseTestPage extends AbstractPage
{
    public const string PAGE = 'refusal_release_page';

    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::ADMIN;

    /** Skips the freeze, so the refusal under test is the admin gate's own 403. */
    public const bool OPEN_WHILE_FROZEN = true;

    public static int $unsubscribeCalls = 0;

    public static bool $throwOnUnsubscribe = false;

    public static ?Throwable $subscribeFailure = null;

    public static function resetState(): void
    {
        self::$unsubscribeCalls = 0;
        self::$throwOnUnsubscribe = false;
        self::$subscribeFailure = null;
    }

    /**
     * Counts the release and, when the case asks, fails it.
     *
     * @param string $acceptKey WebSocket accept key
     * @throws RuntimeException When the case asks the release to fail
     */
    public function onUnsubscribe(string $acceptKey): void
    {
        self::$unsubscribeCalls++;
        if (Hilos::$sr instanceof RefusalReleaseTestSignalRouter) {
            Hilos::$sr->events[] = RefusalReleaseTestSignalRouter::RELEASED;
        }
        if (self::$throwOnUnsubscribe) {
            throw new RuntimeException('release failed');
        }
    }

    /**
     * Plants the failure the case is about, before any answer is sent.
     *
     * @param string $acceptKey WebSocket accept key (unused)
     * @param PageRouteParams $params Route params from page subscription (unused)
     * @throws PageInternalErrorException When the case plants an internal failure
     * @throws RuntimeException When the case plants an unexpected failure
     */
    protected function onSubscribeBeforeResponse(string $acceptKey, PageRouteParams $params): void
    {
        if (self::$subscribeFailure !== null) {
            throw self::$subscribeFailure;
        }
    }
}

/**
 * Signed-in person, administrator or not, standing in for the connection registry.
 */
final class RefusalReleaseTestBrowser extends BrowserContext
{
    /**
     * @param bool $admin Whether this person acts as an administrator
     */
    public function __construct(private readonly bool $admin)
    {
        parent::__construct();
    }

    /**
     * @param string $acceptKey Acting connection accept key (unused in the fixture)
     * @return ConnectionIdentity Settled identity of a signed-in person
     */
    protected function resolveConnectionIdentity(string $acceptKey): ConnectionIdentity
    {
        return ConnectionIdentity::resolved(5);
    }

    /**
     * @param int $userId Authenticated durable user id (unused in the fixture)
     * @return bool Injected admin verdict
     */
    public function isAdmin(int $userId): bool
    {
        return $this->admin;
    }
}

/**
 * Page factory fixture holding the refusal-release page.
 *
 * @extends AbstractPageFactory<RefusalReleaseTestAgent>
 */
final class RefusalReleaseTestPageFactory extends AbstractPageFactory
{
    /**
     * @param string $pageName Page name
     * @return AbstractPage Fixture page
     * @throws PageNotFoundException When an unexpected page is requested
     */
    protected function createPage(string $pageName): AbstractPage
    {
        return match ($pageName) {
            RefusalReleaseTestPage::PAGE => new RefusalReleaseTestPage($this->agent),
            default => throw new PageNotFoundException($pageName),
        };
    }

    /**
     * @param string $pageName Page name
     * @return bool Whether this is the fixture page
     */
    public function hasPage(string $pageName): bool
    {
        return $pageName === RefusalReleaseTestPage::PAGE;
    }
}

final class RefusalReleaseTestAgent implements PageAgentInterface
{
    /**
     * @return string Agent id
     */
    public function getId(): string
    {
        return 'refusal-release-test-agent';
    }

    /**
     * @return SignalSourceInterface Signal source for page helpers
     */
    public function getAgentSignalSource(): SignalSourceInterface
    {
        return new SignalSource(SignalSource::AGENT, 'test');
    }
}
