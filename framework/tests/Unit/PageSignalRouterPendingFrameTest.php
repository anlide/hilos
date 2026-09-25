<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\AbstractPageFactory;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Page\Exception\PageForbiddenException;
use Hilos\Core\Page\Exception\PageNotFoundException;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\TableConstants;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\TableLagRuntime as StateTableLagRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUnsubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUpdateSubscriptionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketTableFacetsSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketTableRenderedSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketTableRowFocusSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketTableViewportSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the queue a frame waits in until its connection is identified (HIL-599).
 *
 * The defect being pinned is one of ordering, not of logic: the connection is identified in
 * the worker that owns its WebSocket and judged in the worker that serves its page, so a
 * frame can arrive before the identity crosses the RT sync and be refused 401 to a person
 * who is signed in. What the tests hold down is that such a frame waits instead, that
 * waiting cannot reorder a connection's own frames, and - just as important - that the wait
 * has an end: an identity that never arrives costs the deadline and then exactly the verdict
 * the frame would have got before this queue existed.
 *
 * The subscription-update door joined them last (HIL-689) and is the one the defect was
 * loudest at: the client-side gate holds back only page_subscribe, so a param change sent
 * into the reconnect window reached the guards with nothing in front of it.
 */
final class PageSignalRouterPendingFrameTest extends TestCase
{
    private const string ACCEPT_KEY = 'ak-pending-1';

    private const int USER_ID = 27;

    /** Options the fixture table's view declared, three filters so a recount can leave one out. */
    private const array FACETS = ['channel' => ['email', 'sms'], 'status' => ['failed', 'sent'], 'node' => ['node-1']];

    /** A table lag no test waits out: whatever it releases, the test released by taking it off. */
    private const int HELD_FOR_GOOD_MS = 60_000;

    /** A table lag a test does wait out, longer than the identity wait so the two can be told apart. */
    private const int LAG_PAST_THE_IDENTITY_WAIT_MS = 600;

    /** Real time that outlasts {@see self::LAG_PAST_THE_IDENTITY_WAIT_MS} but not the lag plus the identity wait. */
    private const int PAST_THAT_LAG_MICROSECONDS = 650_000;

    private ?RtContext $previousRt = null;

    private string $logFile = '';

    protected function setUp(): void
    {
        Hilos::$sr = new SignalRouter();
        $this->previousRt = Hilos::$rt;
    }

    protected function tearDown(): void
    {
        RtTruthSourceRegistry::unregisterDaemon(StateTableLagRuntime::RT_ITEM);
        Hilos::$rt = $this->previousRt;
        Hilos::$sr = null;
        Hilos::resetBrowser();
        if ($this->logFile !== '') {
            Logger::resetLogFile();
            if (is_file($this->logFile)) {
                unlink($this->logFile);
            }
        }

        parent::tearDown();
    }

    public function testASubscribeFromAnUnidentifiedConnectionIsHeldInsteadOfRefused(): void
    {
        $browser = $this->mountBrowser();
        $browser->identity = ConnectionIdentity::pending();
        $factory = new PendingFrameTestPageFactory(new PendingFrameTestAgent());

        $this->router($factory)->dispatchPageSubscribe(
            new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, PendingFrameTestPage::PAGE, []),
            SignalSource::WEBSOCKET,
            PendingFrameTestPage::PAGE,
        );

        // Neither judged nor answered: the guard never ran, and nothing was sent to a
        // client that would have read the answer as "sign in again".
        $this->assertSame([], $this->page($factory)->handled);
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testTheHeldSubscribeRunsAsSoonAsTheIdentityArrives(): void
    {
        $browser = $this->mountBrowser();
        $browser->identity = ConnectionIdentity::pending();
        $factory = new PendingFrameTestPageFactory(new PendingFrameTestAgent());
        $router = $this->router($factory);

        $router->dispatchPageSubscribe(
            new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, PendingFrameTestPage::PAGE, []),
            SignalSource::WEBSOCKET,
            PendingFrameTestPage::PAGE,
        );

        $browser->identity = ConnectionIdentity::resolved(self::USER_ID);
        $router->releasePendingFrames();

        $this->assertSame(['subscribe'], $this->page($factory)->handled);

        // Released means answered: the page_response the client has been waiting on all
        // along rides this same run, and nothing else follows it.
        $signal = Hilos::$sr?->getNextQueuedSignal();

        $this->assertNotNull($signal);
        $this->assertSame(SignalTypeConstants::PAGE_RESPONSE, $signal->signalName->getName());
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testALaterFrameQueuesBehindAnEarlierOneEvenOnceTheIdentityIsThere(): void
    {
        $browser = $this->mountBrowser();
        $browser->identity = ConnectionIdentity::pending();
        $factory = new PendingFrameTestPageFactory(new PendingFrameTestAgent());
        $router = $this->router($factory);

        $router->dispatchPageSubscribe(
            new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, PendingFrameTestPage::PAGE, []),
            SignalSource::WEBSOCKET,
            PendingFrameTestPage::PAGE,
        );

        // The answer lands between the two frames. The action is still held, because the
        // subscribe it belongs to is: running it now would let it overtake its own page.
        $browser->identity = ConnectionIdentity::resolved(self::USER_ID);
        $router->dispatchAction(
            new WebSocketActionSignalDTO(self::ACCEPT_KEY, PendingFrameTestPage::ACTION),
            SignalSource::WEBSOCKET,
        );
        $this->assertSame([], $this->page($factory)->handled);

        $router->releasePendingFrames();

