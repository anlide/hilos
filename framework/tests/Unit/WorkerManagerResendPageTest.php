<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserPageBindings;
use Hilos\Core\Browser\Config\BrowserPageConfig;
use Hilos\Core\Browser\Config\BrowserSourceConfig;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\AbstractPageFactory;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Page\Exception\PageForbiddenException;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Page\Exception\PageNotFoundException;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\Exception\PageServiceUnavailableException;
use Hilos\Core\Page\Exception\PageSubscriptionException;
use Hilos\Core\Page\Exception\PageUnauthorizedException;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageErrorMessages;
use Hilos\Core\Page\PageResendOutcome;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalNameInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\SignalTypeInterface;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\Table\DTO\TableProgressDTO;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\DTO\TableViewportDeltaDTO;
use Hilos\Core\Table\Definition\SelfSnapshotTable;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\Row\GenericTableRow;
use Hilos\Core\Table\TableProgressScope;
use Hilos\Database\Pages\PageCatalogConstants;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUnsubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUpdateSubscriptionSignalDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Utils\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests the worker's half of the re-send after a failed delivery (HIL-1236), including first-answer debt (HIL-1351).
 *
 * The browser fan-out finds out that a page is owed whole, and the worker answers it through the
 * page of the agent serving the subscription. Which agent that is, the worker learns when the
 * subscribe - or a re-decision of rights, which may hand the subscription to another instance -
 * reaches it, and writes beside the subscription in its own mirror; until this leaf only the
 * master wrote it. The re-send then runs under that agent and that connection, the way a message
 * to the agent does.
 */
final class WorkerManagerResendPageTest extends TestCase
{
    private string $logFile;

    /** Captures the first failure and any accidental repeated probe log lines. */
    protected function setUp(): void
    {
        $this->logFile = tempnam(sys_get_temp_dir(), 'hilos-worker-page-debt');
        Logger::setLogFile($this->logFile);
        Hilos::$sr = new SignalRouter();
        Hilos::$browser = new ResendWorkerTestBrowser();
    }

    /** Releases the fixture's facades and temporary log. */
    protected function tearDown(): void
    {
        SourceInterestRegistry::releaseConsumer(SourceConsumer::page('ak-1'));
        Hilos::$sr = null;
        Hilos::$table = null;
        Hilos::resetBrowser();
        Logger::resetLogFile();
        unlink($this->logFile);

        parent::tearDown();
    }

    public function testASubscribeRemembersTheAgentThatServedIt(): void
    {
        $this->subscribedManager(ResendWorkerTestManager::FIRST);

        $subscription = Hilos::$sr?->pageSubscription('ak-1');
        $this->assertSame(ResendWorkerTestAgent::AGENT_TYPE, $subscription?->agentType);
        $this->assertSame(ResendWorkerTestManager::FIRST, $subscription->agentIndex);
    }

    /**
     * A re-decision of rights may hand the subscription to another instance of the agent (HIL-627),
     * and the page owed after a failed delivery is that instance's to answer.
     */
    public function testAReDecisionRemembersTheAgentThatServedIt(): void
    {
        $manager = $this->subscribedManager(ResendWorkerTestManager::FIRST);

        $this->handle($manager, ResendWorkerTestManager::SECOND, SignalTypeConstants::PAGE_ACCESS_REASSESS);

        $this->assertSame(ResendWorkerTestManager::SECOND, Hilos::$sr?->pageSubscription('ak-1')?->agentIndex);
    }

    public function testTheReSendRunsThePageOfTheServingAgentUnderThatAgentAndConnection(): void
    {
        $manager = $this->subscribedManager(ResendWorkerTestManager::FIRST);
        $page = $this->servedPage($manager, ResendWorkerTestManager::FIRST);
        $this->drainQueue();

        $outcome = $manager->resendPage(ResendWorkerTestPage::PAGE, 'ak-1', []);

        $this->assertSame(PageResendOutcome::Answered, $outcome);
        $this->assertSame(2, $page->subscribeCount);
        $this->assertSame(
            [ResendWorkerTestAgent::AGENT_TYPE . ':' . ResendWorkerTestManager::FIRST, 'ak-1'],
            $page->lastFrame,
        );
        $this->assertSame(0, $this->servedPage($manager, ResendWorkerTestManager::SECOND)->subscribeCount);
        $this->assertSame([SignalTypeConstants::PAGE_RESPONSE], $this->drainQueue());
    }

    public function testAConnectionWithNoSubscriptionHereIsUnserved(): void
    {
        $manager = $this->subscribedManager(ResendWorkerTestManager::FIRST);

        $this->assertSame(PageResendOutcome::Unserved, $manager->resendPage(ResendWorkerTestPage::PAGE, 'ak-other', []));
    }

    public function testAConnectionOnAnotherPageIsUnserved(): void
    {
        $manager = $this->subscribedManager(ResendWorkerTestManager::FIRST);

        $this->assertSame(PageResendOutcome::Unserved, $manager->resendPage('resend_worker_other_page', 'ak-1', []));
    }

    public function testASubscriptionWhoseAgentIsGoneIsUnserved(): void
    {
        $manager = $this->subscribedManager(ResendWorkerTestManager::FIRST);
        $manager->removeAgent(ResendWorkerTestManager::FIRST);
        $this->drainQueue();

        $this->assertSame(PageResendOutcome::Unserved, $manager->resendPage(ResendWorkerTestPage::PAGE, 'ak-1', []));
        $this->assertSame([], $this->drainQueue());
    }

