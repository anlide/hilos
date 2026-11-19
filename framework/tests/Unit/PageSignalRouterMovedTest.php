<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\HttpConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Analytics\AnalyticsCollector;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\AbstractPageFactory;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PageMovedSignalData;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Page\Exception\PageMovedException;
use Hilos\Core\Page\Exception\PageNotFoundException;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageResendOutcome;
use Hilos\Core\Page\PageResource;
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A page that lives at another address answers its subscription with page_moved (HIL-2190).
 *
 * The frame replaces page_response and is not a refusal: the page is released before it, the
 * registration stays, and no page debt is set. A move that names no address, or the address
 * it was judged at, is the page's broken declaration and answers as an internal failure.
 */
final class PageSignalRouterMovedTest extends TestCase
{
    private const string ACCEPT_KEY = 'ak-moved';

    private const string PREVIOUS_SLUG = 'morning-meadow';

    private const string NEW_SLUG = 'summer-meadow';

    /** Temporary main log file, so the answers below do not print into the run */
    private string $logFile = '';

    private MovedTestSignalRouter $signals;

    private ?BrowserContext $previousBrowser = null;

    private ?AnalyticsCollector $previousAc = null;

    protected function setUp(): void
    {
        $this->previousBrowser = Hilos::$browser;
        $this->previousAc = Hilos::$ac;
        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-page-moved');
        Logger::setLogFile($this->logFile);
        $this->signals = new MovedTestSignalRouter();
        Hilos::$sr = $this->signals;
        Hilos::$ac = null;
        Hilos::initBrowser(new MovedTestBrowser());
        MovedTestPage::resetState();
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
        MovedTestPage::resetState();

        parent::tearDown();
    }

    public function testASubscribeAtAPreviousAddressIsAnsweredWithPageMoved(): void
    {
        $this->holdSubscription(['slug' => self::PREVIOUS_SLUG]);
        MovedTestPage::$move = new PageMovedException(MovedTestPage::PAGE, ['slug' => self::NEW_SLUG]);

        $this->router()->dispatchPageSubscribe(
            $this->subscribeFrame(['slug' => self::PREVIOUS_SLUG]),
            SignalSource::WORKER,
            MovedTestPage::PAGE,
        );

        $this->assertSame([MovedTestSignalRouter::RELEASED, SignalConstants::PAGE_MOVED], $this->signals->events);
        $moved = $this->onlyPageMoved();
        $this->assertSame(MovedTestPage::PAGE, $moved->page);
        $this->assertSame(['slug' => self::PREVIOUS_SLUG], $moved->params);
        $this->assertSame(MovedTestPage::PAGE, $moved->targetPage);
        $this->assertSame(['slug' => self::NEW_SLUG], $moved->targetParams);
        $this->assertSame(1, MovedTestPage::$unsubscribeCalls);
        $this->assertFalse(Hilos::$sr?->hasPageDeliveryFailure(self::ACCEPT_KEY));
        $this->assertSame(MovedTestPage::PAGE, Hilos::$sr?->pageSubscription(self::ACCEPT_KEY)?->page);
        $this->assertStringContainsString(
            'Page moved: page=' . MovedTestPage::PAGE . ', target=' . MovedTestPage::PAGE,
            (string)file_get_contents($this->logFile),
        );
    }

    public function testATrackedSubscribeIsClosedAfterThePageMovedFrame(): void
    {
        MovedTestPage::$move = new PageMovedException('another_page', ['slug' => self::NEW_SLUG]);

        $this->router()->dispatchPageSubscribe(
            new WebSocketPageSubscribeSignalDTO(
                self::ACCEPT_KEY,
                MovedTestPage::PAGE,
                ['slug' => self::PREVIOUS_SLUG],
                deliveryId: 'delivery-1',
            ),
            SignalSource::WORKER,
            MovedTestPage::PAGE,
        );

        $this->assertSame(
            [MovedTestSignalRouter::RELEASED, SignalConstants::PAGE_MOVED, SignalConstants::DELIVERY_COMPLETE],
            $this->signals->events,
        );
    }