        $this->assertSame(['subscribe', 'action'], $this->page($factory)->handled);
    }

    public function testAnIdentityThatNeverArrivesCostsTheDeadlineAndThenTodaysVerdict(): void
    {
        $browser = $this->mountBrowser();
        $browser->identity = ConnectionIdentity::pending();
        $factory = new PendingFrameTestPageFactory(new PendingFrameTestAgent());
        $router = $this->router($factory);

        $router->dispatchPageSubscribe(
            new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, PendingFrameTestPage::PAGE, []),
            SignalSource::WEBSOCKET,
            PendingFrameTestPage::PAGE,
        );

        // Real time, and the only place this suite spends it: the ceiling is a constant of
        // the mechanism rather than a setting, precisely so that no deployment can turn the
        // guarantee "never worse than before" into a configuration mistake.
        usleep(PendingFrameTestPage::PAST_THE_DEADLINE_MICROSECONDS);
        $router->releasePendingFrames();

        $this->assertSame([], $this->page($factory)->handled);
        $this->assertSame(401, $this->queuedSubscriptionError()?->httpCode);
    }

    public function testClosingTheConnectionThrowsAwayWhatItWasWaitingFor(): void
    {
        $browser = $this->mountBrowser();
        $browser->identity = ConnectionIdentity::pending();
        $factory = new PendingFrameTestPageFactory(new PendingFrameTestAgent());
        $router = $this->router($factory);

        $router->dispatchPageSubscribe(
            new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, PendingFrameTestPage::PAGE, []),
            SignalSource::WEBSOCKET,
            PendingFrameTestPage::PAGE,
        );
        $router->dropPendingFrames(self::ACCEPT_KEY);

        $browser->identity = ConnectionIdentity::resolved(self::USER_ID);
        $router->releasePendingFrames();

        // The socket is gone: the frame must not be dispatched at nobody, however
        // resolvable its connection has since become.
        $this->assertSame([], $this->page($factory)->handled);
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testAViewportFrameAlsoWaitsForTheSubscriptionItIsAddressedTo(): void
    {
        $browser = $this->mountBrowser();
        $browser->identity = ConnectionIdentity::resolved(self::USER_ID);
        $factory = new PendingFrameTestPageFactory(new PendingFrameTestAgent());
        $router = $this->router($factory);

        $router->dispatchTableViewport(
            new WebSocketTableViewportSignalDTO(
                acceptKey: self::ACCEPT_KEY,
                page: PendingFrameTestPage::PAGE,
                tableKey: PendingFrameTestPage::TABLE_KEY,
            ),
            SignalSource::WEBSOCKET,
            PendingFrameTestPage::PAGE,
        );

        // Identified, yet held: the window delivery re-checks the page guards, and those
        // guards read the subscription's params - judged without it they would answer a
        // question the client did not ask.
        $this->assertSame([], $browser->windows);

        Hilos::$sr?->subscribeToPage(
            PendingFrameTestPage::PAGE,
            new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, PendingFrameTestPage::PAGE, []),
        );
        $router->releasePendingFrames();

        $this->assertSame([PendingFrameTestPage::TABLE_KEY], $browser->windows);
    }

    public function testATableLagHoldsAViewportFrameEvenOnceItsIdentityAndSubscriptionAreThere(): void
    {
        $this->mountTableLag(self::HELD_FOR_GOOD_MS);
        $browser = $this->mountBrowser();
        $browser->identity = ConnectionIdentity::resolved(self::USER_ID);
        $this->registerSubscription([]);
        $router = $this->router(new PendingFrameTestPageFactory(new PendingFrameTestAgent()));

        $router->dispatchTableViewport($this->viewportFrame([]), SignalSource::WEBSOCKET, PendingFrameTestPage::PAGE);
        $router->releasePendingFrames();

        // Everything the frame is judged against is here; only the lag stands between it and
        // the window, which is the late answer the row skeleton exists for (HIL-1020).
        $this->assertSame([], $browser->windows);
    }

    public function testTakingTheTableLagOffLetsTheHeldViewportGoAtTheNextSweep(): void
    {
        $this->mountTableLag(self::HELD_FOR_GOOD_MS);
        $browser = $this->mountBrowser();
        $browser->identity = ConnectionIdentity::resolved(self::USER_ID);
        $this->registerSubscription([]);
        $router = $this->router(new PendingFrameTestPageFactory(new PendingFrameTestAgent()));
        $router->dispatchTableViewport($this->viewportFrame([]), SignalSource::WEBSOCKET, PendingFrameTestPage::PAGE);

        // The lag is read on every sweep rather than stamped on the frame: a test does not have
        // to wait out the minute it asked for to see the window arrive.
        $this->setTableLag(0);
        $router->releasePendingFrames();

        $this->assertSame([PendingFrameTestPage::TABLE_KEY], $browser->windows);
    }

    public function testATableLagOfZeroHoldsNothing(): void
    {
        $this->mountTableLag(0);
        $browser = $this->mountBrowser();
        $browser->identity = ConnectionIdentity::resolved(self::USER_ID);
        $this->registerSubscription([]);
        $router = $this->router(new PendingFrameTestPageFactory(new PendingFrameTestAgent()));

        $router->dispatchTableViewport($this->viewportFrame([]), SignalSource::WEBSOCKET, PendingFrameTestPage::PAGE);

        $this->assertSame([PendingFrameTestPage::TABLE_KEY], $browser->windows);
    }

    public function testATableLagHoldsNoDoorButTheViewport(): void
    {
        $this->mountTableLag(self::HELD_FOR_GOOD_MS);
        $browser = $this->mountBrowser();
        $browser->identity = ConnectionIdentity::resolved(self::USER_ID);
        $this->registerSubscription([]);
        $factory = new PendingFrameTestPageFactory(new PendingFrameTestAgent());
        $router = $this->router($factory);
        Hilos::$sr?->setTableViewport(self::ACCEPT_KEY, new TableViewportSubscription(tableKey: PendingFrameTestPage::TABLE_KEY));

        $router->dispatchTableFacets(
            new WebSocketTableFacetsSignalDTO(self::ACCEPT_KEY, PendingFrameTestPage::PAGE, PendingFrameTestPage::TABLE_KEY, self::FACETS),
            SignalSource::WEBSOCKET,
            PendingFrameTestPage::PAGE,
        );
        $router->dispatchAction(
            new WebSocketActionSignalDTO(self::ACCEPT_KEY, PendingFrameTestPage::ACTION),
            SignalSource::WEBSOCKET,
        );

        $this->assertSame([[PendingFrameTestPage::TABLE_KEY, null]], $browser->facetCounts);
        $this->assertSame(['action'], $this->page($factory)->handled);
    }

    public function testAnActionBehindAHeldViewportWaitsForIt(): void
    {
        $this->mountTableLag(self::HELD_FOR_GOOD_MS);
        $browser = $this->mountBrowser();
        $browser->identity = ConnectionIdentity::resolved(self::USER_ID);
        $this->registerSubscription([]);
        $factory = new PendingFrameTestPageFactory(new PendingFrameTestAgent());
        $router = $this->router($factory);

        $router->dispatchTableViewport($this->viewportFrame([]), SignalSource::WEBSOCKET, PendingFrameTestPage::PAGE);
        $router->dispatchAction(
            new WebSocketActionSignalDTO(self::ACCEPT_KEY, PendingFrameTestPage::ACTION),
            SignalSource::WEBSOCKET,
        );
        $router->releasePendingFrames();

        // The connection's queue is the same FIFO it always was: the action came after the
        // window it may depend on, and it does not overtake it because the window is late.
        $this->assertSame([], $this->page($factory)->handled);

        $this->setTableLag(0);
        $router->releasePendingFrames();

        $this->assertSame([PendingFrameTestPage::TABLE_KEY], $browser->windows);
        $this->assertSame(['action'], $this->page($factory)->handled);
    }

    public function testATableLagTakenOffPastTheIdentityWaitIsNotReportedAsATimeout(): void
    {
        $this->captureLog();
        $this->mountTableLag(self::HELD_FOR_GOOD_MS);
        $browser = $this->mountBrowser();
        $browser->identity = ConnectionIdentity::resolved(self::USER_ID);
        $this->registerSubscription([]);
        $router = $this->router(new PendingFrameTestPageFactory(new PendingFrameTestAgent()));
        $router->dispatchTableViewport($this->viewportFrame([]), SignalSource::WEBSOCKET, PendingFrameTestPage::PAGE);

        // Real time: the frame's own identity deadline passes while the lag holds it. What the
        // e2e does - hold, look, take the lag off - must not read as a lost identity afterwards.
        usleep(PendingFrameTestPage::PAST_THE_DEADLINE_MICROSECONDS);
        $router->releasePendingFrames();
        $this->assertSame([], $browser->windows);

        $this->setTableLag(0);
        $router->releasePendingFrames();

        $this->assertSame([PendingFrameTestPage::TABLE_KEY], $browser->windows);
        $this->assertStringNotContainsString('Frame wait timed out', (string) file_get_contents($this->logFile));
    }

    public function testATableLagThatRunsOutPastTheIdentityWaitIsNotReportedAsATimeout(): void
    {
        $this->captureLog();
        $this->mountTableLag(self::LAG_PAST_THE_IDENTITY_WAIT_MS);
        $browser = $this->mountBrowser();
        $browser->identity = ConnectionIdentity::resolved(self::USER_ID);
        $this->registerSubscription([]);
        $router = $this->router(new PendingFrameTestPageFactory(new PendingFrameTestAgent()));
        $router->dispatchTableViewport($this->viewportFrame([]), SignalSource::WEBSOCKET, PendingFrameTestPage::PAGE);

        // No sweep in between: the lag runs out on its own, and the identity wait is counted
        // from its end rather than from the frame's arrival, so the frame is not late.
        usleep(self::PAST_THAT_LAG_MICROSECONDS);
        $router->releasePendingFrames();

        $this->assertSame([PendingFrameTestPage::TABLE_KEY], $browser->windows);
        $this->assertStringNotContainsString('Frame wait timed out', (string) file_get_contents($this->logFile));
    }

    public function testAnUpdateFromAnUnidentifiedConnectionIsHeldInsteadOfRefused(): void
    {
        $browser = $this->mountBrowser();
        $browser->identity = ConnectionIdentity::pending();
        $factory = new PendingFrameTestPageFactory(new PendingFrameTestAgent());
        $this->registerSubscription(['tab' => 'info']);

        $this->router($factory)->dispatchPageUpdateSubscription(
            $this->updateFrame(['tab' => 'files']),
            SignalSource::WEBSOCKET,
            PendingFrameTestPage::PAGE,
        );

        // Neither judged nor answered. Refusing here is what the client reads as "sign in
        // again", and it is the answer a person who has been signed in for an hour got.
        $this->assertSame([], $this->page($factory)->handled);
        $this->assertNull($this->queuedSubscriptionError());
    }

    public function testTheHeldUpdateAppliesItsParamsOnceTheIdentityArrives(): void
    {
        $browser = $this->mountBrowser();
        $browser->identity = ConnectionIdentity::pending();
        $factory = new PendingFrameTestPageFactory(new PendingFrameTestAgent());
        $router = $this->router($factory);
        $this->registerSubscription(['tab' => 'info']);

        $router->dispatchPageUpdateSubscription(
            $this->updateFrame(['tab' => 'files']),
            SignalSource::WEBSOCKET,
            PendingFrameTestPage::PAGE,
        );

        $browser->identity = ConnectionIdentity::resolved(self::USER_ID);
        $router->releasePendingFrames();

        // The wait costs the update nothing but its delay: the page is refreshed and the
        // accepted params settle, from the resumed frame exactly as from a straight one.
        $this->assertSame(['update'], $this->page($factory)->handled);
        $this->assertSame(['tab' => 'files'], $this->subscriptionParams());
    }

    public function testAnUpdateDoesNotOvertakeTheSubscribeItBelongsTo(): void
    {
        $browser = $this->mountBrowser();
        $browser->identity = ConnectionIdentity::pending();
        $factory = new PendingFrameTestPageFactory(new PendingFrameTestAgent());
        $router = $this->router($factory);

        $router->dispatchPageSubscribe(
            new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, PendingFrameTestPage::PAGE, ['tab' => 'info']),
            SignalSource::WEBSOCKET,
            PendingFrameTestPage::PAGE,
        );
        $this->registerSubscription(['tab' => 'info']);

        // The answer lands between the two frames, and the update is held anyway: it belongs
        // behind the subscribe, whose onSubscribe is what builds the page it refreshes.
        $browser->identity = ConnectionIdentity::resolved(self::USER_ID);
        $router->dispatchPageUpdateSubscription(
            $this->updateFrame(['tab' => 'files']),
            SignalSource::WEBSOCKET,
            PendingFrameTestPage::PAGE,
        );
        $this->assertSame([], $this->page($factory)->handled);

        $router->releasePendingFrames();

        $this->assertSame(['subscribe', 'update'], $this->page($factory)->handled);
    }

    public function testARefusedUpdateLeavesTheSubscriptionOnItsOldParams(): void
    {
        $browser = $this->mountBrowser();
        $browser->identity = ConnectionIdentity::pending();
        $factory = new PendingFrameTestPageFactory(new PendingFrameTestAgent());
        $router = $this->router($factory);
        $this->registerSubscription(['tab' => 'info']);

        $router->dispatchPageUpdateSubscription(
            $this->updateFrame(['tab' => 'files']),
            SignalSource::WEBSOCKET,
            PendingFrameTestPage::PAGE,
        );

        $browser->identity = ConnectionIdentity::resolved(self::USER_ID);
        $browser->refuseSubscriptionAccess = true;
        $router->releasePendingFrames();

        // Waiting moves when the verdict is reached, not what it is. A refused set still
        // settles nowhere, or the next fan-out would be judged by params this connection
        // was denied - and the refusal still reaches the client, now as the 403 it is.
        $this->assertSame([], $this->page($factory)->handled);
        $this->assertSame(['tab' => 'info'], $this->subscriptionParams());
        $this->assertSame(403, $this->queuedSubscriptionError()?->httpCode);
    }

    public function testAnUpdateForAPageTheConnectionHasLeftIsNotJudgedAtAll(): void
    {
        $browser = $this->mountBrowser();
        $browser->identity = ConnectionIdentity::pending();
        $factory = new PendingFrameTestPageFactory(new PendingFrameTestAgent());
        $router = $this->router($factory);
        $this->registerSubscription(['tab' => 'info']);

        $router->dispatchPageUpdateSubscription(
            $this->updateFrame(['tab' => 'files']),
            SignalSource::WEBSOCKET,
            PendingFrameTestPage::PAGE,
        );

        // The tab leaves while the frame waits. Its subscription goes with it, so there is
        // no longer a question to answer: the page must not be refreshed for a connection
        // that is not on it, and the client must not be told anything about a page it left.
        Hilos::$sr?->unsubscribeFromPage(
            PendingFrameTestPage::PAGE,
            new WebSocketPageUnsubscribeSignalDTO(self::ACCEPT_KEY),
        );
        $browser->identity = ConnectionIdentity::resolved(self::USER_ID);
        $router->releasePendingFrames();

        $this->assertSame([], $this->page($factory)->handled);
        $this->assertNull($this->queuedSubscriptionError());
        $this->assertNull(Hilos::$sr?->pageSubscription(self::ACCEPT_KEY));
    }

    public function testAProjectThatResolvesIdentityAtOnceNeverReachesTheQueue(): void
    {
        $browser = $this->mountBrowser();
        $browser->identity = ConnectionIdentity::resolved(self::USER_ID);
        $factory = new PendingFrameTestPageFactory(new PendingFrameTestAgent());

        $this->router($factory)->dispatchPageSubscribe(
            new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, PendingFrameTestPage::PAGE, []),
            SignalSource::WEBSOCKET,
            PendingFrameTestPage::PAGE,
        );

        // No sweep in between: an answer that is already there costs the frame nothing.
        $this->assertSame(['subscribe'], $this->page($factory)->handled);
    }

    public function testAFacetsFrameWaitsForTheSubscriptionAndIsAnsweredWithTheCounts(): void
    {
        $browser = $this->mountBrowser();
        $browser->identity = ConnectionIdentity::resolved(self::USER_ID);
        $router = $this->router(new PendingFrameTestPageFactory(new PendingFrameTestAgent()));
        Hilos::$sr?->setTableViewport(self::ACCEPT_KEY, new TableViewportSubscription(tableKey: PendingFrameTestPage::TABLE_KEY));

        $router->dispatchTableFacets(
            new WebSocketTableFacetsSignalDTO(self::ACCEPT_KEY, PendingFrameTestPage::PAGE, PendingFrameTestPage::TABLE_KEY, self::FACETS),
            SignalSource::WEBSOCKET,
            PendingFrameTestPage::PAGE,
        );

        // Held like a viewport frame: the counts re-check the same page guards a window does.
        $this->assertSame([], $browser->facetCounts);

        $this->registerSubscription([]);
        $router->releasePendingFrames();

        $this->assertSame(self::FACETS, Hilos::$sr?->getTableFacets(self::ACCEPT_KEY, PendingFrameTestPage::TABLE_KEY));
        $this->assertSame([[PendingFrameTestPage::TABLE_KEY, null]], $browser->facetCounts);
    }

    public function testARenderedFrameWaitsForTheSubscriptionAndIsLaidOverTheHeldWindow(): void
    {
        $browser = $this->mountBrowser();
        $browser->identity = ConnectionIdentity::resolved(self::USER_ID);
        $router = $this->router(new PendingFrameTestPageFactory(new PendingFrameTestAgent()));
        Hilos::$sr?->setTableViewport(self::ACCEPT_KEY, new TableViewportSubscription(tableKey: PendingFrameTestPage::TABLE_KEY));

        $router->dispatchTableRendered(
            new WebSocketTableRenderedSignalDTO(self::ACCEPT_KEY, PendingFrameTestPage::PAGE, PendingFrameTestPage::TABLE_KEY, ['name']),
            SignalSource::WEBSOCKET,
            PendingFrameTestPage::PAGE,
        );

        // Held like a viewport frame: the window is read again under the same page guards.
        $this->assertSame([], $browser->declaredRendered);

        $this->registerSubscription([]);
        $router->releasePendingFrames();

        $this->assertSame([[PendingFrameTestPage::TABLE_KEY, ['name']]], $browser->declaredRendered);
    }

    public function testARenderedFrameAWindowAlreadyCarriesReadsNothingAgain(): void
    {
        $browser = $this->mountBrowser();
        $browser->identity = ConnectionIdentity::resolved(self::USER_ID);
        $router = $this->router(new PendingFrameTestPageFactory(new PendingFrameTestAgent()));
        $this->registerSubscription([]);
        Hilos::$sr?->setTableViewport(
            self::ACCEPT_KEY,
            new TableViewportSubscription(tableKey: PendingFrameTestPage::TABLE_KEY, rendered: ['name']),
        );

        $router->dispatchTableRendered(
            new WebSocketTableRenderedSignalDTO(self::ACCEPT_KEY, PendingFrameTestPage::PAGE, PendingFrameTestPage::TABLE_KEY, ['name']),
            SignalSource::WEBSOCKET,
            PendingFrameTestPage::PAGE,
        );

        $this->assertSame([], $browser->declaredRendered);
    }

    public function testARenderedFrameForATableWithNoWindowReadsNothing(): void
    {
        $browser = $this->mountBrowser();
        $browser->identity = ConnectionIdentity::resolved(self::USER_ID);
        $router = $this->router(new PendingFrameTestPageFactory(new PendingFrameTestAgent()));
        $this->registerSubscription([]);

        $router->dispatchTableRendered(
            new WebSocketTableRenderedSignalDTO(self::ACCEPT_KEY, PendingFrameTestPage::PAGE, PendingFrameTestPage::TABLE_KEY, ['name']),
            SignalSource::WEBSOCKET,
            PendingFrameTestPage::PAGE,
        );

        // The window frame that makes this table's first window carries the list itself.
        $this->assertSame([], $browser->declaredRendered);
    }

    public function testAFocusFrameWaitsForTheSubscriptionAndIsRecordedAndAnsweredOnceItIsThere(): void
    {
        $browser = $this->mountBrowser();
        $browser->identity = ConnectionIdentity::resolved(self::USER_ID);
        $router = $this->router(new PendingFrameTestPageFactory(new PendingFrameTestAgent()));

        $router->dispatchTableRowFocus(
            new WebSocketTableRowFocusSignalDTO(self::ACCEPT_KEY, PendingFrameTestPage::PAGE, PendingFrameTestPage::TABLE_KEY, 'row-7'),
            SignalSource::WEBSOCKET,
            PendingFrameTestPage::PAGE,
        );

        // Held like a viewport frame: the row is read under the same page guards a window re-checks.
        $this->assertNull(Hilos::$sr?->getTableFocus(self::ACCEPT_KEY, PendingFrameTestPage::TABLE_KEY));
        $this->assertSame([], $browser->focusAnswers);

        $this->registerSubscription([]);
        $router->releasePendingFrames();

        $this->assertSame('row-7', Hilos::$sr?->getTableFocus(self::ACCEPT_KEY, PendingFrameTestPage::TABLE_KEY));
        $this->assertSame([[PendingFrameTestPage::TABLE_KEY, 'row-7']], $browser->focusAnswers);
    }

    public function testAReleaseFrameLetsTheRowGoAndIsAnsweredWithNothing(): void
    {
        $browser = $this->mountBrowser();
        $browser->identity = ConnectionIdentity::resolved(self::USER_ID);
        $router = $this->router(new PendingFrameTestPageFactory(new PendingFrameTestAgent()));
        $this->registerSubscription([]);
        Hilos::$sr?->setTableFocus(self::ACCEPT_KEY, PendingFrameTestPage::TABLE_KEY, 'row-7');

        $router->dispatchTableRowFocus(
            new WebSocketTableRowFocusSignalDTO(self::ACCEPT_KEY, PendingFrameTestPage::PAGE, PendingFrameTestPage::TABLE_KEY, ''),
            SignalSource::WEBSOCKET,
            PendingFrameTestPage::PAGE,
        );

        $this->assertNull(Hilos::$sr?->getTableFocus(self::ACCEPT_KEY, PendingFrameTestPage::TABLE_KEY));
        $this->assertSame([], $browser->focusAnswers);
    }

    public function testTheFirstWindowOfATableCarriesEveryCount(): void
    {
        [$browser, $router] = $this->mountFacetedTable();

        $router->dispatchTableViewport($this->viewportFrame([]), SignalSource::WEBSOCKET, PendingFrameTestPage::PAGE);

        $this->assertSame([[PendingFrameTestPage::TABLE_KEY, null]], $browser->facetCounts);
    }

    /**
     * A filter's counts are taken with that filter lifted, so choosing a channel moves the counts of
     * the status and the node and leaves the channel counts exactly where they were.
     */
    public function testChangingOneFilterCountsEveryOtherFilterAgainButNotItsOwn(): void
    {
        [$browser, $router] = $this->mountFacetedTable();
        $router->dispatchTableViewport($this->viewportFrame([]), SignalSource::WEBSOCKET, PendingFrameTestPage::PAGE);
        $browser->facetCounts = [];

        $router->dispatchTableViewport($this->viewportFrame(['channel' => 'email']), SignalSource::WEBSOCKET, PendingFrameTestPage::PAGE);

        $this->assertSame([[PendingFrameTestPage::TABLE_KEY, ['status', 'node']]], $browser->facetCounts);
    }

    public function testChangingTheSearchCountsEveryFilterAgain(): void
    {
        [$browser, $router] = $this->mountFacetedTable();
        $router->dispatchTableViewport($this->viewportFrame(['channel' => 'email']), SignalSource::WEBSOCKET, PendingFrameTestPage::PAGE);
        $browser->facetCounts = [];

        $router->dispatchTableViewport(
            $this->viewportFrame(['channel' => 'email', TableConstants::FILTER_KEY_SEARCH => 'ada']),
            SignalSource::WEBSOCKET,
            PendingFrameTestPage::PAGE,
        );

        $this->assertSame([[PendingFrameTestPage::TABLE_KEY, ['channel', 'status', 'node']]], $browser->facetCounts);
    }

    /**
     * A page turn, a new size and a new order leave the set as it was, so no count is taken again.
     */
    public function testTurningThePageResizingOrReorderingCountsNothingAgain(): void
    {
        [$browser, $router] = $this->mountFacetedTable();
        $router->dispatchTableViewport($this->viewportFrame(['channel' => 'email']), SignalSource::WEBSOCKET, PendingFrameTestPage::PAGE);
        $browser->facetCounts = [];

        $router->dispatchTableViewport(
            $this->viewportFrame(['channel' => 'email'], pageIndex: 2),
            SignalSource::WEBSOCKET,
            PendingFrameTestPage::PAGE,
        );
        $router->dispatchTableViewport(
            $this->viewportFrame(['channel' => 'email'], limit: 50),
            SignalSource::WEBSOCKET,
            PendingFrameTestPage::PAGE,
        );
        $router->dispatchTableViewport(
            $this->viewportFrame(['channel' => 'email'], sort: TableSortOrderDTO::of(new TableSortDTO('key', TableConstants::ORDER_DESC))),
            SignalSource::WEBSOCKET,
            PendingFrameTestPage::PAGE,
        );

        $this->assertSame([], $browser->facetCounts);
    }

    public function testATableWhoseViewDeclaredNoOptionsIsNeverCounted(): void
    {
        [$browser, $router] = $this->mountFacetedTable([]);

        $router->dispatchTableViewport($this->viewportFrame([]), SignalSource::WEBSOCKET, PendingFrameTestPage::PAGE);
        $router->dispatchTableViewport($this->viewportFrame(['channel' => 'email']), SignalSource::WEBSOCKET, PendingFrameTestPage::PAGE);

        $this->assertSame([], $browser->facetCounts);
    }

    /**
     * Mounts an identified, subscribed connection whose view declared options for the fixture table.
     *
     * @param array<string, list<int|float|string|bool>> $facets Options the view declared, by filter key
     * @return array{0: PendingFrameTestBrowser, 1: PageSignalRouter} Browser fixture and router under test
     */
    private function mountFacetedTable(array $facets = self::FACETS): array
    {
        $browser = $this->mountBrowser();
        $browser->identity = ConnectionIdentity::resolved(self::USER_ID);
        $this->registerSubscription([]);
        Hilos::$sr?->setTableFacets(self::ACCEPT_KEY, PendingFrameTestPage::TABLE_KEY, $facets);

        return [$browser, $this->router(new PendingFrameTestPageFactory(new PendingFrameTestAgent()))];
    }

    /**
     * Builds one viewport frame for the fixture table.
     *
     * @param array<string, mixed> $filter Filter map the window asks for
     * @param int $limit Window size
     * @param ?int $pageIndex Page the window jumps to, or null for the first window
     * @param ?TableSortOrderDTO $sort Order the window asks for, or null for none
     * @return WebSocketTableViewportSignalDTO Viewport frame
     */
    private function viewportFrame(
        array $filter,
        int $limit = 25,
        ?int $pageIndex = null,
        ?TableSortOrderDTO $sort = null,
    ): WebSocketTableViewportSignalDTO {
        return new WebSocketTableViewportSignalDTO(
            acceptKey: self::ACCEPT_KEY,
            page: PendingFrameTestPage::PAGE,
            tableKey: PendingFrameTestPage::TABLE_KEY,
            filter: $filter,
            sort: $sort,
            limit: $limit,
            pageIndex: $pageIndex,
        );
    }

    /**
     * Puts a live page subscription for the test connection into the registry.
     *
     * The worker records it the moment it dispatches the subscribe, parked or not, which is
     * exactly why an update of the same connection waits for the identity and nothing else.
     *
     * @param array<string, string> $params Params the subscription starts on
     */
    private function registerSubscription(array $params): void
    {
        Hilos::$sr?->subscribeToPage(
            PendingFrameTestPage::PAGE,
            new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, PendingFrameTestPage::PAGE, $params),
        );
    }

    /**
     * Builds one subscription-update frame for the test connection.
     *
     * @param array<string, string> $params Params the frame carries
     * @return WebSocketPageUpdateSubscriptionSignalDTO Update frame
     */
    private function updateFrame(array $params): WebSocketPageUpdateSubscriptionSignalDTO
    {
        return new WebSocketPageUpdateSubscriptionSignalDTO(
            self::ACCEPT_KEY,
            PendingFrameTestPage::PAGE,
            $params,
        );
    }

    /**
     * Returns the params the subscription registry holds for the test connection.
     *
     * @return array<string, mixed> Registered subscription params
     */
    private function subscriptionParams(): array
    {
        return Hilos::$sr?->pageSubscription(self::ACCEPT_KEY)?->params ?? [];
    }

    /**
     * Mounts the framework's runtime state, with this process as the master that writes the lag.
     *
     * @param int $windowMs Window lag to start with, in milliseconds
     */
    private function mountTableLag(int $windowMs): void
    {
        Hilos::$rt = new PendingFrameTestRtContext();
        Hilos::$rt->mountFeatureRuntime([]);
        RtTruthSourceRegistry::registerDaemon(StateTableLagRuntime::RT_ITEM);
        $this->setTableLag($windowMs);
    }

    /**
     * Writes the window lag as the master does in answer to test:table:lag.
     *
     * The write goes on the sync wire like any other; the RT sync frame it queues is drained
     * here so the assertions read only what the router sent.
     *
     * @param int $windowMs Window lag, in milliseconds
     */
    private function setTableLag(int $windowMs): void
    {
        Hilos::$rt?->hilosTableLagRuntime?->actions->set($windowMs, 0);
        while (Hilos::$sr?->getNextQueuedSignal() !== null) {
            continue;
        }
    }

    /**
     * Sends the log to a file of this test's own, so a line it must not write can be looked for.
     */
    private function captureLog(): void
    {
        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'hilos-table-lag-log');
        Logger::setLogFile($this->logFile);
    }

    /**
     * Mounts the browser context fixture whose identity the test drives.
     *
     * @return PendingFrameTestBrowser Mounted browser context fixture
     */
    private function mountBrowser(): PendingFrameTestBrowser
    {
        $browser = new PendingFrameTestBrowser();
        Hilos::$browser = $browser;

        return $browser;
    }

    /**
     * Builds the router with the fixture page's action route registered.
     *
     * @param PendingFrameTestPageFactory $factory Page factory fixture
     * @return PageSignalRouter Router under test
     */
    private function router(PendingFrameTestPageFactory $factory): PageSignalRouter
    {
        return new PageSignalRouter(
            $factory,
            new ActionRouteConfig([PendingFrameTestPage::ACTION => PendingFrameTestPage::PAGE]),
        );
    }

    /**
     * Returns the fixture page the router dispatched into.
     *
     * @param PendingFrameTestPageFactory $factory Page factory fixture
     * @return PendingFrameTestPage Fixture page
     * @throws PageNotFoundException When the fixture page cannot be resolved
     */
    private function page(PendingFrameTestPageFactory $factory): PendingFrameTestPage
    {
        $page = $factory->getPage(PendingFrameTestPage::PAGE);
        $this->assertInstanceOf(PendingFrameTestPage::class, $page);

        return $page;
    }

    /**
     * Drains the signal queue and returns the subscription error it holds, if any.
     *
     * @return ?PageSubscriptionErrorSignalData Subscription error sent to the client
     */
    private function queuedSubscriptionError(): ?PageSubscriptionErrorSignalData
    {
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() !== SignalConstants::SUBSCRIPTION_PAGE_ERROR) {
                continue;
            }

            $data = $signal->data;
            $error = $data instanceof WebSocketSignalData ? $data->data : null;
            if ($error instanceof PageSubscriptionErrorSignalData) {
                return $error;
            }
        }

        return null;
    }
}