    /**
     * The deletion refusal runs under the serving agent, releases the page, then sends the line.
     */
    public function testTheDeletionRefusalRunsUnderTheServingAgentAndReleasesBeforeTheFrame(): void
    {
        $manager = $this->subscribedManager(ResendWorkerTestManager::FIRST, ['countryCode' => 'se']);
        $page = $this->servedPage($manager, ResendWorkerTestManager::FIRST);
        $this->assertSame([SignalTypeConstants::PAGE_RESPONSE], $this->drainQueue());
        $manager->signals->marks = [];
        Hilos::$browser->guardParams = null;

        $outcome = $manager->refuseDeletedResource(ResendWorkerTestPage::PAGE, 'ak-1');

        $this->assertSame(PageResendOutcome::Refused, $outcome);
        $this->assertSame(1, $page->subscribeCount);
        $this->assertSame(1, $page->unsubscribeCount);
        $this->assertSame(
            [ResendWorkerTestAgent::AGENT_TYPE . ':' . ResendWorkerTestManager::FIRST, 'ak-1'],
            $page->releaseFrame,
        );
        $this->assertSame(0, $this->servedPage($manager, ResendWorkerTestManager::SECOND)->unsubscribeCount);
        $this->assertSame(['release', SignalConstants::SUBSCRIPTION_PAGE_ERROR], $manager->signals->marks);
        $this->assertSame(['countryCode' => 'se'], Hilos::$browser->guardParams);
        $missing = new PageResourceNotFoundException();
        $this->assertSubscriptionError(
            $missing->httpCode,
            $missing->errorCode,
            PageErrorMessages::RESOURCE_DELETED_MESSAGE,
        );
        $this->assertTrue(Hilos::$sr->hasPageDeliveryFailure('ak-1'));
        $this->assertSame(ResendWorkerTestPage::PAGE, Hilos::$sr->pageSubscription('ak-1')?->page);
        $this->assertSame(['countryCode' => 'se'], Hilos::$sr->pageSubscription('ak-1')?->params);
    }

    public function testADeletionRefusalForAConnectionWithNoSubscriptionHereIsUnserved(): void
    {
        $manager = $this->subscribedManager(ResendWorkerTestManager::FIRST);
        $this->drainQueue();

        $this->assertSame(
            PageResendOutcome::Unserved,
            $manager->refuseDeletedResource(ResendWorkerTestPage::PAGE, 'ak-other'),
        );
        $this->assertSame([], $this->drainQueue());
        $this->assertSame(0, $this->servedPage($manager, ResendWorkerTestManager::FIRST)->unsubscribeCount);
    }

    public function testADeletionRefusalForAnotherPageIsUnserved(): void
    {
        $manager = $this->subscribedManager(ResendWorkerTestManager::FIRST);
        $this->drainQueue();

        $this->assertSame(PageResendOutcome::Unserved, $manager->refuseDeletedResource('resend_worker_other_page', 'ak-1'));
        $this->assertSame(PageResendOutcome::Unserved, $manager->refuseDeletedResource('', 'ak-1'));
        $this->assertSame([], $this->drainQueue());
        $this->assertSame(0, $this->servedPage($manager, ResendWorkerTestManager::FIRST)->unsubscribeCount);
    }

    public function testADeletionRefusalWhoseAgentIsGoneIsUnserved(): void
    {
        $manager = $this->subscribedManager(ResendWorkerTestManager::FIRST);
        $manager->removeAgent(ResendWorkerTestManager::FIRST);
        $this->drainQueue();

        $this->assertSame(PageResendOutcome::Unserved, $manager->refuseDeletedResource(ResendWorkerTestPage::PAGE, 'ak-1'));
        $this->assertSame([], $this->drainQueue());
    }

    /**
     * A fresh resource denial and a later refresh of the same key carry no deletion line.
     */
    public function testAFreshDenialAndARefreshCarryNoDeletionLine(): void
    {
        $manager = new ResendWorkerTestManager();
        $page = $this->servedPage($manager, ResendWorkerTestManager::FIRST);
        $page->failureCode = 404;
        $this->handle($manager, ResendWorkerTestManager::FIRST, SignalTypeConstants::PAGE_SUBSCRIBE);
        $missing = new PageResourceNotFoundException();
        $this->assertSubscriptionError($missing->httpCode, $missing->errorCode, null);

        $page->failureCode = null;
        $this->handle($manager, ResendWorkerTestManager::FIRST, SignalTypeConstants::PAGE_SUBSCRIBE);
        $this->wholeAnswer();
        $this->assertSame(
            PageResendOutcome::Refused,
            $manager->refuseDeletedResource(ResendWorkerTestPage::PAGE, 'ak-1'),
        );
        $this->assertSubscriptionError(
            $missing->httpCode,
            $missing->errorCode,
            PageErrorMessages::RESOURCE_DELETED_MESSAGE,
        );

        $page->failureCode = 404;
        $this->handle($manager, ResendWorkerTestManager::FIRST, SignalTypeConstants::PAGE_SUBSCRIBE);
        $this->assertSubscriptionError($missing->httpCode, $missing->errorCode, null);
    }

    /** A cleanup failure is logged and the deletion frame still goes out. */
    public function testAThrowingCleanupStillSendsTheDeletionRefusal(): void
    {
        $manager = $this->subscribedManager(ResendWorkerTestManager::FIRST);
        $page = $this->servedPage($manager, ResendWorkerTestManager::FIRST);
        $this->drainQueue();
        $manager->signals->marks = [];
        $page->throwOnUnsubscribe = true;
        $logged = file_get_contents($this->logFile);

        $this->assertSame(
            PageResendOutcome::Refused,
            $manager->refuseDeletedResource(ResendWorkerTestPage::PAGE, 'ak-1'),
        );

        $this->assertSame(1, $page->subscribeCount);
        $this->assertSame(['release', SignalConstants::SUBSCRIPTION_PAGE_ERROR], $manager->signals->marks);
        $missing = new PageResourceNotFoundException();
        $this->assertSubscriptionError(
            $missing->httpCode,
            $missing->errorCode,
            PageErrorMessages::RESOURCE_DELETED_MESSAGE,
        );
        $this->assertTrue(Hilos::$sr->hasPageDeliveryFailure('ak-1'));
        $this->assertNotNull(Hilos::$sr->pageSubscription('ak-1'));
        $this->assertStringContainsString(
            'Page release after a refusal failed: page=' . ResendWorkerTestPage::PAGE
            . ', acceptKey=ak-1, release failed',
            (string)file_get_contents($this->logFile),
        );
        $this->assertNotSame($logged, file_get_contents($this->logFile));
    }

    /** @return iterable<string, array{int, string}> First errors crossed with the two source roads */
    public static function failedAnswersAndSources(): iterable
    {
        foreach ([500, 404, 403] as $httpCode) {
            foreach ([SourceChange::KIND_DB, SourceChange::KIND_RT] as $kind) {
                yield "$httpCode/$kind" => [$httpCode, $kind];
            }
        }
    }