    public function testAParamUpdateIsAnsweredWithTheMergedParams(): void
    {
        $this->holdSubscription(['slug' => self::PREVIOUS_SLUG, 'tab' => 'reviews']);
        MovedTestPage::$move = new PageMovedException(MovedTestPage::PAGE, ['slug' => self::NEW_SLUG, 'tab' => 'photos']);

        $this->router()->dispatchPageUpdateSubscription(
            new WebSocketPageUpdateSubscriptionSignalDTO(self::ACCEPT_KEY, MovedTestPage::PAGE, ['tab' => 'photos']),
            SignalSource::WORKER,
            MovedTestPage::PAGE,
        );

        $this->assertSame([MovedTestSignalRouter::RELEASED, SignalConstants::PAGE_MOVED], $this->signals->events);
        $moved = $this->onlyPageMoved();
        $this->assertSame(['slug' => self::PREVIOUS_SLUG, 'tab' => 'photos'], $moved->params);
        $this->assertSame(['slug' => self::NEW_SLUG, 'tab' => 'photos'], $moved->targetParams);
        $this->assertFalse(Hilos::$sr?->hasPageDeliveryFailure(self::ACCEPT_KEY));
        $this->assertSame(
            ['slug' => self::PREVIOUS_SLUG, 'tab' => 'reviews'],
            Hilos::$sr?->pageSubscription(self::ACCEPT_KEY)?->params,
        );
    }

    public function testASilentResendDoesNotSendTheMove(): void
    {
        $this->holdSubscription(['slug' => self::PREVIOUS_SLUG]);
        Hilos::$sr?->markPageDeliveryFailure(self::ACCEPT_KEY);
        MovedTestPage::$move = new PageMovedException(MovedTestPage::PAGE, ['slug' => self::NEW_SLUG]);

        $outcome = $this->router()->resendPage(MovedTestPage::PAGE, self::ACCEPT_KEY, ['slug' => self::PREVIOUS_SLUG]);

        $this->assertSame(PageResendOutcome::Refused, $outcome);
        $this->assertSame([MovedTestSignalRouter::RELEASED], $this->signals->events);
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
        $this->assertTrue(Hilos::$sr?->hasPageDeliveryFailure(self::ACCEPT_KEY));
    }

    public function testABrokenMoveOnASilentResendFailsWithoutAFrame(): void
    {
        $this->holdSubscription(['slug' => self::PREVIOUS_SLUG]);
        Hilos::$sr?->markPageDeliveryFailure(self::ACCEPT_KEY);
        MovedTestPage::$move = new PageMovedException(MovedTestPage::PAGE, ['slug' => '']);

        $outcome = $this->router()->resendPage(MovedTestPage::PAGE, self::ACCEPT_KEY, ['slug' => self::PREVIOUS_SLUG]);

        $this->assertSame(PageResendOutcome::Failed, $outcome);
        $this->assertSame([MovedTestSignalRouter::RELEASED], $this->signals->events);
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
        $this->assertTrue(Hilos::$sr?->hasPageDeliveryFailure(self::ACCEPT_KEY));
        $this->assertStringNotContainsString('Page subscription error', (string)file_get_contents($this->logFile));
    }

    public function testADeletionAttemptOverADebtSendsTheMoveAndKeepsTheDebt(): void
    {
        $this->holdSubscription(['slug' => self::PREVIOUS_SLUG]);
        Hilos::$sr?->markPageDeliveryFailure(self::ACCEPT_KEY);
        MovedTestPage::$move = new PageMovedException(MovedTestPage::PAGE, ['slug' => self::NEW_SLUG]);

        $outcome = $this->router()->resendPage(
            MovedTestPage::PAGE,
            self::ACCEPT_KEY,
            ['slug' => self::PREVIOUS_SLUG],
            reportRefusal: true,
        );

        $this->assertSame(PageResendOutcome::Refused, $outcome);
        $this->assertSame([MovedTestSignalRouter::RELEASED, SignalConstants::PAGE_MOVED], $this->signals->events);
        $this->assertSame(['slug' => self::NEW_SLUG], $this->onlyPageMoved()->targetParams);
        $this->assertTrue(Hilos::$sr?->hasPageDeliveryFailure(self::ACCEPT_KEY));
        $this->assertStringNotContainsString('Page moved:', (string)file_get_contents($this->logFile));
    }

