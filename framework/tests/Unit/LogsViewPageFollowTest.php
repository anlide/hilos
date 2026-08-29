<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Hilos;
use Hilos\Log\DTO\LogsFollowStartSignalData;
use Hilos\Log\DTO\LogsFollowStopSignalData;
use Hilos\Pages\Logs\DTO\LogsFollowStartActionDTO;
use Hilos\Pages\Logs\DTO\LogsFollowStopActionDTO;
use Hilos\Runtime\State\Item\HilosClusterNode as StateHilosClusterNode;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Tests how the logs viewer page turns following on and off (HIL-389).
 *
 * The page never reads a line and never sees one: it checks the node, hands the follow to that
 * node's owner and steps out of its own action, exactly as it does for a read. What is judged here
 * is the bookkeeping that only it can do - which node each connection is following - because
 * everything downstream depends on the removal reaching the RIGHT owner: the one recorded at the
 * start, not the one the browser happens to name later.
 *
 * The asymmetry between the two actions is deliberate and is pinned here. A start hands over and
 * defers; a removal is answered on the spot, because it cannot fail in a way the viewer could act
 * on and waiting for a confirmation would hold a browser in loading for somebody else's fact.
 */
final class LogsViewPageFollowTest extends TestCase
{
    /** @var string Node on the other end of the cluster, the one a follow is addressed to */
    private const string PEER = 'node-B';

    /** @var string A second peer, for the case where a viewer switches nodes mid-follow */
    private const string OTHER_PEER = 'node-C';

    /** @var string Request id of the tracked dispatch under test */
    private const string REQUEST_ID = 'req-follow-1';

    /** @var string Accept key of the connection that will receive the appended lines */
    private const string ACCEPT_KEY = 'ak-logs-follow-1';

    protected function setUp(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = new LogsViewPageTestRtContext();
        Hilos::$rt->mountFeatureRuntime([]);
        RtTruthSourceRegistry::registerDaemon(StateHilosClusterNode::RT_COLLECTION);
    }

    protected function tearDown(): void
    {
        // The page keeps its follows per worker, not per dispatch, so a case that started one and
        // never stopped it would hand it to the next case. Released the same way a closing tab
        // releases it.
        new LogsViewTestPage(new LogsViewTestAgent())->onUnsubscribe(self::ACCEPT_KEY);
        RtTruthSourceRegistry::unregisterDaemon(StateHilosClusterNode::RT_COLLECTION);
        Hilos::$rt = null;
        Hilos::$sr = null;

        parent::tearDown();
    }