    /**
     * Runs subscribe, worker registration, real full resend and subsequent viewport delivery.
     *
     * @param int $httpCode First answer's error status
     * @param string $kind Source road that supplies recovery facts
     */
    #[DataProvider('failedAnswersAndSources')]
    public function testFirstFailureRecoversWholeBeforeAnyViewportExists(int $httpCode, string $kind): void
    {
        $manager = $this->managerWithTable();
        $page = $this->servedPage($manager, ResendWorkerTestManager::FIRST);
        $page->failureCode = $httpCode;
        $this->handle($manager, ResendWorkerTestManager::FIRST, SignalTypeConstants::PAGE_SUBSCRIBE);
        $this->assertError($httpCode);
        $this->assertSame(ResendWorkerTestManager::FIRST, Hilos::$sr->pageSubscription('ak-1')?->agentIndex);
        $this->assertSame([], Hilos::$sr->getTableViewports('ak-1'));

        // An unrelated fact does not try this debt, and an empty flush is not a timer.
        Hilos::$browser->record(SourceChange::rtUpdated('unrelated', 'a', ['label' => 'Other']));
        Hilos::$browser->flushToSignalRouter();
        Hilos::$browser->flushToSignalRouter();
        $this->assertSame(1, $page->subscribeCount);
        $this->assertSame([], $this->drainQueue());

        $page->failureCode = null;
        $this->flushFacts($kind);
        $payload = $this->wholeAnswer();
        $this->assertSame('mine', $payload->data['own']);
        $this->assertSame(['a', 'b'], array_column($payload->windows[ResendWorkerTestTable::TABLE]['rows'], PagePayload::rowKey));
        $this->assertSame(2, $page->subscribeCount);
        $this->assertFalse(Hilos::$sr->hasPageDeliveryFailure('ak-1'));

        $this->flushFacts($kind, twoRows: false);
        $signal = Hilos::$sr->getNextQueuedSignal();
        $this->assertSame(SignalTypeConstants::TABLE_VIEWPORT_DELTA, $signal?->signalName->getName());
        $this->assertInstanceOf(TableViewportDeltaDTO::class, $signal->data->data);
        $this->assertSame('a', $signal->data->data->rowKey);
        $this->assertSame('changed', $signal->data->data->row[PagePayload::slots][ResendWorkerTestTable::TABLE]['label']);
        $this->assertSame([SignalTypeConstants::TABLE_PROGRESS], $this->drainQueue());
        $this->assertSame(2, $page->subscribeCount, 'The next fact must not build another full page');
    }

    /** @return iterable<string, array{int}> Deferred failure and refusal verdicts */
    public static function deferredFailures(): iterable
    {
        yield 'failure' => [500];
        yield 'refusal' => [404];
    }

    /**
     * A pending first answer is registered before its verdict, and acquires debt only on that verdict.
     *
     * @param int $httpCode Deferred answer's status
     */
    #[DataProvider('deferredFailures')]
    public function testDeferredFirstFailureIsOwedWhenTheRegisteredSubscriptionIsJudged(int $httpCode): void
    {
        $manager = $this->managerWithTable();
        $page = $this->servedPage($manager, ResendWorkerTestManager::FIRST);
        $page->failureCode = $httpCode;
        Hilos::$browser->identity = ConnectionIdentity::pending();
        $this->handle($manager, ResendWorkerTestManager::FIRST, SignalTypeConstants::PAGE_SUBSCRIBE);
        $this->assertNotNull(Hilos::$sr->pageSubscription('ak-1'));
        $this->assertFalse(Hilos::$sr->hasPageDeliveryFailure('ak-1'));
        $this->assertSame([], $this->drainQueue());

        Hilos::$browser->identity = ConnectionIdentity::resolved(5);
        $manager->releasePendingPageFrames();
        $this->assertError($httpCode);
        $this->assertTrue(Hilos::$sr->hasPageDeliveryFailure('ak-1'));
        $page->failureCode = null;
        Hilos::$browser->recordSourceStaleness(ResendWorkerTestTable::SOURCE, ['a', 'b']);
        Hilos::$browser->flushToSignalRouter();
        $this->wholeAnswer();
        $this->assertFalse(Hilos::$sr->hasPageDeliveryFailure('ak-1'));
    }

    /** A fresh subscribe drops its predecessor's debt before parking or entering the 401 auth gate. */
    public function testNewSubscribeDropsOldDebtBeforeItsDeferredAuthVerdict(): void
    {
        $manager = $this->managerWithTable();
        $this->servedPage($manager, ResendWorkerTestManager::FIRST)->failureCode = 500;
        $this->handle($manager, ResendWorkerTestManager::FIRST, SignalTypeConstants::PAGE_SUBSCRIBE);
        $this->assertError(500);
        Hilos::$browser->identity = ConnectionIdentity::pending();
        $this->handle($manager, ResendWorkerTestManager::FIRST, SignalTypeConstants::PAGE_SUBSCRIBE);
        $this->assertFalse(Hilos::$sr->hasPageDeliveryFailure('ak-1'));
        $this->assertSame([], $this->drainQueue());

        Hilos::$browser->identity = ConnectionIdentity::resolved(null);
        $manager->releasePendingPageFrames();
        $this->assertError(401);
        $this->assertFalse(Hilos::$sr->hasPageDeliveryFailure('ak-1'));
    }

    /** Immediate auth denial never creates page debt either. */
    public function testImmediateAuthDenialIsNotAPageDebt(): void
    {
        Hilos::$browser->identity = ConnectionIdentity::resolved(null);
        $this->subscribedManager(ResendWorkerTestManager::FIRST);
        $this->assertError(401);
        $this->assertNotNull(Hilos::$sr->pageSubscription('ak-1'));
        $this->assertFalse(Hilos::$sr->hasPageDeliveryFailure('ak-1'));
    }