    /**
     * @param string $targetPage Page the broken move names
     * @param array<array-key, mixed> $targetParams Params the broken move names
     */
    #[DataProvider('brokenMoves')]
    public function testABrokenMoveIsAnsweredAsAnInternalFailure(string $targetPage, array $targetParams): void
    {
        $this->holdSubscription(['slug' => self::PREVIOUS_SLUG, 'tab' => 'reviews']);
        MovedTestPage::$move = new PageMovedException($targetPage, $targetParams);

        $this->router()->dispatchPageSubscribe(
            $this->subscribeFrame(['slug' => self::PREVIOUS_SLUG, 'tab' => 'reviews']),
            SignalSource::WORKER,
            MovedTestPage::PAGE,
        );

        $this->assertSame([MovedTestSignalRouter::RELEASED, SignalConstants::SUBSCRIPTION_PAGE_ERROR], $this->signals->events);
        $error = $this->onlySubscriptionError();
        $this->assertSame(HttpConstants::HTTP_INTERNAL_ERROR, $error->httpCode);
        $this->assertSame('internal_error', $error->errorCode);
        $this->assertTrue(Hilos::$sr?->hasPageDeliveryFailure(self::ACCEPT_KEY));
        $this->assertStringContainsString(
            'Page subscription error: page=' . MovedTestPage::PAGE . ', httpCode=500, error=internal_error, '
            . 'message=Page moved to a broken target',
            (string)file_get_contents($this->logFile),
        );
    }

    /**
     * @return array<string, array{string, array<array-key, mixed>}>
     */
    public static function brokenMoves(): array
    {
        return [
            'itself, keys in another order' => [MovedTestPage::PAGE, ['tab' => 'reviews', 'slug' => self::PREVIOUS_SLUG]],
            'no page' => ['', ['slug' => self::NEW_SLUG, 'tab' => 'reviews']],
            'a param that is not a string' => [MovedTestPage::PAGE, ['slug' => 42, 'tab' => 'reviews']],
            'an empty param' => [MovedTestPage::PAGE, ['slug' => '', 'tab' => 'reviews']],
            'a list' => [MovedTestPage::PAGE, [self::NEW_SLUG]],
            'a numeric name' => [MovedTestPage::PAGE, ['slug' => self::NEW_SLUG, 7 => 'reviews']],
        ];
    }

    public function testAParamUpdateThatMovesToItselfIsAnInternalFailure(): void
    {
        $this->holdSubscription(['slug' => self::PREVIOUS_SLUG, 'tab' => 'reviews']);
        MovedTestPage::$move = new PageMovedException(MovedTestPage::PAGE, ['tab' => 'photos', 'slug' => self::PREVIOUS_SLUG]);

        $this->router()->dispatchPageUpdateSubscription(
            new WebSocketPageUpdateSubscriptionSignalDTO(self::ACCEPT_KEY, MovedTestPage::PAGE, ['tab' => 'photos']),
            SignalSource::WORKER,
            MovedTestPage::PAGE,
        );

        $this->assertSame([MovedTestSignalRouter::RELEASED, SignalConstants::SUBSCRIPTION_PAGE_ERROR], $this->signals->events);
        $this->assertSame('internal_error', $this->onlySubscriptionError()->errorCode);
        $this->assertTrue(Hilos::$sr?->hasPageDeliveryFailure(self::ACCEPT_KEY));
    }

