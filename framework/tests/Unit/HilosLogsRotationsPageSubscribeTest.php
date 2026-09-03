<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Hilos;
use Hilos\Log\ClusterLogIndexMirror;
use Hilos\Log\ClusterLogNodeSlot;
use Hilos\Log\DTO\ClusterLogIndexPortionSignalData;
use Hilos\Log\DTO\LogsTakeoutConfirmSignalData;
use Hilos\Log\DTO\LogsTakeoutUndoSignalData;
use Hilos\Log\LogBatchSummary;
use Hilos\Log\NodeLogIndex;
use Hilos\Pages\Logs\AbstractHilosLogsRotationsPage;
use Hilos\Pages\Logs\DTO\HilosLogsRotationsSignalData;
use Hilos\Pages\Logs\DTO\LogsTakeoutConfirmActionDTO;
use Hilos\Pages\Logs\DTO\LogsTakeoutUndoActionDTO;
use Hilos\Runtime\State\Item\HilosClusterNode as StateHilosClusterNode;
use Hilos\Tables\Logs\HilosLogRotationsTable;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the header the rotation-history page answers a subscription with (HIL-387).
 *
 * The batches ride the ordinary windowed table and are not tested here; what is, is everything the
 * screen needs BEFORE a row can mean anything. Which of the four empty states it is in, because
 * "nobody has reported" and "no node can read its store" are different screens and neither of them
 * is a zero. Which nodes exist, because an installation with no node id at all must lose its node
 * column rather than offer a filter with one entry in it. And that a viewer who leaves is dropped
 * from the page's own set AND from the section's count - a viewer left counted keeps the
 * aggregator sending frames for a page nobody has open.
 *
 * The screen's one action lives here too (HIL-483). The page owns no batch directory - one node's
 * owner does - so what is judged is the handover: it refuses a node an operator can still do
 * something about, forwards everything else with the whole request plus whom to answer and who
 * asked, and then stops owing an ack of its own.
 */
final class HilosLogsRotationsPageSubscribeTest extends TestCase
{
    private const string ACCEPT_KEY = 'ak-rotations-1';

    /** A second connection, for the case where one admin's arrival must not silence another's push. */
    private const string SECOND_ACCEPT_KEY = 'ak-rotations-2';

    /** Comfortably past the ~100ms throttle {@see AbstractHilosLogsRotationsPage::onAgentTick()} keeps. */
    private const int PAST_THE_TICK_THROTTLE_MICROSECONDS = 150_000;

    /** Any fixed instant, so a timestamp in a fixture means something to read. */
    private const int T0 = 1_800_000_000;

    /** Node on the other end of the cluster, the one the confirmations are addressed to. */
    private const string PEER = 'node-2';

    /** Request id of the tracked dispatch the confirmation cases run under. */
    private const string REQUEST_ID = 'req-takeout-1';

    /** The batch a confirmation names; the one the fixture slots hold. */
    private const int CONFIRMED_BATCH_AT = self::T0 - 3_600;

    /** Weight of each fixture node's one batch; each move of it moves the rows fingerprint. */
    private int $batchBytes = 0;

    /** How many nodes the fixture picture holds; each one more moves the header fingerprint. */
    private int $reportedNodes = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Hilos::$sr = new SignalRouter();
        // Binds the base facade, whose page catalog answers for the framework admin pages. The
        // base creates no browser context, so this clears the browser in the same call - which is
        // also the connection carrying no user that the confirmation cases forward under.
        Hilos::initBrowser();
        Hilos::$rt = new LogsRotationsPageTestRtContext();
        Hilos::$rt->mountFeatureRuntime([]);
        RtTruthSourceRegistry::registerDaemon(StateHilosClusterNode::RT_COLLECTION);