    /** An auth denial reached through the deletion port sets no page debt and carries no deletion line. */
    public function testADeletionRefusalYieldsToAuthWithoutDebtOrALine(): void
    {
        $manager = $this->subscribedManager(ResendWorkerTestManager::FIRST);
        $page = $this->servedPage($manager, ResendWorkerTestManager::FIRST);
        $this->assertSame([SignalTypeConstants::PAGE_RESPONSE], $this->drainQueue());
        Hilos::$browser->identity = ConnectionIdentity::resolved(null);

        $this->assertSame(
            PageResendOutcome::Refused,
            $manager->refuseDeletedResource(ResendWorkerTestPage::PAGE, 'ak-1'),
        );

        $this->assertSame(1, $page->subscribeCount);
        $denial = new PageUnauthorizedException();
        $this->assertSubscriptionError($denial->httpCode, $denial->errorCode, null);
        $this->assertFalse(Hilos::$sr->hasPageDeliveryFailure('ak-1'));
        $this->assertSame(ResendWorkerTestPage::PAGE, Hilos::$sr->pageSubscription('ak-1')?->page);
    }

    /** @return iterable<string, array{class-string<PageSubscriptionException>}> Guard refusals that outrank a deletion */
    public static function rightsAboveTheResource(): iterable
    {
        yield 'forbidden' => [PageForbiddenException::class];
        yield 'unavailable' => [PageServiceUnavailableException::class];
    }

    /**
     * A 403 or 503 from the guards goes out as that status, with no deletion line, and the page stays owed.
     *
     * @param class-string<PageSubscriptionException> $refusalClass Guard refusal to plant
     */
    #[DataProvider('rightsAboveTheResource')]
    public function testADeletionRefusalYieldsToARightWithoutTheDeletionLine(string $refusalClass): void
    {
        $manager = $this->subscribedManager(ResendWorkerTestManager::FIRST);
        $page = $this->servedPage($manager, ResendWorkerTestManager::FIRST);
        $this->assertSame([SignalTypeConstants::PAGE_RESPONSE], $this->drainQueue());
        $refusal = new $refusalClass();
        $this->assertInstanceOf(PageSubscriptionException::class, $refusal);
        Hilos::$browser->guardRefusal = $refusal;

        $this->assertSame(
            PageResendOutcome::Refused,
            $manager->refuseDeletedResource(ResendWorkerTestPage::PAGE, 'ak-1'),
        );

        $this->assertSame(1, $page->subscribeCount);
        $this->assertSubscriptionError($refusal->httpCode, $refusal->errorCode, null);
        $this->assertTrue(Hilos::$sr->hasPageDeliveryFailure('ak-1'));
        $this->assertNotNull(Hilos::$sr->pageSubscription('ak-1'));
    }

    /** Successful real resubscribe starts clean, then ordinary source delivery stays incremental. */
    public function testSuccessfulResubscribeClearsTheDebtBeforePageDispatch(): void
    {
        $manager = $this->managerWithTable();
        $page = $this->servedPage($manager, ResendWorkerTestManager::FIRST);
        $page->failureCode = 500;
        $this->handle($manager, ResendWorkerTestManager::FIRST, SignalTypeConstants::PAGE_SUBSCRIBE);
        $this->assertError(500);
        $page->failureCode = null;
        $this->handle($manager, ResendWorkerTestManager::FIRST, SignalTypeConstants::PAGE_SUBSCRIBE);
        $this->wholeAnswer();
        $this->assertSame([false, false], $page->debtsAtEntry);
        $this->assertFalse(Hilos::$sr->hasPageDeliveryFailure('ak-1'));
        $this->flushFacts(SourceChange::KIND_DB, twoRows: false);
        $this->assertSame([SignalTypeConstants::TABLE_VIEWPORT_DELTA, SignalTypeConstants::TABLE_PROGRESS], $this->drainQueue());
    }

    /** @return iterable<string, array{string}> Two verdict doors that can erase an already delivered scope */
    public static function changedVerdicts(): iterable
    {
        yield 'reassess' => [SignalTypeConstants::PAGE_ACCESS_REASSESS];
        yield 'update' => [SignalTypeConstants::PAGE_UPDATE_SUBSCRIPTION];
    }