    public function testALiveMoveReleasesThePageAndSendsTheFrame(): void
    {
        $this->holdSubscription(['slug' => self::PREVIOUS_SLUG]);
        Hilos::$sr?->bindPageResource(
            self::ACCEPT_KEY,
            PageResource::db('products', 12)->addressedBy('slug', 'slug'),
        );

        $outcome = $this->router()->moveRenamedResource(MovedTestPage::PAGE, self::ACCEPT_KEY, ['slug' => self::NEW_SLUG]);

        $this->assertSame(PageResendOutcome::Refused, $outcome);
        $this->assertSame([MovedTestSignalRouter::RELEASED, SignalConstants::PAGE_MOVED], $this->signals->events);
        $moved = $this->onlyPageMoved();
        $this->assertSame(MovedTestPage::PAGE, $moved->page);
        $this->assertSame(['slug' => self::PREVIOUS_SLUG], $moved->params);
        $this->assertSame(MovedTestPage::PAGE, $moved->targetPage);
        $this->assertSame(['slug' => self::NEW_SLUG], $moved->targetParams);
        $this->assertNull(Hilos::$sr?->pageResource(self::ACCEPT_KEY));
        $this->assertFalse(Hilos::$sr?->hasPageDeliveryFailure(self::ACCEPT_KEY));
        $this->assertSame(MovedTestPage::PAGE, Hilos::$sr?->pageSubscription(self::ACCEPT_KEY)?->page);
        $this->assertSame(0, MovedTestPage::$subscribeCalls);
    }

    public function testALiveMoveWithoutASubscriptionIsUnserved(): void
    {
        $outcome = $this->router()->moveRenamedResource(MovedTestPage::PAGE, self::ACCEPT_KEY, ['slug' => self::NEW_SLUG]);

        $this->assertSame(PageResendOutcome::Unserved, $outcome);
        $this->assertSame([], $this->signals->events);
        $this->assertSame(0, MovedTestPage::$unsubscribeCalls);
    }

    public function testALiveMoveOfAnotherPagesSubscriptionIsUnserved(): void
    {
        Hilos::$sr?->subscribeToPage(
            'another_page',
            new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, 'another_page', ['slug' => self::PREVIOUS_SLUG]),
        );

        $outcome = $this->router()->moveRenamedResource(MovedTestPage::PAGE, self::ACCEPT_KEY, ['slug' => self::NEW_SLUG]);