        $this->emptyTheMirror();
        $this->growTheClusterPicture();
    }

    protected function tearDown(): void
    {
        // The subscriber set is static and outlives the test; a key left in it would push at
        // nobody for the rest of the suite. The mirror is static for the same reason.
        AbstractHilosLogsRotationsPage::removeSubscriber(self::ACCEPT_KEY);
        AbstractHilosLogsRotationsPage::removeSubscriber(self::SECOND_ACCEPT_KEY);
        $this->emptyTheMirror();

        foreach ([
            EnvConstants::LOG_ARCHIVE_RETENTION_KEEP_BATCHES,
            EnvConstants::LOG_ARCHIVE_RETENTION_MAX_AGE_SECONDS,
            EnvConstants::LOG_ROTATION_MAX_AGE_SECONDS,
            EnvConstants::LOG_ROTATION_MAX_LIVE_SIZE_BYTES,
            EnvConstants::LOG_ROTATION_CRON,
        ] as $key) {
            putenv($key->name);
        }

        RtTruthSourceRegistry::unregisterDaemon(StateHilosClusterNode::RT_COLLECTION);
        Hilos::$rt = null;
        Hilos::$sr = null;
        Hilos::$browser = null;

        parent::tearDown();
    }

    /**
     * Two answers, in this order and no other: the page's own header first, because the frame
     * behind it means the subscription is answered in full.
     */
    public function testTheSubscriptionAnswersWithTheHeaderAndThenTheFrame(): void
    {
        $page = new LogsRotationsPageSubscribeTestPage(new LogsRotationsPageSubscribeTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $this->assertSame(
            [
                HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS_ROTATIONS,
                SignalTypeConstants::PAGE_RESPONSE,
            ],
            $this->queuedSignalNames(),
        );
    }

    /**
     * The rules on the screen are the ones that REALLY act: the resolver has already fallen back
     * to the environment wherever a setting could not be used, so the header shows what rotation
     * will do rather than what the settings table says it should.
     */
    public function testTheHeaderCarriesTheRulesInForce(): void
    {
        putenv(EnvConstants::LOG_ROTATION_CRON->name . '=0 4 * * *');
        putenv(EnvConstants::LOG_ROTATION_MAX_AGE_SECONDS->name . '=3600');
        putenv(EnvConstants::LOG_ROTATION_MAX_LIVE_SIZE_BYTES->name . '=1048576');
        putenv(EnvConstants::LOG_ARCHIVE_RETENTION_KEEP_BATCHES->name . '=7');
        putenv(EnvConstants::LOG_ARCHIVE_RETENTION_MAX_AGE_SECONDS->name . '=604800');
        $page = new LogsRotationsPageSubscribeTestPage(new LogsRotationsPageSubscribeTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $header = $this->header();
        $this->assertSame('0 4 * * *', $header->rotationCron);
        $this->assertSame(3_600, $header->rotationMaxAgeSeconds);
        $this->assertSame(1_048_576, $header->rotationMaxLiveSizeBytes);
        $this->assertSame(7, $header->retentionKeepBatches);
        $this->assertSame(604_800, $header->retentionMaxAgeSeconds);
    }

    /**
     * Opening the screen before the aggregator has answered gives the third state and not zeros:
     * we have not heard, which is a different screen from "we heard and nothing can be read".
     */
    public function testAnEmptyMirrorAnswersWithTheThirdState(): void
    {
        $this->emptyTheMirror();
        $page = new LogsRotationsPageSubscribeTestPage(new LogsRotationsPageSubscribeTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $this->assertNull($this->header()->available);
    }

    /**
     * A picture in which no node can read its store is the other screen, and it is a fault to show
     * rather than a wait to sit through.
     */
    public function testAPictureWhereNoNodeCanReadItsStoreIsUnavailable(): void
    {
        $this->picture($this->slot('node-1', available: false), $this->slot('node-2', available: false));
        $page = new LogsRotationsPageSubscribeTestPage(new LogsRotationsPageSubscribeTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $this->assertFalse($this->header()->available);
    }

    /**
     * One node that can be read is enough to have a history to draw, even while a neighbour
     * cannot: the rows of the readable node exist and the screen must not hide them.
     */
    public function testOneReadableNodeIsEnoughToHaveAHistory(): void
    {
        $this->picture($this->slot('node-1', available: false), $this->slot('node-2', available: true));
        $page = new LogsRotationsPageSubscribeTestPage(new LogsRotationsPageSubscribeTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $this->assertTrue($this->header()->available);
    }

    /**
     * An installation with no node id at all reports under no name, and the empty list is how the
     * screen is told to drop its node column and node filter rather than offer a choice of one.
     */
    public function testASingleNodeInstallationNamesNoNodes(): void
    {
        $this->picture($this->slot(null, available: true));
        $page = new LogsRotationsPageSubscribeTestPage(new LogsRotationsPageSubscribeTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $header = $this->header();
        $this->assertSame([], $header->nodes);
        $this->assertTrue($header->available);
    }

    public function testAClusterNamesEveryNodeThatHasReported(): void
    {
        $this->picture($this->slot('node-2', available: true), $this->slot('node-1', available: true));
        $page = new LogsRotationsPageSubscribeTestPage(new LogsRotationsPageSubscribeTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        // The picture holds its slots ordered by node, so the filter offers them the same way
        // however the frames happened to arrive.
        $this->assertSame(['node-1', 'node-2'], $this->header()->nodes);
    }

    /**
     * Subscribing counts the connection as a viewer of the SECTION, which is the only thing that
     * makes the aggregator send anything; leaving takes it off both lists at once.
     */
    public function testLeavingDropsTheViewerFromThePageAndFromTheSection(): void
    {
        $page = new LogsRotationsPageSubscribeTestPage(new LogsRotationsPageSubscribeTestAgent());
        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));
        $this->queuedSignalNames();

        $this->assertSame([self::ACCEPT_KEY], ClusterLogIndexMirror::viewerKeys());
        $this->assertSame([self::ACCEPT_KEY], $this->tickRecipients());

        $page->onUnsubscribe(self::ACCEPT_KEY);

        $this->assertSame(0, ClusterLogIndexMirror::viewerCount());
        $this->assertSame([], $this->tickRecipients());
    }

    /**
     * A second admin opening the page must not cancel the push the first one is still owed.
     *
     * The two fingerprints are one pair for the whole process and say what the last BROADCAST
     * carried. A subscribe that moved them to "current" would mark a change nobody had been sent
     * yet as already delivered, and the admins already on the page would go on looking at the
     * previous picture until the next change happened to come along.
     */
    public function testASecondSubscriberDoesNotSwallowThePushTheFirstIsOwed(): void
    {
        $page = new LogsRotationsPageSubscribeTestPage(new LogsRotationsPageSubscribeTestAgent());
        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));
        $this->queuedSignalNames();

        // One tick with nothing changed, so the fingerprints hold THIS picture whatever an
        // earlier case in this process left them holding: they are static, and the point of the
        // case is what the NEXT change does.
        $this->tickPastTheThrottle();
        $this->queuedSignalNames();

        // The picture moves, and the throttled tick has not run yet when the second admin arrives.
        $this->growTheClusterPicture();
        $page->onSubscribe(self::SECOND_ACCEPT_KEY, new PageRouteParams([]));
        $this->queuedSignalNames();

        $this->tickPastTheThrottle();

        $keys = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
            $keys[] = (string) $signal->data->targetAcceptKey;
        }
        sort($keys);
        $this->assertSame([self::ACCEPT_KEY, self::SECOND_ACCEPT_KEY], $keys);
    }

    /**
     * A retention verdict that moved is enough to re-serve the window, and it is the one change
     * that arrives with nothing else moving (HIL-871): the same node, the same batch, the same
     * weight and the same marker, with a different badge over it. Before the verdict became part
     * of the slot the fingerprint could not see it at all, and the column waited for whatever
     * changed next.
     */
    public function testAVerdictThatMovedOnItsOwnReservesTheWindow(): void
    {
        $browser = new LogsRotationsPageSubscribeTestBrowser();
        Hilos::$browser = $browser;
        new LogsRotationsPageSubscribeTestPage(new LogsRotationsPageSubscribeTestAgent())
            ->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));
        $this->queuedSignalNames();
        Hilos::$sr?->setTableViewport(
            self::ACCEPT_KEY,
            new TableViewportSubscription(tableKey: HilosLogRotationsTable::TABLE),
        );

        // One tick to settle the fingerprints on the current picture, whatever an earlier case in
        // this process left them holding: they are static, and the point is what the NEXT one does.
        $this->tickPastTheThrottle();
        $browser->windows = [];
        $this->tickPastTheThrottle();
        $this->assertSame([], $browser->windows, 'A quiet picture re-serves nothing');

        // The same picture again, batch for batch, with that one batch newly recommended.
        $this->picture($this->slot('node-1', available: true, due: [self::CONFIRMED_BATCH_AT]));
        $this->tickPastTheThrottle();

        $this->assertSame([HilosLogRotationsTable::TABLE], $browser->windows);
    }

    /**
     * The whole request reaches the owner, and the page stops owing an answer: the node that
     * writes the marker is the one that can say what instant it wrote, so it acks the browser
     * itself.
     */
    public function testAConfirmationForALiveNodeReachesItsOwnerWholeAndLeavesThePageOwingNothing(): void
    {
        $this->publishNode(self::PEER, online: true);
        $page = $this->dispatchingPage();

        $reply = $page->onAction(
            self::ACCEPT_KEY,
            HilosSignalConstants::LOGS_TAKEOUT_CONFIRM,
            $this->confirmation(self::PEER),
        );

        $this->assertNull($reply, 'The page answers with nothing: the owner answers instead');
        $this->assertTrue($page->actionReplyDeferred(), 'Having handed the request over, the page must not also ack');
        $this->assertEquals(
            new LogsTakeoutConfirmSignalData(
                nodeId: self::PEER,
                batchTimestamp: self::CONFIRMED_BATCH_AT,
                acceptKey: self::ACCEPT_KEY,
                action: HilosSignalConstants::LOGS_TAKEOUT_CONFIRM,
                requestId: self::REQUEST_ID,
                // Nobody is signed in on this connection, and a fact nobody can be named for is
                // recorded without a name rather than refused.
                userId: null,
            ),
            $this->sentToOwner(),
        );
    }

    public function testAConfirmationWithNoNodeGoesToThisNodesOwnerWithoutConsultingTheRoster(): void
    {
        // The roster is deliberately empty: a standalone install publishes itself under an EMPTY
        // id, so a page that looked an empty id up would be asking whether this machine exists.
        $page = $this->dispatchingPage();

        $page->onAction(self::ACCEPT_KEY, HilosSignalConstants::LOGS_TAKEOUT_CONFIRM, $this->confirmation(''));

        $this->assertSame('', $this->sentToOwner()->nodeId);
        $this->assertTrue($page->actionReplyDeferred());
    }

    public function testAConfirmationNamingANodeThisClusterDoesNotHaveIsRefusedOnTheSpot(): void
    {
        $this->publishNode('node-1', online: true);
        $page = $this->dispatchingPage();

        try {
            $page->onAction(
                self::ACCEPT_KEY,
                HilosSignalConstants::LOGS_TAKEOUT_CONFIRM,
                $this->confirmation(self::PEER),
            );
            $this->fail('Expected TableActionException');
        } catch (TableActionException $e) {
            $this->assertSame('Unknown cluster node: ' . self::PEER, $e->getMessage());
        }

        $this->assertNull(Hilos::$sr?->getNextQueuedSignal(), 'A refused confirmation travels nowhere');
    }

    /**
     * The two refusals mean opposite things to the person reading, and a node the master last saw
     * offline is the one whose archive they were about to carry off by hand.
     */
    public function testAConfirmationNamingANodeTheMasterSawFallOverSaysThatInsteadOfUnknown(): void
    {
        $this->publishNode(self::PEER, online: false);
        $page = $this->dispatchingPage();

        try {
            $page->onAction(
                self::ACCEPT_KEY,
                HilosSignalConstants::LOGS_TAKEOUT_CONFIRM,
                $this->confirmation(self::PEER),
            );
            $this->fail('Expected TableActionException');
        } catch (TableActionException $e) {
            $this->assertSame('Cluster node ' . self::PEER . ' is offline', $e->getMessage());
        }

        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    /**
     * An untracked confirmation has no ack to correlate, so there is nothing to defer - and
     * deferring it anyway would be a promise the owner cannot keep.
     */
    public function testAnUntrackedConfirmationIsForwardedWithNoRequestIdAndNothingIsDeferred(): void
    {
        $this->publishNode(self::PEER, online: true);
        $page = new LogsRotationsPageSubscribeTestPage(new LogsRotationsPageSubscribeTestAgent());
        $page->beginActionDispatch();

        $page->onAction(
            self::ACCEPT_KEY,
            HilosSignalConstants::LOGS_TAKEOUT_CONFIRM,
            $this->confirmation(self::PEER),
        );

        $this->assertNull($this->sentToOwner()->requestId);
        $this->assertFalse($page->actionReplyDeferred());
    }

    /**
     * The withdrawal travels the same road as the confirmation and for the same reason: the marker
     * lies in a directory this page worker does not own. One field fewer rides along — nothing is
     * written, so there is nobody to record as having written it.
     */
    public function testAWithdrawalForALiveNodeReachesItsOwnerWholeAndLeavesThePageOwingNothing(): void
    {
        $this->publishNode(self::PEER, online: true);
        $page = $this->dispatchingPage();

        $reply = $page->onAction(
            self::ACCEPT_KEY,
            HilosSignalConstants::LOGS_TAKEOUT_UNDO,
            $this->withdrawal(self::PEER),
        );

        $this->assertNull($reply, 'The page answers with nothing: the owner answers instead');
        $this->assertTrue($page->actionReplyDeferred(), 'Having handed the request over, the page must not also ack');
        $this->assertEquals(
            new LogsTakeoutUndoSignalData(
                nodeId: self::PEER,
                batchTimestamp: self::CONFIRMED_BATCH_AT,
                acceptKey: self::ACCEPT_KEY,
                action: HilosSignalConstants::LOGS_TAKEOUT_UNDO,
                requestId: self::REQUEST_ID,
            ),
            $this->withdrawalSentToOwner(),
        );
    }

    /**
     * A node the master last saw offline cannot take the marker away, and the refusal is made here
     * rather than travelling: the person is standing in front of an open modal.
     */
    public function testAWithdrawalNamingADeadNodeIsRefusedOnTheSpot(): void
    {
        $this->publishNode(self::PEER, online: false);
        $page = $this->dispatchingPage();

        try {
            $page->onAction(
                self::ACCEPT_KEY,
                HilosSignalConstants::LOGS_TAKEOUT_UNDO,
                $this->withdrawal(self::PEER),
            );
            $this->fail('Expected TableActionException');
        } catch (TableActionException $e) {
            $this->assertSame('Cluster node ' . self::PEER . ' is offline', $e->getMessage());
        }

        $this->assertNull(Hilos::$sr?->getNextQueuedSignal(), 'A refused withdrawal travels nowhere');
    }

    public function testAnActionThePageDoesNotOwnIsRefusedByName(): void
    {
        $page = $this->dispatchingPage();

        $this->expectException(AgentUnknownActionException::class);

        $page->onAction(self::ACCEPT_KEY, HilosSignalConstants::LOGS_READ_LINES, $this->confirmation(''));
    }

    public function testAPayloadThatIsNotAConfirmationIsRefusedByType(): void
    {
        $page = $this->dispatchingPage();

        $this->expectException(InvalidActionPayloadException::class);

        $page->onAction(
            self::ACCEPT_KEY,
            HilosSignalConstants::LOGS_TAKEOUT_CONFIRM,
            new LogsRotationsPageTestForeignActionDTO(),
        );
    }

    /**
     * Runs one tick past its throttle, with the picture changed underneath so the header really
     * differs and a push has a reason to go out. An empty result therefore means "nobody is
     * registered" rather than "nothing happened".
     *
     * @return list<string> Accept keys the tick pushed the header to
     */
    private function tickRecipients(): array
    {
        $this->growTheClusterPicture();
        $this->tickPastTheThrottle();

        $keys = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
            $this->assertSame(
                HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS_ROTATIONS,
                $signal->signalName->getName(),
            );
            $keys[] = (string) $signal->data->targetAcceptKey;
        }

        return $keys;
    }

    /**
     * Builds a page in the middle of a tracked dispatch, the way the dispatcher hands it one.
     *
     * @return LogsRotationsPageSubscribeTestPage Page whose running action carries a request id
     */
    private function dispatchingPage(): LogsRotationsPageSubscribeTestPage
    {
        $page = new LogsRotationsPageSubscribeTestPage(new LogsRotationsPageSubscribeTestAgent());
        $page->beginActionDispatch(self::REQUEST_ID);

        return $page;
    }

    /**
     * Publishes one row of the cluster roster the page reads.
     *
     * @param string $nodeId Id of the node the row is about
     * @param bool $online Whether the master saw the node connected when it last published
     */
    private function publishNode(string $nodeId, bool $online): void
    {
        Hilos::$rt?->hilosClusterNodes->actions->publish($nodeId, 'master', [], null, $online, microtime(true));
    }

    /**
     * Builds one confirmation request as the page receives it.
     *
     * @param string $nodeId Id of the node holding the batch, empty for this node
     * @return LogsTakeoutConfirmActionDTO Confirmation request
     */
    private function confirmation(string $nodeId): LogsTakeoutConfirmActionDTO
    {
        return new LogsTakeoutConfirmActionDTO(nodeId: $nodeId, batchTimestamp: self::CONFIRMED_BATCH_AT);
    }

    /**
     * Builds one withdrawal request as the page receives it.
     *
     * @param string $nodeId Id of the node holding the batch, empty for this node
     * @return LogsTakeoutUndoActionDTO Withdrawal request
     */
    private function withdrawal(string $nodeId): LogsTakeoutUndoActionDTO
    {
        return new LogsTakeoutUndoActionDTO(nodeId: $nodeId, batchTimestamp: self::CONFIRMED_BATCH_AT);
    }

    /**
     * Reads back the one frame the page sent to the owner to withdraw a confirmation.
     *
     * @return LogsTakeoutUndoSignalData Payload of that frame
     */
    private function withdrawalSentToOwner(): LogsTakeoutUndoSignalData
    {
        $signal = Hilos::$sr?->getNextQueuedSignal();

        $this->assertNotNull($signal, 'The page forwards the withdrawal; nothing was queued');
        $this->assertSame(SignalTypeConstants::AGENT_SIGNAL, $signal->signalType->getType());
        $this->assertSame(HilosSignalConstants::LOGS_AGENT_TAKEOUT_UNDO, $signal->signalName->getName());
        $this->assertInstanceOf(AgentSignalData::class, $signal->data);
        $this->assertInstanceOf(LogsTakeoutUndoSignalData::class, $signal->data->data);
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal(), 'One withdrawal is one frame');

        return $signal->data->data;
    }

    /**
     * Reads back the one frame the page sent to the owner of the batch directory.
     *
     * @return LogsTakeoutConfirmSignalData Payload of that frame
     */
    private function sentToOwner(): LogsTakeoutConfirmSignalData
    {
        $signal = Hilos::$sr?->getNextQueuedSignal();

        $this->assertNotNull($signal, 'The page forwards the confirmation; nothing was queued');
        $this->assertSame(SignalTypeConstants::AGENT_SIGNAL, $signal->signalType->getType());
        $this->assertSame(HilosSignalConstants::LOGS_AGENT_TAKEOUT_CONFIRM, $signal->signalName->getName());
        $this->assertInstanceOf(AgentSignalData::class, $signal->data);
        $this->assertInstanceOf(LogsTakeoutConfirmSignalData::class, $signal->data->data);
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal(), 'One confirmation is one frame');

        return $signal->data->data;
    }

    /**
     * Waits out the tick's throttle and runs one tick.
     */
    private function tickPastTheThrottle(): void
    {
        usleep(self::PAST_THE_TICK_THROTTLE_MICROSECONDS);
        AbstractHilosLogsRotationsPage::onAgentTick(new LogsRotationsPageSubscribeTestAgent());
    }

    /**
     * Drains the queue into the signal names it holds.
     *
     * @return list<string> Queued signal names, oldest first
     */
    private function queuedSignalNames(): array
    {
        $names = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $names[] = $signal->signalName->getName();
        }

        return $names;
    }

    /**
     * Takes the header the page answered a subscription with.
     *
     * @return HilosLogsRotationsSignalData Payload of the first queued signal
     */
    private function header(): HilosLogsRotationsSignalData
    {
        $signal = Hilos::$sr?->getNextQueuedSignal();

        $this->assertNotNull($signal, 'The subscription answers with a header');
        $this->assertSame(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS_ROTATIONS,
            $signal->signalName->getName(),
        );
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertInstanceOf(HilosLogsRotationsSignalData::class, $signal->data->data);

        return $signal->data->data;
    }

    /**
     * Files one more frame of the cluster picture, with one node more and heavier batches than the
     * last, so both fingerprints the page compares against differ from what they were.
     *
     * The node count is what moves the HEADER: the batch weights live in the rows and would leave
     * a header push with no reason to happen, which is exactly the tick's own rule.
     */
    private function growTheClusterPicture(): void
    {
        $this->batchBytes += 100;
        $this->reportedNodes++;

        $slots = [];
        for ($node = 1; $node <= $this->reportedNodes; $node++) {
            $slots[] = $this->slot('node-' . $node, available: true);
        }
        $this->picture(...$slots);
    }

    /**
     * Files a whole cluster picture into the mirror the page reads.
     *
     * @param ClusterLogNodeSlot ...$slots Node slots the picture is made of
     */
    private function picture(ClusterLogNodeSlot ...$slots): void
    {
        ClusterLogIndexMirror::applyPortion(
            ClusterLogIndexPortionSignalData::ofSlots(array_values($slots), true),
        );
    }

    /**
     * The mirror belongs to the worker process, so a case leaves it as it found it.
     */
    private function emptyTheMirror(): void
    {
        ClusterLogIndexMirror::forgetPicture();
        foreach (ClusterLogIndexMirror::viewerKeys() as $acceptKey) {
            ClusterLogIndexMirror::removeViewer($acceptKey);
        }
    }

    /**
     * Builds one node's slot holding a single batch.
     *
     * @param ?string $nodeId Node the slot belongs to, null in a single-node installation
     * @param bool $available Whether that node could read its log store
     * @param list<int> $due Batches this node's own retention rule recommends carrying off
     * @return ClusterLogNodeSlot Slot as the aggregator would hold it
     */
    private function slot(?string $nodeId, bool $available, array $due = []): ClusterLogNodeSlot
    {
        // An unreadable store reports empty projections, the way NodeLogIndex carries the state:
        // zeros there would claim a walk that never happened.
        $batches = $available
            ? [new LogBatchSummary(self::T0 - 3_600, 1, $this->batchBytes, 0, 0, 0, 0, 0, 0)]
            : [];

        return new ClusterLogNodeSlot(
            nodeId: $nodeId,
            index: new NodeLogIndex(
                nodeId: $nodeId,
                available: $available,
                sampledAt: self::T0,
                batches: $batches,
                keys: [],
                workers: [],
                growthBytesPerDay: [],
                dueBatchTimestamps: $due,
            ),
            receivedAt: self::T0,
        );
    }
}

