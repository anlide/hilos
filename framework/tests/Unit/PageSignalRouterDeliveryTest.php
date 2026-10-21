<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\AbstractPageFactory;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\Exception\PageForbiddenException;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Page\Exception\PageNotFoundException;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\Exception\PageUnauthorizedException;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Hilos;
use Hilos\Socket\WebSocket\DTO\DeliveryCompleteSignalData;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUnsubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUpdateSubscriptionSignalDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/PageSignalRouterPendingFrameTest.php';

/** Completion follows the real final subscribe/update handler, including parked and refused frames. */
final class PageSignalRouterDeliveryTest extends TestCase
{
    private ?SignalRouter $previousRouter;
    private DeliveryPageFactory $factory;
    private PageSignalRouter $router;
    private DeliveryPageBrowser $browser;

    protected function setUp(): void
    {
        $this->previousRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        $this->browser = new DeliveryPageBrowser();
        Hilos::$browser = $this->browser;
        $this->factory = new DeliveryPageFactory(new PendingFrameTestAgent());
        $this->router = new PageSignalRouter($this->factory, new ActionRouteConfig());
        Hilos::$sr->subscribeToPage('delivery', new WebSocketPageSubscribeSignalDTO('socket', 'delivery'));
    }

    protected function tearDown(): void
    {
        Hilos::$sr = $this->previousRouter;
        Hilos::resetBrowser();
    }

    /** @return iterable<string, array{bool}> The two subscription doors */
    public static function doors(): iterable
    {
        yield 'subscribe' => [false];
        yield 'update' => [true];
    }

    #[DataProvider('doors')]
    public function testCompletionFollowsPreliminaryResponseFacetsAndAfterHook(bool $update): void
    {
        $this->dispatch($update);
        $signals = $this->drain();
        self::assertSame(['own_state', 'page_response', 'table_facet_counts', 'after_response', 'hilos_delivery_complete'],
            array_map(static fn(SignalDTO $signal): string => $signal->signalName->getName(), $signals));
        $this->assertCompletion($signals[4], 'token');
        self::assertSame([], Hilos::$sr->takeReportedTableWindows('socket'));
    }

    #[DataProvider('doors')]
    public function testIdentityWaitDoesNotCompleteBeforeTheHandlerResumes(bool $update): void
    {
        $this->browser->identity = ConnectionIdentity::pending();
        $this->dispatch($update);
        self::assertSame([], $this->drain());
        $this->router->releasePendingFrames();
        self::assertSame([], $this->drain());
        $this->browser->identity = ConnectionIdentity::resolved(null);
        $this->router->releasePendingFrames();
        $signals = $this->drain();
        self::assertCount(5, $signals);
        $this->assertCompletion($signals[4], 'token');
        $this->router->releasePendingFrames();
        self::assertSame([], $this->drain());
    }

    /** @return iterable<string, array{bool, Throwable, int}> Refusals and failures on both doors */
    public static function failures(): iterable
    {
        foreach ([false, true] as $update) {
            foreach ([new PageUnauthorizedException(), new PageForbiddenException(), new PageResourceNotFoundException(),
                new PageInternalErrorException(), new RuntimeException('private engine failure')] as $index => $failure) {
                yield ($update ? 'update' : 'subscribe') . '-' . $index => [$update, $failure, [401, 403, 404, 500, 500][$index]];
            }
        }
    }

    #[DataProvider('failures')]
    public function testRefusalOrExceptionCompletesAfterTheError(bool $update, Throwable $failure, int $status): void
    {
        $this->factory->page()->failure = $failure;
        $this->dispatch($update);
        $signals = $this->drain();
        self::assertCount(2, $signals);
        self::assertSame('subscription_page_error', $signals[0]->signalName->getName());
        self::assertSame($status, $signals[0]->data->data->httpCode);
        $this->assertCompletion($signals[1], 'token');
    }

    #[DataProvider('doors')]
    public function testGuardRefusalCompletesWithoutCallingThePage(bool $update): void
    {
        $this->browser->refuse = true;
        $this->dispatch($update);
        $signals = $this->drain();
        self::assertCount(2, $signals);
        self::assertSame(403, $signals[0]->data->data->httpCode);
        self::assertSame(0, $this->factory->page()->calls);
        $this->assertCompletion($signals[1], 'token');
    }

    #[DataProvider('doors')]
    public function testAnExceptionAfterResponseStillCompletesAfterAllQueuedOutput(bool $update): void
    {
        $this->factory->page()->failAfter = true;
        $this->dispatch($update);
        $signals = $this->drain();
        self::assertSame(['own_state', 'page_response', 'table_facet_counts', 'after_response',
            'subscription_page_error', 'hilos_delivery_complete'],
            array_map(static fn(SignalDTO $signal): string => $signal->signalName->getName(), $signals));
        $this->assertCompletion($signals[5], 'token');
    }

    #[DataProvider('doors')]
    public function testResolveFailureCompletesAnExecutedFrame(bool $update): void
    {
        $this->dispatch($update, 'missing');
        $signals = $this->drain();
        self::assertCount($update ? 1 : 2, $signals);
        $this->assertCompletion($signals[array_key_last($signals)], 'token', 'missing');
    }

    public function testUnnamedSubscribeCompletesUsingTheSignalAddressWithoutCallingThePage(): void
    {
        $this->router->dispatchPageSubscribe(
            new WebSocketPageSubscribeSignalDTO('socket', '', deliveryId: 'token'), SignalSource::WEBSOCKET, 'delivery',
        );
        $signals = $this->drain();
        self::assertCount(1, $signals);
        self::assertSame(0, $this->factory->page()->calls);
        $this->assertCompletion($signals[0], 'token');
    }

