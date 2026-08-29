<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Hilos;
use Hilos\Log\DTO\LogsReadLinesSignalData;
use Hilos\Pages\Logs\AbstractHilosLogsViewPage;
use Hilos\Pages\Logs\DTO\LogsReadLinesActionDTO;
use Hilos\Runtime\State\Item\HilosClusterNode as StateHilosClusterNode;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Tests how the logs viewer page answers a read request (HIL-757).
 *
 * The page owns no log file - one node's owner does - so what it is judged on here is the
 * handover: it refuses a node an operator can still do something about, forwards everything else
 * to the owner with the whole request plus whom to answer, and then stops owing an ack of its own.
 *
 * The two refusals are kept apart on purpose. An id no node answers to and a node the master last
 * saw offline read as the same "no lines" to a page that merges them, and they are not the same
 * thing to the person looking: one is a stale choice, the other is the outage being investigated.
 */
final class LogsViewPageReadLinesTest extends TestCase
{
    /** @var string Node this installation runs on, as the roster names it */
    private const string SELF = 'node-A';

    /** @var string Node on the other end of the cluster, the one the reads are addressed to */
    private const string PEER = 'node-B';

    /** @var string Request id of the tracked dispatch under test */
    private const string REQUEST_ID = 'req-1';

    /** @var string Accept key of the connection waiting for the page of lines */
    private const string ACCEPT_KEY = 'ak-logs-view-1';

    protected function setUp(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = new LogsViewPageTestRtContext();
        Hilos::$rt->mountFeatureRuntime([]);
        RtTruthSourceRegistry::registerDaemon(StateHilosClusterNode::RT_COLLECTION);
    }

    protected function tearDown(): void
    {
        RtTruthSourceRegistry::unregisterDaemon(StateHilosClusterNode::RT_COLLECTION);
        Hilos::$rt = null;
        Hilos::$sr = null;

        parent::tearDown();
    }

    public function testAReadForALiveNodeReachesItsOwnerWholeAndLeavesThePageOwingNothing(): void
    {
        $this->publishNode(self::PEER, true);
        $page = $this->dispatchingPage();

        $reply = $page->onAction(self::ACCEPT_KEY, HilosSignalConstants::LOGS_READ_LINES, $this->request(self::PEER));

        $this->assertNull($reply, 'The page answers with nothing: the owner answers instead');
        $this->assertTrue($page->actionReplyDeferred(), 'Having handed the request over, the page must not also ack');
        $this->assertEquals(
            new LogsReadLinesSignalData(
                nodeId: self::PEER,
                source: LogsReadLinesActionDTO::SOURCE_BATCH,
                batchTimestamp: 1774000000,
                stream: 'worker-0.log',
                level: 'ERROR',
                substring: 'timeout',
                cursor: 4096,
                acceptKey: self::ACCEPT_KEY,
                action: HilosSignalConstants::LOGS_READ_LINES,
                requestId: self::REQUEST_ID,
            ),
            $this->sentToOwner(),
        );
    }

    public function testAReadWithNoNodeGoesToThisNodesOwnerWithoutConsultingTheRoster(): void
    {
        // The roster is deliberately empty: a standalone install publishes itself under an EMPTY
        // id, so a page that looked an empty id up would be asking whether this machine exists.
        $page = $this->dispatchingPage();

        $page->onAction(self::ACCEPT_KEY, HilosSignalConstants::LOGS_READ_LINES, $this->request(''));

        $this->assertSame('', $this->sentToOwner()->nodeId);
        $this->assertTrue($page->actionReplyDeferred());
    }

    public function testAReadNamingANodeThisClusterDoesNotHaveIsRefusedOnTheSpot(): void
    {
        $this->publishNode(self::SELF, true);
        $page = $this->dispatchingPage();

        try {
            $page->onAction(self::ACCEPT_KEY, HilosSignalConstants::LOGS_READ_LINES, $this->request(self::PEER));
            $this->fail('Expected TableActionException');
        } catch (TableActionException $e) {
            $this->assertSame('Unknown cluster node: ' . self::PEER, $e->getMessage());
        }

        $this->assertNull(Hilos::$sr?->getNextQueuedSignal(), 'A refused read travels nowhere');
    }