final class PendingFrameTestPage extends AbstractPage
{
    public const string PAGE = 'pending_frame';

    public const string ACTION = 'pending_frame_action';

    public const string TABLE_KEY = 'pending_frame_rows';

    /** Half a second of ceiling plus the margin that keeps a loaded box from flaking. */
    public const int PAST_THE_DEADLINE_MICROSECONDS = 550_000;

    /** Judged by identity on both doors, so a pending answer is what the test varies. */
    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::AUTHENTICATED;

    /** @var list<string> Handlers that ran, in the order the router reached them */
    public array $handled = [];

    /**
     * Records that the subscribe handler ran (only reachable once the guard passed).
     *
     * @param string $acceptKey WebSocket accept key (unused)
     * @param PageRouteParams $params Route params (unused)
     */
    protected function onSubscribeBeforeResponse(string $acceptKey, PageRouteParams $params): void
    {
        $this->handled[] = 'subscribe';
    }

    /**
     * Records that the action handler ran (only reachable once the guards passed).
     *
     * @param string $acceptKey WebSocket accept key (unused)
     * @param string $action Action name (unused)
     * @param ActionPayloadDTO $dto Action payload (unused)
     * @return ?ActionReplyDTO Always null; the fixture only records that it ran
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        $this->handled[] = 'action';

        return null;
    }

    /**
     * Records that the update handler ran (only reachable once the guards passed).
     *
     * @param string $acceptKey WebSocket accept key (unused)
     * @param PageRouteParams $params Merged route params (unused)
     */
    public function onUpdateSubscription(string $acceptKey, PageRouteParams $params): void
    {
        $this->handled[] = 'update';
    }
}