    /**
     * Both doors report fresh errors, retain debt, and pay it when their full answer leaves.
     *
     * @param string $type Verdict door to exercise
     */
    #[DataProvider('changedVerdicts')]
    public function testChangedVerdictKeepsDebtUntilItsWholeAnswer(string $type): void
    {
        $manager = $this->managerWithTable();
        $page = $this->servedPage($manager, ResendWorkerTestManager::FIRST);
        $this->handle($manager, ResendWorkerTestManager::FIRST, SignalTypeConstants::PAGE_SUBSCRIBE);
        $this->wholeAnswer();
        foreach ([403, 500, 503] as $httpCode) {
            $page->failureCode = $httpCode;
            $this->handle($manager, ResendWorkerTestManager::FIRST, $type);
            $this->assertError($httpCode);
            $this->assertTrue(Hilos::$sr->hasPageDeliveryFailure('ak-1'));

            // A live viewport makes stray deltas and progress observable on these failed probes.
            $logged = file_get_contents($this->logFile);
            $before = $page->subscribeCount;
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $this->flushFacts(SourceChange::KIND_DB);
                $this->assertSame($before + $attempt + 1, $page->subscribeCount, 'One whole-page try per flush');
                $this->assertSame([], $this->drainQueue());
                $this->assertTrue(Hilos::$sr->hasPageDeliveryFailure('ak-1'));
            }
            $this->assertSame($logged, file_get_contents($this->logFile));
        }
        $page->failureCode = null;
        $this->handle($manager, ResendWorkerTestManager::FIRST, $type);
        $this->wholeAnswer();
        $this->assertFalse(Hilos::$sr->hasPageDeliveryFailure('ak-1'));
    }

    /** A real refused/failed page probe cannot release the separately requested window. */
    public function testWindowRequestWaitsThroughRefusedAndFailedPageProbes(): void
    {
        $manager = $this->managerWithTable();
        $page = $this->servedPage($manager, ResendWorkerTestManager::FIRST);
        $page->failureCode = 500;
        $this->handle($manager, ResendWorkerTestManager::FIRST, SignalTypeConstants::PAGE_SUBSCRIBE);
        $this->assertError(500);
        $logged = file_get_contents($this->logFile);
        $viewport = new TableViewportSubscription(tableKey: ResendWorkerTestTable::TABLE, limit: 10);
        foreach ([404, 404, 500, 500] as $httpCode) {
            $page->failureCode = $httpCode;
            $this->assertFalse(Hilos::$browser->sendTableWindow(ResendWorkerTestPage::PAGE, 'ak-1', $viewport));
            $this->assertSame([], $this->drainQueue());
            $this->assertTrue(Hilos::$sr->hasPageDeliveryFailure('ak-1'));
        }
        $this->assertSame($logged, file_get_contents($this->logFile));
        $page->failureCode = null;
        $this->assertTrue(Hilos::$browser->sendTableWindow(ResendWorkerTestPage::PAGE, 'ak-1', $viewport));
        $this->assertSame([SignalTypeConstants::PAGE_RESPONSE, SignalTypeConstants::TABLE_WINDOW], $this->drainQueue());
        $this->assertFalse(Hilos::$sr->hasPageDeliveryFailure('ak-1'));
    }

    /** A throwing after-response hook cannot turn an already queued full recovery into another debt. */
    public function testAfterResponseFailureDoesNotUndoAnAnsweredProbe(): void
    {
        $manager = $this->managerWithTable();
        $page = $this->servedPage($manager, ResendWorkerTestManager::FIRST);
        $page->failureCode = 500;
        $this->handle($manager, ResendWorkerTestManager::FIRST, SignalTypeConstants::PAGE_SUBSCRIBE);
        $this->assertError(500);
        $logged = file_get_contents($this->logFile);
        $page->failureCode = null;
        $page->failAfterResponse = true;
        $this->assertSame(PageResendOutcome::Answered, $manager->resendPage(ResendWorkerTestPage::PAGE, 'ak-1', []));
        $this->wholeAnswer();
        $this->assertFalse(Hilos::$sr->hasPageDeliveryFailure('ak-1'));
        $this->assertSame($logged, file_get_contents($this->logFile));
        $this->flushFacts(SourceChange::KIND_RT, twoRows: false);
        $this->assertSame([SignalTypeConstants::TABLE_VIEWPORT_DELTA, SignalTypeConstants::TABLE_PROGRESS], $this->drainQueue());
    }

    /** @return iterable<string, array{string}> Both ways to remove the subscription */
    public static function subscriptionEnds(): iterable
    {
        yield 'leave' => [SignalTypeConstants::PAGE_UNSUBSCRIBE];
        yield 'close' => [SignalTypeConstants::CONNECTION_CLOSE];
    }

    /**
     * The worker removes debt together with the subscription on either teardown path.
     *
     * @param string $type Teardown door to exercise
     */
    #[DataProvider('subscriptionEnds')]
    public function testLeavingOrClosingRemovesTheDebt(string $type): void
    {
        $manager = $this->managerWithTable();
        $this->servedPage($manager, ResendWorkerTestManager::FIRST)->failureCode = 404;
        $this->handle($manager, ResendWorkerTestManager::FIRST, SignalTypeConstants::PAGE_SUBSCRIBE);
        $this->assertError(404);
        $this->handle($manager, ResendWorkerTestManager::FIRST, $type);
        $this->assertNull(Hilos::$sr->pageSubscription('ak-1'));
        $this->assertFalse(Hilos::$sr->hasPageDeliveryFailure('ak-1'));
        $this->flushFacts(SourceChange::KIND_DB);
        $this->assertSame([], $this->drainQueue());
    }

    /** @return ResendWorkerTestManager Worker with a real page resender and an in-memory viewport table */
    private function managerWithTable(): ResendWorkerTestManager
    {
        Hilos::$browser->withTable = true;
        Hilos::$table = new ResendWorkerTestTableContext();
        Hilos::$table->configure();
        $manager = new ResendWorkerTestManager();
        Hilos::$browser->bindPageResender($manager);

        return $manager;
    }

    /**
     * @param string $kind DB or RT fact kind
     * @param bool $twoRows Whether the flush carries a second row fact
     */
    private function flushFacts(string $kind, bool $twoRows = true): void
    {
        foreach ($twoRows ? ['a', 'b'] : ['a'] as $id) {
            Hilos::$browser->record($kind === SourceChange::KIND_DB
                ? SourceChange::dbUpdated(ResendWorkerTestTable::SOURCE, $id, ['label' => 'changed'])
                : SourceChange::rtUpdated(ResendWorkerTestTable::SOURCE, $id, ['label' => 'changed']));
        }
        if ($twoRows) {
            Hilos::$browser->recordSourceStaleness(ResendWorkerTestTable::SOURCE, ['a', 'b']);
        }
        Hilos::$browser->flushToSignalRouter();
    }

    /** @param int $httpCode Expected status of the sole error frame */
    private function assertError(int $httpCode): void
    {
        $signal = Hilos::$sr->getNextQueuedSignal();
        $this->assertSame(SignalConstants::SUBSCRIPTION_PAGE_ERROR, $signal?->signalName->getName());
        $this->assertInstanceOf(PageSubscriptionErrorSignalData::class, $signal->data->data);
        $this->assertSame($httpCode, $signal->data->data->httpCode);
        $this->assertSame([], $this->drainQueue());
    }

    /**
     * Asserts the queue holds one subscription error and nothing after it.
     *
     * @param int $httpCode Expected status
     * @param string $errorCode Expected machine-readable code
     * @param ?string $transitionMessage Expected deletion line, or null when the frame has none
     * @return PageSubscriptionErrorSignalData The refusal the connection was sent
     */
    private function assertSubscriptionError(
        int $httpCode,
        string $errorCode,
        ?string $transitionMessage,
    ): PageSubscriptionErrorSignalData {
        $signal = Hilos::$sr->getNextQueuedSignal();
        $this->assertSame(SignalConstants::SUBSCRIPTION_PAGE_ERROR, $signal?->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertSame('ak-1', $signal->data->targetAcceptKey);
        $this->assertInstanceOf(PageSubscriptionErrorSignalData::class, $signal->data->data);
        $error = $signal->data->data;
        $text = PageErrorMessages::forStatus($httpCode);
        $this->assertSame($httpCode, $error->httpCode);
        $this->assertSame($errorCode, $error->errorCode);
        $this->assertSame($text->title, $error->title);
        $this->assertSame($text->message, $error->message);
        $this->assertSame($transitionMessage, $error->transitionMessage);
        $payload = $error->toArray();
        if ($transitionMessage === null) {
            $this->assertArrayNotHasKey(PageSubscriptionErrorSignalData::transitionMessage, $payload);
        } else {
            $this->assertSame($transitionMessage, $payload[PageSubscriptionErrorSignalData::transitionMessage]);
        }
        $this->assertSame([], $this->drainQueue());

        return $error;
    }

    /** @return PagePayload The sole complete response, including the catalog's four identity fields */
    private function wholeAnswer(): PagePayload
    {
        $signal = Hilos::$sr->getNextQueuedSignal();
        $this->assertSame(SignalTypeConstants::PAGE_RESPONSE, $signal?->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertSame('ak-1', $signal->data->targetAcceptKey);
        $this->assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
        $this->assertSame(ResendWorkerTestPage::PAGE, $signal->data->data->pageKey);
        $payload = $signal->data->data->payload;
        $this->assertSame('mine', $payload->data['own']);
        $this->assertNotEmpty($payload->data[PageCatalogConstants::WIRE_PAGE_LABEL]);
        $this->assertArrayHasKey(PageCatalogConstants::WIRE_PAGE_LEAD, $payload->data);
        $this->assertNotEmpty($payload->data[PageCatalogConstants::WIRE_PAGE_BREADCRUMB]);
        $this->assertArrayHasKey(PageCatalogConstants::WIRE_PAGE_CHILDREN, $payload->data);
        $this->assertSame([], $this->drainQueue());

        return $payload;
    }

    /**
     * Builds a manager whose connection 'ak-1' holds the fixture page, served by the named instance.
     *
     * @param string $index Index of the agent instance the subscribe reaches
     * @param array<string, string> $params Route params the subscription keeps
     * @return ResendWorkerTestManager Manager with a live subscription
     */
    private function subscribedManager(string $index, array $params = []): ResendWorkerTestManager
    {
        $manager = new ResendWorkerTestManager();
        $this->handle($manager, $index, SignalTypeConstants::PAGE_SUBSCRIBE, $params);

        return $manager;
    }

    /**
     * Returns the fixture page one agent instance of the manager serves.
     *
     * @param ResendWorkerTestManager $manager Manager under test
     * @param string $index Index of the agent instance
     * @return ResendWorkerTestPage Fixture page
     * @throws PageNotFoundException When the fixture factory does not know the page
     */
    private function servedPage(ResendWorkerTestManager $manager, string $index): ResendWorkerTestPage
    {
        $page = $manager->page($index);
        $this->assertInstanceOf(ResendWorkerTestPage::class, $page);

        return $page;
    }

    /**
     * Delivers one page frame for 'ak-1' to the named agent instance of the manager under test.
     *
     * @param WorkerManager $manager Manager under test
     * @param string $index Index of the agent instance the frame is addressed to
     * @param string $type Signal type of the frame
     * @param array<string, string> $params Route params of a subscribe frame
     */
    private function handle(WorkerManager $manager, string $index, string $type, array $params = []): void
    {
        $signal = new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType($type),
            new SignalName(ResendWorkerTestPage::PAGE),
            match ($type) {
                SignalTypeConstants::PAGE_UPDATE_SUBSCRIPTION => new WebSocketPageUpdateSubscriptionSignalDTO('ak-1', ResendWorkerTestPage::PAGE),
                SignalTypeConstants::PAGE_UNSUBSCRIBE => new WebSocketPageUnsubscribeSignalDTO('ak-1', ResendWorkerTestPage::PAGE),
                SignalTypeConstants::CONNECTION_CLOSE => new WebSocketCloseSignalDTO('ak-1'),
                default => new WebSocketPageSubscribeSignalDTO('ak-1', ResendWorkerTestPage::PAGE, $params),
            },
        );
        $handle = Closure::bind(
            static function (WorkerManager $manager, string $agentId, SignalDTO $signal): void {
                $manager->handleAgentMessage(new DaemonAgentMessageDTO($agentId, $signal));
            },
            null,
            WorkerManager::class,
        );

        $handle($manager, ResendWorkerTestAgent::AGENT_TYPE . ':' . $index, $signal);
        if ($type === SignalTypeConstants::PAGE_SUBSCRIBE) {
            // Every real subscribe first reports replica freshness, independently of its page verdict.
            $this->assertSame(SignalTypeConstants::RT_STALENESS, Hilos::$sr->getNextQueuedSignal()?->signalName->getName());
        }
    }

    /**
     * Empties the router queue and reports what each signal was named.
     *
     * @return list<string> Signal names, in the order they were queued
     */
    private function drainQueue(): array
    {
        $names = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $names[] = $signal->signalName->getName();
        }

        return $names;
    }
}