    public function testUnnamedUpdateCompletesUsingItsDtoAddressWithoutCallingThePage(): void
    {
        $this->router->dispatchPageUpdateSubscription(
            new WebSocketPageUpdateSubscriptionSignalDTO('socket', 'delivery', deliveryId: 'token'), SignalSource::WEBSOCKET, '',
        );
        $signals = $this->drain();
        self::assertCount(1, $signals);
        self::assertSame(0, $this->factory->page()->calls);
        $this->assertCompletion($signals[0], 'token');
    }

    public function testAnUpdateAfterLeavingThePageCompletesWithoutJudgingIt(): void
    {
        Hilos::$sr->unsubscribeFromPage('delivery', new WebSocketPageUnsubscribeSignalDTO('socket'));
        $this->dispatch(true);
        $signals = $this->drain();
        self::assertCount(1, $signals);
        self::assertSame(0, $this->factory->page()->calls);
        $this->assertCompletion($signals[0], 'token');
    }

    #[DataProvider('doors')]
    public function testUntrackedRequestsNeverCreateCompletion(bool $update): void
    {
        $this->dispatch($update, token: null);
        self::assertCount(4, $this->drain());
    }

    public function testSyntheticResendDoesNotReuseTheLastBrowserToken(): void
    {
        $this->dispatch(false);
        $this->drain();
        $this->router->resendPage('delivery', 'socket', []);
        self::assertCount(4, $this->drain());
    }

    private function dispatch(bool $update, string $page = 'delivery', ?string $token = 'token'): void
    {
        if ($update) {
            $this->router->dispatchPageUpdateSubscription(
                new WebSocketPageUpdateSubscriptionSignalDTO('socket', $page, deliveryId: $token), SignalSource::WEBSOCKET, $page,
            );
        } else {
            $this->router->dispatchPageSubscribe(
                new WebSocketPageSubscribeSignalDTO('socket', $page, deliveryId: $token), SignalSource::WEBSOCKET, $page,
            );
        }
    }

    /** @return list<SignalDTO> Real queued response frames */
    private function drain(): array
    {
        $signals = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $signals[] = $signal;
        }
        return $signals;
    }

    private function assertCompletion(SignalDTO $signal, string $token, string $page = 'delivery'): void
    {
        self::assertSame(SignalTypeConstants::WS_USER, $signal->signalType->getType());
        self::assertSame(SignalConstants::DELIVERY_COMPLETE, $signal->signalName->getName());
        self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
        self::assertSame('socket', $signal->data->targetAcceptKey);
        self::assertInstanceOf(DeliveryCompleteSignalData::class, $signal->data->data);
        self::assertSame($page, $signal->data->data->page);
        self::assertSame($token, $signal->data->data->deliveryId);
    }
}

/** A real final AbstractPage subscription with preliminary and after-response output. */
final class DeliveryPage extends AbstractPage
{
    public const string PAGE = 'delivery';
    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::PUBLIC;
    public ?Throwable $failure = null;
    public bool $failAfter = false;
    public int $calls = 0;

    public function onUpdateSubscription(string $acceptKey, PageRouteParams $params): void
    {
        $this->onSubscribe($acceptKey, $params);
    }

    protected function onSubscribeBeforeResponse(string $acceptKey, PageRouteParams $params): void
    {
        $this->calls++;
        if ($this->failure !== null) {
            throw $this->failure;
        }
        $this->sendToUser('own_state', $acceptKey, new SignalData());
    }

    protected function onSubscribeAfterResponse(string $acceptKey, PageRouteParams $params): void
    {
        $this->sendToUser('after_response', $acceptKey, new SignalData());
        if ($this->failAfter) {
            throw new RuntimeException('after-response failure');
        }
    }
}

/** @extends AbstractPageFactory<PendingFrameTestAgent> */
final class DeliveryPageFactory extends AbstractPageFactory
{
    public function page(): DeliveryPage
    {
        return $this->getPage(DeliveryPage::PAGE);
    }

    public function hasPage(string $pageName): bool
    {
        return $pageName === DeliveryPage::PAGE;
    }

    protected function createPage(string $pageName): AbstractPage
    {
        if (!$this->hasPage($pageName)) {
            throw new PageNotFoundException($pageName);
        }
        return new DeliveryPage($this->agent);
    }
}

/** Identity and facets are controlled; the subscription response itself is produced by AbstractPage. */
final class DeliveryPageBrowser extends BrowserContext
{
    public ConnectionIdentity $identity;
    public bool $refuse = false;

    public function __construct()
    {
        parent::__construct();
        $this->identity = ConnectionIdentity::resolved(null);
    }

    public function assertSubscriptionAccess(string $page, string $acceptKey, PageRouteParams $params): void
    {
        if ($this->refuse) {
            throw new PageForbiddenException();
        }
    }

    public function sendSnapshotFacetCounts(string $page, string $acceptKey, PagePayload $snapshot): void
    {
        Hilos::$sr->queueSignal(
            new SignalSource(SignalSource::AGENT), new SignalType(SignalTypeConstants::WS_USER),
            new SignalName('table_facet_counts'), new WebSocketSignalData(new SignalData(), targetAcceptKey: $acceptKey),
        );
    }

    protected function resolveConnectionIdentity(string $acceptKey): ConnectionIdentity
    {
        return $this->identity;
    }
}