/**
 * Browser context fixture whose identity answer the test moves from pending to resolved.
 */
final class PendingFrameTestBrowser extends BrowserContext
{
    /** Identity this fixture reports for every accept key. */
    public ConnectionIdentity $identity;

    /** @var list<string> Table keys whose window delivery was reached */
    public array $windows = [];

    /** Whether the page guards refuse the next subscription verdict. */
    public bool $refuseSubscriptionAccess = false;

    /** @var list<array{0: string, 1: ?list<string>}> Tables whose counts were sent, with the filters they were narrowed to */
    public array $facetCounts = [];

    /** @var list<array{0: string, 1: list<string>}> Tables whose windows were told what their tab draws, with the fields named */
    public array $declaredRendered = [];

    /** @var list<array{0: string, 1: string}> Tables whose held row was answered, with the row named */
    public array $focusAnswers = [];

    public function __construct()
    {
        parent::__construct();
        $this->identity = ConnectionIdentity::pending();
    }

    /**
     * Returns the identity the test set, standing in for the project's connection registry.
     *
     * @param string $acceptKey Subscriber accept key (unused in the fixture)
     * @return ConnectionIdentity Identity the test is currently reporting
     */
    protected function resolveConnectionIdentity(string $acceptKey): ConnectionIdentity
    {
        return $this->identity;
    }