/**
 * Browser context fixture recording the window deliveries the tick asked for.
 */
final class LogsRotationsPageSubscribeTestBrowser extends BrowserContext
{
    /** @var list<string> Table keys whose window delivery was reached */
    public array $windows = [];

    /**
     * Records the window delivery instead of building one from a table nothing mounted.
     *
     * @param string $page Page the table belongs to (unused)
     * @param string $acceptKey Subscribing WebSocket accept key (unused)
     * @param TableViewportSubscription $viewport Window descriptor
     * @return bool Always true; reaching this method is what the case asserts
     */
    public function sendTableWindow(string $page, string $acceptKey, TableViewportSubscription $viewport): bool
    {
        $this->windows[] = $viewport->tableKey;

        return true;
    }
}

/**
 * Concrete stand-in for a project's rotation-history page, which adds nothing of its own.
 */
final class LogsRotationsPageSubscribeTestPage extends AbstractHilosLogsRotationsPage
{
}

/**
 * Minimal page agent providing a signal source for sendToUser and for the tick push.
 */
final class LogsRotationsPageSubscribeTestAgent implements PageAgentInterface
{
    public function getId(): string
    {
        return 'hilos_logs';
    }

    public function getAgentSignalSource(): SignalSourceInterface
    {
        return new SignalSource(SignalSource::AGENT, 'hilos_logs');
    }
}

/**
 * Runtime context carrying nothing of its own: the cluster roster is mounted by the framework.
 */
final class LogsRotationsPageTestRtContext extends RtContext
{
    public function configure(): void
    {
    }
}

/**
 * Payload of another action entirely, for the type guard.
 */
final class LogsRotationsPageTestForeignActionDTO extends ActionPayloadDTO
{
    /**
     * @return string Action name this payload belongs to
     */
    public function getAction(): string
    {
        return 'logs_takeout_undo';
    }

    /**
     * @return array<string, mixed> Empty payload
     */
    public function toArray(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $data Wire payload
     * @return static Restored payload
     */
    public static function fromArray(array $data): static
    {
        return new static();
    }
}