    public function testAFollowForALiveNodeReachesItsOwnerWholeAndLeavesThePageOwingNothing(): void
    {
        $this->publishNode(self::PEER, true);
        $page = $this->dispatchingPage();

        $reply = $page->onAction(self::ACCEPT_KEY, HilosSignalConstants::LOGS_FOLLOW_START, $this->request(self::PEER));

        $this->assertNull($reply, 'The page answers with nothing: the owner answers the first page');
        $this->assertTrue($page->actionReplyDeferred(), 'Having handed the follow over, the page must not also ack');
        $this->assertEquals(
            new LogsFollowStartSignalData(
                nodeId: self::PEER,
                stream: 'worker-0.log',
                level: 'ERROR',
                substring: 'timeout',
                acceptKey: self::ACCEPT_KEY,
                action: HilosSignalConstants::LOGS_FOLLOW_START,
                requestId: self::REQUEST_ID,
            ),
            $this->nextFrame(HilosSignalConstants::LOGS_AGENT_FOLLOW_START),
        );
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal(), 'One start is one frame');
    }

    public function testAFollowWithNoNodeGoesToThisNodesOwnerWithoutConsultingTheRoster(): void
    {
        // The roster is deliberately empty: a standalone install publishes itself under an EMPTY
        // id, so a page that looked an empty id up would be asking whether this machine exists.
        $page = $this->dispatchingPage();

        $page->onAction(self::ACCEPT_KEY, HilosSignalConstants::LOGS_FOLLOW_START, $this->request(''));

        $this->assertSame('', $this->nextFrame(HilosSignalConstants::LOGS_AGENT_FOLLOW_START)->nodeId);
        $this->assertTrue($page->actionReplyDeferred());
    }

    public function testAFollowNamingANodeThisClusterDoesNotHaveIsRefusedOnTheSpot(): void
    {
        $page = $this->dispatchingPage();

        try {
            $page->onAction(self::ACCEPT_KEY, HilosSignalConstants::LOGS_FOLLOW_START, $this->request(self::PEER));
            $this->fail('Expected TableActionException');
        } catch (TableActionException $e) {
            $this->assertSame('Unknown cluster node: ' . self::PEER, $e->getMessage());
        }

        $this->assertNull(Hilos::$sr?->getNextQueuedSignal(), 'A refused follow travels nowhere');
    }

    public function testAFollowNamingANodeTheMasterSawFallOverSaysThatInsteadOfUnknown(): void
    {
        $this->publishNode(self::PEER, false);
        $page = $this->dispatchingPage();

        try {
            $page->onAction(self::ACCEPT_KEY, HilosSignalConstants::LOGS_FOLLOW_START, $this->request(self::PEER));
            $this->fail('Expected TableActionException');
        } catch (TableActionException $e) {
            $this->assertSame('Cluster node ' . self::PEER . ' is offline', $e->getMessage());
        }

        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    /**
     * The owner keys its readers by accept key, so a second start on the SAME node replaces the
     * first by itself - but a start on a different node would leave the previous owner reading a
     * file for a viewer that has stopped listening to it.
     */
    public function testSwitchingNodesReleasesThePreviousOwnerBeforeAskingTheNewOne(): void
    {
        $this->publishNode(self::PEER, true);
        $this->publishNode(self::OTHER_PEER, true);
        $this->dispatchingPage()->onAction(
            self::ACCEPT_KEY,
            HilosSignalConstants::LOGS_FOLLOW_START,
            $this->request(self::PEER),
        );
        $this->nextFrame(HilosSignalConstants::LOGS_AGENT_FOLLOW_START);

        $this->dispatchingPage()->onAction(
            self::ACCEPT_KEY,
            HilosSignalConstants::LOGS_FOLLOW_START,
            $this->request(self::OTHER_PEER),
        );

        $this->assertEquals(
            new LogsFollowStopSignalData(self::PEER, self::ACCEPT_KEY),
            $this->nextFrame(HilosSignalConstants::LOGS_AGENT_FOLLOW_STOP),
        );
        $this->assertSame(self::OTHER_PEER, $this->nextFrame(HilosSignalConstants::LOGS_AGENT_FOLLOW_START)->nodeId);
    }

    public function testStartingAgainOnTheSameNodeSendsNoRemoval(): void
    {
        $this->publishNode(self::PEER, true);
        $this->dispatchingPage()->onAction(
            self::ACCEPT_KEY,
            HilosSignalConstants::LOGS_FOLLOW_START,
            $this->request(self::PEER),
        );
        $this->nextFrame(HilosSignalConstants::LOGS_AGENT_FOLLOW_START);

        $this->dispatchingPage()->onAction(
            self::ACCEPT_KEY,
            HilosSignalConstants::LOGS_FOLLOW_START,
            $this->request(self::PEER),
        );

        $this->assertSame(self::PEER, $this->nextFrame(HilosSignalConstants::LOGS_AGENT_FOLLOW_START)->nodeId);
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal(), 'Replacing a follow on one node is one frame');
    }

    public function testARemovalGoesToTheRecordedNodeAndIsAnsweredWithoutWaiting(): void
    {
        $this->publishNode(self::PEER, true);
        $this->dispatchingPage()->onAction(
            self::ACCEPT_KEY,
            HilosSignalConstants::LOGS_FOLLOW_START,
            $this->request(self::PEER),
        );
        $this->nextFrame(HilosSignalConstants::LOGS_AGENT_FOLLOW_START);
        $page = $this->dispatchingPage();

        // The browser names a node it is no longer on: the removal must still reach the owner
        // that is actually holding the follow.
        $reply = $page->onAction(
            self::ACCEPT_KEY,
            HilosSignalConstants::LOGS_FOLLOW_STOP,
            new LogsFollowStopActionDTO(self::OTHER_PEER),
        );

        $this->assertNull($reply);
        $this->assertFalse($page->actionReplyDeferred(), 'A removal is answered here, not by the owner');
        $this->assertEquals(
            new LogsFollowStopSignalData(self::PEER, self::ACCEPT_KEY),
            $this->nextFrame(HilosSignalConstants::LOGS_AGENT_FOLLOW_STOP),
        );
    }

    public function testARemovalFromAConnectionThatWasNotFollowingTravelsNowhere(): void
    {
        $page = $this->dispatchingPage();

        $page->onAction(self::ACCEPT_KEY, HilosSignalConstants::LOGS_FOLLOW_STOP, new LogsFollowStopActionDTO(''));

        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
        $this->assertFalse($page->actionReplyDeferred());
    }

    /**
     * The framework delivers this both on leaving the page and on the socket closing, so a closed
     * tab releases the reader by the same path an explicit switch-off does.
     */
    public function testLeavingThePageReleasesTheOwnerTheStartWasSentTo(): void
    {
        $this->publishNode(self::PEER, true);
        $this->dispatchingPage()->onAction(
            self::ACCEPT_KEY,
            HilosSignalConstants::LOGS_FOLLOW_START,
            $this->request(self::PEER),
        );
        $this->nextFrame(HilosSignalConstants::LOGS_AGENT_FOLLOW_START);

        new LogsViewTestPage(new LogsViewTestAgent())->onUnsubscribe(self::ACCEPT_KEY);

        $this->assertEquals(
            new LogsFollowStopSignalData(self::PEER, self::ACCEPT_KEY),
            $this->nextFrame(HilosSignalConstants::LOGS_AGENT_FOLLOW_STOP),
        );
    }

    /**
     * The follow id IS the request id: a viewer that did not track its own start has nothing to
     * match the frames against, so there is no follow to begin.
     */
    public function testAnUntrackedFollowIsNotStartedAtAll(): void
    {
        $this->publishNode(self::PEER, true);
        $page = new LogsViewTestPage(new LogsViewTestAgent());
        $page->beginActionDispatch();

        $page->onAction(self::ACCEPT_KEY, HilosSignalConstants::LOGS_FOLLOW_START, $this->request(self::PEER));

        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
        $this->assertFalse($page->actionReplyDeferred());
    }

    public function testAPayloadThatIsNotAFollowRequestIsRefusedByType(): void
    {
        $page = $this->dispatchingPage();

        $this->expectException(InvalidActionPayloadException::class);

        $page->onAction(
            self::ACCEPT_KEY,
            HilosSignalConstants::LOGS_FOLLOW_START,
            new LogsFollowStopActionDTO(''),
        );
    }

    /**
     * Builds a page in the middle of a tracked dispatch, the way the dispatcher hands it one.
     *
     * @return LogsViewTestPage Page whose running action carries a request id
     */
    private function dispatchingPage(): LogsViewTestPage
    {
        $page = new LogsViewTestPage(new LogsViewTestAgent());
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
     * Builds one follow request, filled in every field so nothing can be dropped unnoticed.
     *
     * @param string $nodeId Id of the node owning the file, empty for this node
     * @return LogsFollowStartActionDTO Follow request as the page receives it
     */
    private function request(string $nodeId): LogsFollowStartActionDTO
    {
        return new LogsFollowStartActionDTO(
            nodeId: $nodeId,
            stream: 'worker-0.log',
            level: 'ERROR',
            substring: 'timeout',
        );
    }

    /**
     * Takes the next queued frame and asserts it is the agent signal named.
     *
     * @param string $signalName Agent signal the frame must carry
     * @return LogsFollowStartSignalData|LogsFollowStopSignalData Payload of that frame
     */
    private function nextFrame(string $signalName): LogsFollowStartSignalData|LogsFollowStopSignalData
    {
        $signal = Hilos::$sr?->getNextQueuedSignal();

        $this->assertInstanceOf(SignalDTO::class, $signal, 'The page forwards the follow; nothing was queued');
        $this->assertSame(SignalTypeConstants::AGENT_SIGNAL, $signal->signalType->getType());
        $this->assertSame($signalName, $signal->signalName->getName());
        $this->assertInstanceOf(AgentSignalData::class, $signal->data);
        $payload = $signal->data->data;
        $this->assertTrue(
            $payload instanceof LogsFollowStartSignalData || $payload instanceof LogsFollowStopSignalData,
            'A follow frame carries a follow payload',
        );

        return $payload;
    }
}