/**
 * Records a page release beside the names of the frames queued around it.
 */
final class ResendWorkerRecordingRouter extends SignalRouter
{
    /** Mark written when the page releases a connection, ahead of any frame that follows. */
    public const string RELEASED = 'release';

    /** @var list<string> Release mark and signal names, oldest first */
    public array $marks = [];

    /** Notes that the page released the connection. */
    public function markReleased(): void
    {
        $this->marks[] = self::RELEASED;
    }

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
        $this->marks[] = $signalName->getName();
        parent::queueSignal($signalSource, $signalType, $signalName, $signalData);
    }
}

final class ResendWorkerTestManager extends WorkerManager
{
    /** Index of the instance a subscribe reaches first. */
    public const string FIRST = '7';

    /** Index of the instance a re-decision may hand the subscription to. */
    public const string SECOND = '8';

    public ResendWorkerRecordingRouter $signals;

    /** @var array<string, ResendWorkerTestPageFactory> Page factory of each agent instance, by index */
    private array $pageFactories = [];

    /** @var list<PageSignalRouter> Routers with pending identity verdicts */
    private array $routers = [];

    public function __construct()
    {
        parent::__construct(1);
        foreach ([self::FIRST, self::SECOND] as $index) {
            $agent = new ResendWorkerTestAgent($index);
            $this->agentManager->addAgent($agent->getId(), $agent);
            $this->pageFactories[$index] = new ResendWorkerTestPageFactory($agent);
        }
    }