    /**
     * Refuses on demand, standing in for a page guard that says no.
     *
     * The fixture page declares no browser config, so the real method passes everything and
     * a refusal has to be armed by hand - which is all this test needs from a guard.
     *
     * @param string $page Page name from the subscription request (unused)
     * @param string $acceptKey Subscribing WebSocket accept key (unused)
     * @param PageRouteParams $params Route params for this page subscription (unused)
     * @throws PageForbiddenException When the test has armed a refusal
     */
    public function assertSubscriptionAccess(string $page, string $acceptKey, PageRouteParams $params): void
    {
        if ($this->refuseSubscriptionAccess) {
            throw new PageForbiddenException();
        }
    }

    /**
     * Records the window delivery instead of building one from a table nothing mounted.
     *
     * @param string $page Page the table belongs to (unused)
     * @param string $acceptKey Subscribing WebSocket accept key (unused)
     * @param TableViewportSubscription $viewport Window descriptor
     * @return bool Always true; reaching this method is what the test asserts
     */
    public function sendTableWindow(string $page, string $acceptKey, TableViewportSubscription $viewport): bool
    {
        $this->windows[] = $viewport->tableKey;

        return true;
    }

    /**
     * Records the counts being sent instead of counting a table nothing mounted.
     *
     * @param string $page Page the table belongs to (unused)
     * @param string $acceptKey Connection the counts are for (unused)
     * @param TableViewportSubscription $viewport Window whose set would be counted
     * @param ?list<string> $only Filters to count again, or null for every declared filter
     */
    public function sendTableFacetCounts(string $page, string $acceptKey, TableViewportSubscription $viewport, ?array $only = null): void
    {
        $this->facetCounts[] = [$viewport->tableKey, $only];
    }