    public function testAReadNamingANodeTheMasterSawFallOverSaysThatInsteadOfUnknown(): void
    {
        $this->publishNode(self::PEER, false);
        $page = $this->dispatchingPage();

        try {
            $page->onAction(self::ACCEPT_KEY, HilosSignalConstants::LOGS_READ_LINES, $this->request(self::PEER));
            $this->fail('Expected TableActionException');
        } catch (TableActionException $e) {
            $this->assertSame('Cluster node ' . self::PEER . ' is offline', $e->getMessage());
        }

        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    /**
     * An untracked read has no ack to correlate, so there is nothing to defer - and deferring it
     * anyway would be a promise the owner cannot keep.
     */
    public function testAnUntrackedReadIsForwardedWithNoRequestIdAndNothingIsDeferred(): void
    {
        $this->publishNode(self::PEER, true);
        $page = new LogsViewTestPage(new LogsViewTestAgent());
        $page->beginActionDispatch();

        $page->onAction(self::ACCEPT_KEY, HilosSignalConstants::LOGS_READ_LINES, $this->request(self::PEER));

        $this->assertNull($this->sentToOwner()->requestId);
        $this->assertFalse($page->actionReplyDeferred());
    }

    public function testAnActionThePageDoesNotOwnIsRefusedByName(): void
    {
        $page = $this->dispatchingPage();

        $this->expectException(AgentUnknownActionException::class);

        $page->onAction(self::ACCEPT_KEY, 'logs_read_something_else', $this->request(''));
    }

    public function testAPayloadThatIsNotAReadRequestIsRefusedByType(): void
    {
        $page = $this->dispatchingPage();

        $this->expectException(InvalidActionPayloadException::class);

        $page->onAction(
            self::ACCEPT_KEY,
            HilosSignalConstants::LOGS_READ_LINES,
            new LogsViewTestForeignActionDTO(),
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
     * Builds one read request, filled in every field so nothing can be dropped unnoticed.
     *
     * @param string $nodeId Id of the node owning the file, empty for this node
     * @return LogsReadLinesActionDTO Read request as the page receives it
     */
    private function request(string $nodeId): LogsReadLinesActionDTO
    {
        return new LogsReadLinesActionDTO(
            nodeId: $nodeId,
            source: LogsReadLinesActionDTO::SOURCE_BATCH,
            batchTimestamp: 1774000000,
            stream: 'worker-0.log',
            level: 'ERROR',
            substring: 'timeout',
            cursor: 4096,
        );
    }

    /**
     * Reads back the one frame the page sent to the owner of the files.
     *
     * @return LogsReadLinesSignalData Payload of that frame
     */
    private function sentToOwner(): LogsReadLinesSignalData
    {
        $signal = Hilos::$sr?->getNextQueuedSignal();

        $this->assertNotNull($signal, 'The page forwards the read; nothing was queued');
        $this->assertSame(SignalTypeConstants::AGENT_SIGNAL, $signal->signalType->getType());
        $this->assertSame(HilosSignalConstants::LOGS_AGENT_READ_LINES, $signal->signalName->getName());
        $this->assertInstanceOf(AgentSignalData::class, $signal->data);
        $this->assertInstanceOf(LogsReadLinesSignalData::class, $signal->data->data);
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal(), 'One read is one frame');

        return $signal->data->data;
    }
}

/**
 * Concrete viewer page: the abstract one carries the whole behaviour, a project adds nothing.
 */
final class LogsViewTestPage extends AbstractHilosLogsViewPage
{
}

/**
 * Page agent carrying only what a page may reach for: its id and its signal source.
 */
final class LogsViewTestAgent implements PageAgentInterface
{
    public function getId(): string
    {
        return 'hilos_index';
    }

    public function getAgentSignalSource(): SignalSourceInterface
    {
        return new SignalSource(SignalSource::AGENT, $this->getId());
    }
}

/**
 * Runtime context carrying nothing of its own: the roster is mounted by the framework.
 */
final class LogsViewPageTestRtContext extends RtContext
{
    public function configure(): void
    {
    }
}

/**
 * Payload of another action entirely, for the type guard.
 */
final class LogsViewTestForeignActionDTO extends ActionPayloadDTO
{
    /**
     * @return string Action name this payload belongs to
     */
    public function getAction(): string
    {
        return 'logs_read_something_else';
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