    /**
     * Returns the fixture page of one agent instance.
     *
     * @param string $index Index of the agent instance
     * @return AbstractPage Fixture page that instance serves
     * @throws PageNotFoundException When the fixture factory does not know the page
     */
    public function page(string $index): AbstractPage
    {
        return $this->pageFactories[$index]->getPage(ResendWorkerTestPage::PAGE);
    }

    /** Runs the worker's pending-page release without a timer or a live process. */
    public function releasePendingPageFrames(): void
    {
        foreach ($this->routers as $router) {
            $router->releasePendingFrames();
        }
    }

    /**
     * Takes one agent instance out of this worker, as a stop does.
     *
     * @param string $index Index of the agent instance
     */
    public function removeAgent(string $index): void
    {
        $this->agentManager->removeAgent(ResendWorkerTestAgent::AGENT_TYPE . ':' . $index);
    }

    /**
     * @return SignalRouter Plain router, enough for subscription bookkeeping
     */
    protected function createSignalRouter(): SignalRouter
    {
        $this->signals = new ResendWorkerRecordingRouter();

        return $this->signals;
    }

    /**
     * @return AgentManager Manager the constructor fills by hand
     */
    protected function createAgentManager(): AgentManager
    {
        return new ResendWorkerTestAgentManager();
    }

    /**
     * @param AgentInterface $agent Agent receiving page signals
     * @return PageSignalRouter Router over that agent's own page factory
     */
    protected function createPageSignalRouter(AgentInterface $agent): PageSignalRouter
    {
        $router = new PageSignalRouter($this->pageFactories[(string)$agent->getIndex()], new ActionRouteConfig());
        $this->routers[] = $router;

        return $router;
    }
}

final class ResendWorkerTestAgentManager extends AgentManager
{
    /**
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index
     * @return AgentInterface Fixture agent
     */
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        return new ResendWorkerTestAgent((string)$agentIndex);
    }
}

final class ResendWorkerTestAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_resend_page';

    /**
     * @param string $index Instance index
     */
    public function __construct(string $index)
    {
        $this->agentIndex = $index;
    }

    public function onStop(): void
    {
    }
}

/**
 * Page factory fixture holding the one page of one agent instance.
 *
 * @extends AbstractPageFactory<ResendWorkerTestAgent>
 */
final class ResendWorkerTestPageFactory extends AbstractPageFactory
{
    /**
     * @param string $pageName Page name
     * @return AbstractPage Fixture page
     * @throws PageNotFoundException When an unexpected page is requested
     */
    protected function createPage(string $pageName): AbstractPage
    {
        if ($pageName !== ResendWorkerTestPage::PAGE) {
            throw new PageNotFoundException($pageName);
        }

        return new ResendWorkerTestPage($this->agent);
    }

    /**
     * @param string $pageName Page name
     * @return bool Whether this is the fixture page
     */
    public function hasPage(string $pageName): bool
    {
        return $pageName === ResendWorkerTestPage::PAGE;
    }
}

/**
 * Page recording every answer it gives and the frame of execution it gave it under.
 */
final class ResendWorkerTestPage extends AbstractPage
{
    public const string PAGE = HilosPageConstants::HILOS_LOGS_KEYS;

    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::AUTHENTICATED;

    public ?int $failureCode = null;

    public bool $failAfterResponse = false;

    /** @var list<bool> Debt at each entry, distinguishing real subscribes from probes */
    public array $debtsAtEntry = [];

    /** How many times this page answered a subscribe frame. */
    public int $subscribeCount = 0;

    /** @var ?array{0: ?string, 1: ?string} Agent and connection of the frame the last answer ran under */
    public ?array $lastFrame = null;

    /** How many times a refusal released this page. */
    public int $unsubscribeCount = 0;

    public bool $throwOnUnsubscribe = false;

    /** @var ?array{0: ?string, 1: ?string} Agent and connection of the frame the release ran under */
    public ?array $releaseFrame = null;

    /**
     * Updates that rebuild their answer enter onSubscribe directly, without the subscribe dispatcher.
     *
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Merged route parameters
     * @throws PageSubscriptionException When the page's own build refuses the resource
     * @throws InvalidArgumentException When the response or facet-count signal cannot be named
     * @throws HilosException When the page or its browser snapshot fails
     */
    public function onUpdateSubscription(string $acceptKey, PageRouteParams $params): void
    {
        $this->onSubscribe($acceptKey, $params);
    }

    /**
     * Records the frame the release ran under, and fails the cleanup when the case asks.
     *
     * @param string $acceptKey WebSocket accept key
     * @throws RuntimeException When the case asks the release to fail
     */
    public function onUnsubscribe(string $acceptKey): void
    {
        $this->unsubscribeCount++;
        $this->releaseFrame = [ExecutionContext::currentAgentId(), ExecutionContext::currentAcceptKey()];
        if (Hilos::$sr instanceof ResendWorkerRecordingRouter) {
            Hilos::$sr->markReleased();
        }
        if ($this->throwOnUnsubscribe) {
            throw new RuntimeException('release failed');
        }
    }

    /**
     * @param string $acceptKey WebSocket accept key whose debt is recorded
     * @param PageRouteParams $params Route params (unused)
     */
    protected function onSubscribeBeforeResponse(string $acceptKey, PageRouteParams $params): void
    {
        $this->subscribeCount++;
        $this->lastFrame = [ExecutionContext::currentAgentId(), ExecutionContext::currentAcceptKey()];
        $this->debtsAtEntry[] = Hilos::$sr->hasPageDeliveryFailure($acceptKey);
    }