        $this->assertSame(PageResendOutcome::Unserved, $outcome);
        $this->assertSame([], $this->signals->events);
        $this->assertSame(0, MovedTestPage::$unsubscribeCalls);
    }

    public function testALiveMoveWithEmptyArgumentsIsUnserved(): void
    {
        $this->holdSubscription(['slug' => self::PREVIOUS_SLUG]);

        $this->assertSame(
            PageResendOutcome::Unserved,
            $this->router()->moveRenamedResource('', self::ACCEPT_KEY, ['slug' => self::NEW_SLUG]),
        );
        $this->assertSame(
            PageResendOutcome::Unserved,
            $this->router()->moveRenamedResource(MovedTestPage::PAGE, '', ['slug' => self::NEW_SLUG]),
        );
        $this->assertSame([], $this->signals->events);
    }

    /**
     * Registers the live subscription the update and the live move both require.
     *
     * @param array<string, string> $params Params the subscription holds
     */
    private function holdSubscription(array $params): void
    {
        Hilos::$sr?->subscribeToPage(MovedTestPage::PAGE, $this->subscribeFrame($params));
    }

    /**
     * @param array<string, string> $params Params of the subscribe frame
     * @return WebSocketPageSubscribeSignalDTO Subscribe frame for the fixture connection
     */
    private function subscribeFrame(array $params): WebSocketPageSubscribeSignalDTO
    {
        return new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, MovedTestPage::PAGE, $params);
    }

    /**
     * @return PageSignalRouter Router over the fixture page
     */
    private function router(): PageSignalRouter
    {
        return new PageSignalRouter(new MovedTestPageFactory(new MovedTestAgent()), new ActionRouteConfig());
    }

    /**
     * Asserts the queue holds one page_moved frame and nothing else, and returns it.
     *
     * @return PageMovedSignalData The move the connection was sent
     */
    private function onlyPageMoved(): PageMovedSignalData
    {
        $signal = Hilos::$sr?->getNextQueuedSignal();
        $this->assertInstanceOf(SignalDTO::class, $signal);
        $this->assertSame(SignalConstants::PAGE_MOVED, $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertSame(self::ACCEPT_KEY, $signal->data->targetAcceptKey);
        $this->assertInstanceOf(PageMovedSignalData::class, $signal->data->data);
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());

        return $signal->data->data;
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
final class MovedTestSignalRouter extends SignalRouter
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
 * An admin page whose address check throws the move the case plants.
 */
final class MovedTestPage extends AbstractPage
{
    public const string PAGE = 'moved_test_page';

    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::ADMIN;

    /** Skips the freeze, so the answer under test is the page's own. */
    public const bool OPEN_WHILE_FROZEN = true;

    public static int $unsubscribeCalls = 0;

    public static int $subscribeCalls = 0;

    public static ?PageMovedException $move = null;

    public static function resetState(): void
    {
        self::$unsubscribeCalls = 0;
        self::$subscribeCalls = 0;
        self::$move = null;
    }

    /**
     * Counts the release.
     *
     * @param string $acceptKey WebSocket accept key
     */
    public function onUnsubscribe(string $acceptKey): void
    {
        self::$unsubscribeCalls++;
        if (Hilos::$sr instanceof MovedTestSignalRouter) {
            Hilos::$sr->events[] = MovedTestSignalRouter::RELEASED;
        }
    }

    /**
     * Throws the planted move, as an update's check of the address would.
     *
     * @param string $acceptKey WebSocket accept key (unused)
     * @param PageRouteParams $params Route params from page subscription (unused)
     * @throws PageMovedException When the case plants a move
     */
    public function onUpdateSubscription(string $acceptKey, PageRouteParams $params): void
    {
        if (self::$move !== null) {
            throw self::$move;
        }
    }

    /**
     * Throws the planted move, before any answer is sent.
     *
     * @param string $acceptKey WebSocket accept key (unused)
     * @param PageRouteParams $params Route params from page subscription (unused)
     * @throws PageMovedException When the case plants a move
     */
    protected function onSubscribeBeforeResponse(string $acceptKey, PageRouteParams $params): void
    {
        self::$subscribeCalls++;
        if (self::$move !== null) {
            throw self::$move;
        }
    }
}

/**
 * Signed-in administrator, standing in for the connection registry.
 */
final class MovedTestBrowser extends BrowserContext
{
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
     * @return bool Always an administrator
     */
    public function isAdmin(int $userId): bool
    {
        return true;
    }
}

/**
 * Page factory fixture holding the moved page.
 *
 * @extends AbstractPageFactory<MovedTestAgent>
 */
final class MovedTestPageFactory extends AbstractPageFactory
{
    /**
     * @param string $pageName Page name
     * @return AbstractPage Fixture page
     * @throws PageNotFoundException When an unexpected page is requested
     */
    protected function createPage(string $pageName): AbstractPage
    {
        return match ($pageName) {
            MovedTestPage::PAGE => new MovedTestPage($this->agent),
            default => throw new PageNotFoundException($pageName),
        };
    }

    /**
     * @param string $pageName Page name
     * @return bool Whether this is the fixture page
     */
    public function hasPage(string $pageName): bool
    {
        return $pageName === MovedTestPage::PAGE;
    }
}

final class MovedTestAgent implements PageAgentInterface
{
    /**
     * @return string Agent id
     */
    public function getId(): string
    {
        return 'moved-test-agent';
    }

    /**
     * @return SignalSourceInterface Signal source for page helpers
     */
    public function getAgentSignalSource(): SignalSourceInterface
    {
        return new SignalSource(SignalSource::AGENT, 'test');
    }
}