    /**
     * Records the drawn fields being laid over a window instead of reading a table nothing mounted.
     *
     * @param string $page Page the table belongs to (unused)
     * @param string $acceptKey Connection holding the window (unused)
     * @param TableViewportSubscription $viewport Window the fields would be laid over
     * @param list<string> $rendered Fields inside the row slots the tab draws
     */
    public function declareTableRendered(string $page, string $acceptKey, TableViewportSubscription $viewport, array $rendered): void
    {
        $this->declaredRendered[] = [$viewport->tableKey, $rendered];
    }

    /**
     * Records the held row being answered instead of reading a table nothing mounted.
     *
     * @param string $page Page the table belongs to (unused)
     * @param string $acceptKey Connection holding the focus (unused)
     * @param string $tableKey Table key the row belongs to
     * @param string $rowKey Row the connection holds in focus
     */
    public function answerTableRowFocus(string $page, string $acceptKey, string $tableKey, string $rowKey): void
    {
        $this->focusAnswers[] = [$tableKey, $rowKey];
    }
}

/**
 * Page factory fixture exposing the identity-judged test page.
 *
 * @extends AbstractPageFactory<PendingFrameTestAgent>
 */
final class PendingFrameTestPageFactory extends AbstractPageFactory
{
    /**
     * Creates the identity-judged test page.
     *
     * @param string $pageName Page name
     * @return AbstractPage Test page instance
     * @throws PageNotFoundException When an unexpected page is requested
     */
    protected function createPage(string $pageName): AbstractPage
    {
        if ($pageName === PendingFrameTestPage::PAGE) {
            return new PendingFrameTestPage($this->agent);
        }

        throw new PageNotFoundException($pageName);
    }

    /**
     * Reports whether the identity-judged test page is available.
     *
     * @param string $pageName Page name
     * @return bool True for the identity-judged test page
     */
    public function hasPage(string $pageName): bool
    {
        return $pageName === PendingFrameTestPage::PAGE;
    }
}

final class PendingFrameTestAgent implements PageAgentInterface
{
    /**
     * Returns the fixture agent id.
     *
     * @return string Agent id
     */
    public function getId(): string
    {
        return 'unit-pending-frame-agent';
    }

    /**
     * Returns the fixture signal source.
     *
     * @return SignalSourceInterface Signal source
     */
    public function getAgentSignalSource(): SignalSourceInterface
    {
        return new SignalSource(SignalSource::AGENT, 'pending_frame_agent');
    }
}

/**
 * Runtime context of a project that mounts nothing of its own.
 *
 * The table lag row is framework-owned and mounted for every project, so the router finds it
 * here exactly as it does in a real worker.
 */
final class PendingFrameTestRtContext extends RtContext
{
    public function configure(): void
    {
    }
}