    /**
     * @param string $acceptKey Subscribing connection (unused)
     * @param PageRouteParams $params Route parameters (unused)
     * @return ?PagePayload Own page data, when the fixture is healthy
     * @throws LogicException When the fixture represents an unexpected build failure
     * @throws PageSubscriptionException When the fixture represents a refused resource
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): ?PagePayload
    {
        if ($this->failureCode === 500) {
            throw new LogicException('Fixture payload failed');
        }
        if ($this->failureCode !== null) {
            throw match ($this->failureCode) {
                404 => new PageResourceNotFoundException(),
                403 => new PageForbiddenException(),
                default => new PageServiceUnavailableException(),
            };
        }

        return new PagePayload(data: ['own' => 'mine']);
    }

    /**
     * @param string $acceptKey Subscribing connection (unused)
     * @param PageRouteParams $params Route parameters (unused)
     * @throws LogicException When the fixture fails after its complete answer left
     */
    protected function onSubscribeAfterResponse(string $acceptKey, PageRouteParams $params): void
    {
        if ($this->failAfterResponse) {
            throw new LogicException('Fixture after-response hook failed');
        }
    }
}

final class ResendWorkerTestBrowser extends BrowserContext
{
    public bool $withTable = false;

    public ConnectionIdentity $identity;

    public ?PageSubscriptionException $guardRefusal = null;

    /** @var ?array<string, string> Params the latest guard check was handed */
    public ?array $guardParams = null;

    /** Starts with an identified subscriber; tests may park or revoke that identity. */
    public function __construct()
    {
        parent::__construct();
        $this->identity = ConnectionIdentity::resolved(5);
    }

    /**
     * Records the params a refusal hands the guards, and plants a guard refusal when the case asks.
     *
     * @param string $page Page name from the subscription mirror
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Route params the caller already held
     * @throws PageSubscriptionException When the case plants a guard refusal, or a param or a guard rejects the subscription
     */
    public function assertSubscriptionAccess(string $page, string $acceptKey, PageRouteParams $params): void
    {
        $this->guardParams = $params->toArray();
        if ($this->guardRefusal !== null) {
            throw $this->guardRefusal;
        }

        parent::assertSubscriptionAccess($page, $acceptKey, $params);
    }

    /**
     * @param string $acceptKey Subscriber accept key (unused in the fixture)
     * @return ConnectionIdentity Identity currently known by the fixture
     */
    protected function resolveConnectionIdentity(string $acceptKey): ConnectionIdentity
    {
        return $this->identity;
    }

    /**
     * @param string $page Page name from the subscription mirror (unused)
     * @return ?BrowserPageConfig Source delivery metadata when the table is mounted
     * @throws PageInternalErrorException When a page or source declaration is malformed
     */
    protected function resolveBrowserPageConfig(string $page): ?BrowserPageConfig
    {
        return $this->withTable
            ? BrowserPageConfig::fromArray([BrowserConfigKey::SIGNAL => 'resend_worker_changes'])
            : null;
    }

    /**
     * @param string $page Page name from the subscription mirror (unused)
     * @return BrowserPageBindings The viewport table when mounted, otherwise empty
     */
    protected function resolveBrowserPageBindings(string $page): BrowserPageBindings
    {
        return $this->withTable
            ? BrowserPageBindings::fromArray([ResendWorkerTestTable::TABLE => []])
            : BrowserPageBindings::empty();
    }

    /**
     * Exposes the same declaration to source-interest discovery and to the live fan-out.
     *
     * @param string $browserKey Requested table key
     * @return ?BrowserSourceConfig The fixture table's declaration
     */
    protected function resolveBrowserOnlyConfig(string $browserKey): ?BrowserSourceConfig
    {
        return $browserKey === ResendWorkerTestTable::TABLE
            ? BrowserSourceConfig::fromArray(ResendWorkerTestTable::BROWSER)
            : null;
    }
}

/** One in-memory table, with real DB and RT fan-out declarations but no database dependency. */
final class ResendWorkerTestTableContext extends TableContext
{
    /** Mounts the source-observing viewport table. */
    public function configure(): void
    {
        $this->register(ResendWorkerTestTable::TABLE, new ResendWorkerTestTable());
    }
}

final class ResendWorkerTestTable extends TableDefinition implements SelfSnapshotTable
{
    public const string TABLE = 'resendWorkerRows';
    public const string SOURCE = 'resendWorkerSource';
    public const array BROWSER = [
        BrowserTableConfigKey::ROWS => [
            [
                BrowserTableFieldKey::SOURCE => [BrowserSourceKey::TYPE => BrowserSourceType::DB, BrowserSourceKey::KEY => self::SOURCE],
                BrowserTableFieldKey::ROW_KEY => 'id',
                BrowserTableFieldKey::FIELDS => ['id', 'label'],
            ],
            [
                BrowserTableFieldKey::SOURCE => [BrowserSourceKey::TYPE => BrowserSourceType::RT, BrowserSourceKey::KEY => self::SOURCE],
                BrowserTableFieldKey::ROW_KEY => 'id',
                BrowserTableFieldKey::FIELDS => ['id', 'label'],
            ],
        ],
    ];

    /**
     * @param SourceChange $change Source fact to map
     * @return ?TableRowMutationDTO Row update for this fixture's source
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        return $change->sourceKey === self::SOURCE
            ? $this->mutation($change->mutationType, $change->sourceId, new GenericTableRow(['id' => $change->sourceId] + $change->row))
            : null;
    }

    /**
     * @param SourceChange $change Source fact (unused)
     * @return ?TableProgressDTO A live bar, making a stray partial delivery observable
     * @throws InvalidArgumentException When the fixture bar violates its scope
     */
    public function buildProgressForSourceEvent(SourceChange $change): ?TableProgressDTO
    {
        return new TableProgressDTO(TableProgressScope::Table, 'work', null, 1, 2);
    }

    /**
     * @param AbstractTableRow $row Window row
     * @return array<string, mixed> Browser envelope
     * @throws TableRowKeyMissingException When the fixture row has no key
     */
    public function browserRow(AbstractTableRow $row): array
    {
        return [PagePayload::rowKey => $row->requireRowKey(), 'sources' => [self::TABLE => $row->toArray()]];
    }

    /**
     * @param TableQueryDTO $query Requested window
     * @return TableSnapshotDTO Two rows filtered to the requested window
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        return $this->filterInMemory([['id' => 'a', 'label' => 'Alpha'], ['id' => 'b', 'label' => 'Beta']], $query);
    }
}
