<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Hilos\Constants\AgentConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Exception\AgentCreationFailedException;
use Hilos\Core\Daemon\ContainedFailure;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\AbstractPageFactory;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\Exception\PageNotFoundException;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Page\SignalRouteConfig;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Files\Upload\UploadMemoryPool;
use Hilos\Files\Upload\UploadMemoryPoolSink;
use Hilos\Files\Upload\UploadMemoryInput;
use Hilos\Files\Upload\UploadMemoryReceipt;
use Hilos\Hilos;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketFrameBinarySignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\Worker\DTO\AgentStartDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRegisterDTO;
use Hilos\Socket\Worker\DTO\WorkerUploadConsumedDTO;
use Hilos\Socket\Worker\DaemonConnectionState;
use Hilos\Socket\Worker\WorkerDaemonClient;
use Hilos\Socket\Worker\WorkerDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Socket;

/** Receipt lifetime through the real worker dispatch/park loop and registered master-side decoder. */
final class WorkerUploadReceiptTest extends TestCase
{
    /** @var class-string<Hilos> Previously bound app */
    private string $previousApp;

    private WorkerUploadReceiptManager $manager;

    /**
     * Mounts the isolated worker fixture and starts the live receiver.
     */
    protected function setUp(): void
    {
        $this->previousApp = Hilos::appClass();
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, WorkerUploadReceiptHilos::class);
        SourceInterestRegistry::readsWhatIsDelivered();
        $this->manager = new WorkerUploadReceiptManager();
        $this->manager->handleDaemonMessage(new AgentStartDTO(WorkerUploadReceiptAgent::AGENT_TYPE));
    }

    /**
     * Releases fixture interests and restores the process facade and execution context.
     */
    protected function tearDown(): void
    {
        foreach (['socket', 'other'] as $acceptKey) {
            SourceInterestRegistry::releaseConsumer(SourceConsumer::page($acceptKey));
        }
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent(WorkerUploadReceiptAgent::AGENT_TYPE));
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent(WorkerUploadReceiptWaitingAgent::AGENT_TYPE));
        SourceInterestRegistry::readsWhatItMounts();
        ExecutionContext::clear();
        Hilos::$sr = null;
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousApp);
        parent::tearDown();
    }

    /**
     * A real socket read holds its receipt until the final page handler returns.
     */
    public function testReadingAndDequeuingDoNotConsumeAndEveryBinaryHandlerFinishesBeforeCollection(): void
    {
        $message = $this->binary('receipt');
        $pair = [];
        $this->assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        $client = new WorkerUploadReceiptSocketClient();
        try {
            $this->assertTrue(socket_set_nonblock($pair[0]));
            $client->adopt($pair[0]);
            $wire = $message->toJson() . "\n";
            $this->assertSame(strlen($wire), socket_write($pair[1], $wire));
            $client->read();
            $this->assertSame([], $this->pending());
            $this->assertSame([], $this->batches());
            $this->assertFalse($client->hasPendingWrite());
            $message = $client->getNextMessage();
            $this->assertInstanceOf(DaemonAgentMessageDTO::class, $message);
            $this->assertSame('receipt', $message->uploadReceiptId);
            $this->assertSame([], $this->pending());
            $this->assertFalse($client->hasPendingWrite());
        } finally {
            $client->close();
            socket_close($pair[1]);
        }

        $this->manager->duringHandler = function (): void {
            $this->assertSame([], $this->pending());
            $this->flush();
            $this->assertSame([], $this->batches());
        };
        $this->manager->handleDaemonMessage($message);
        $this->assertSame(['worker:socket', 'agent:socket', 'page:socket'], $this->manager->events);
        $this->assertSame(['receipt'], $this->pending());
        $this->assertSame([], $this->batches());
        $this->flush();
        $this->assertSame([['receipt']], $this->batches());
        $this->assertSame([], $this->pending());
        $this->flush();
        $this->assertSame([['receipt']], $this->batches(), 'No empty batch or repeated flush.');
    }

    /** @return iterable<string, array{string, list<string>}> Each terminal failure boundary in the binary path */
    public static function failingStages(): iterable
    {
        yield 'worker hook' => ['worker', ['worker:socket']];
        yield 'agent' => ['agent', ['worker:socket', 'agent:socket']];
        yield 'page' => ['page', ['worker:socket', 'agent:socket', 'page:socket']];
    }

    /**
     * @param string $stage Callback that throws
     * @param list<string> $events Callbacks reached before the failure
     */
    #[DataProvider('failingStages')]
    public function testAHandlerFailureCollectsInFinallyAndTheFollowingQueueUnitStillRuns(string $stage, array $events): void
    {
        $this->manager->failingStage = $stage;
        $this->manager->client->parse($this->binary('failed'));
        $this->manager->client->parse($this->binary('missing', agentId: 'absent-agent'));
        $this->manager->run();
        $this->assertSame($events, $this->manager->events);
        $this->assertCount(1, $this->manager->failures);
        $this->assertInstanceOf(RuntimeException::class, $this->manager->failures[0]->failure);
        $this->assertSame([['failed', 'missing']], $this->batches());
        $this->assertNull(ExecutionContext::currentAgentId());
        $this->assertNull(ExecutionContext::currentAcceptKey());
        $this->assertSame('tick', $this->manager->loopEvents[1]);
    }

    /**
     * The loop emits one ids-only batch after every queued unit and before ticking.
     */
    public function testOneBatchFollowsTheWholeQueueBeforeWorkerTickAndContainsOnlyUniqueIds(): void
    {
        foreach (['first', 'second', 'first', '0'] as $receiptId) {
            $this->manager->client->parse($this->binary($receiptId));
        }
        $this->manager->duringHandler = function (): void {
            $this->assertSame([], $this->batches(), 'Earlier messages collect but do not flush inside the queue.');
        };
        $this->manager->run();
        $this->assertSame([['first', 'second', '0']], $this->batches());
        $this->assertSame(['receipts', 'tick'], $this->manager->loopEvents);
        $batch = $this->manager->client->consumed()[0];
        $this->assertSame(['type' => 'worker_upload_consumed', 'receiptIds' => ['first', 'second', '0']], $batch->toArray());
    }

    /**
     * Only a binary delivery carrying a receipt can add an acknowledgement.
     */
    public function testOldMessagesAndNonbinaryMessagesDoNotCreateAnAcknowledgement(): void
    {
        $this->manager->client->parse($this->binary(null));
        $this->manager->client->parse(new DaemonAgentMessageDTO('absent-agent', new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::CONNECTION_CLOSE),
            new SignalName(SignalTypeConstants::CONNECTION_CLOSE),
            new WebSocketCloseSignalDTO('socket'),
        ), 'not-binary'));
        $this->manager->run();
        $this->assertSame(['worker:socket', 'agent:socket', 'page:socket'], $this->manager->events);
        $this->assertSame([], $this->batches());
        $this->assertSame(['tick'], $this->manager->loopEvents);
    }

    /**
     * A later source wait retains the same receipt and does not acknowledge it.
     */
    public function testReleaseAndReparkKeepTheReceiptUntilItsOwnWaitEnds(): void
    {
        $this->subscribe('socket');
        $this->subscribe('socket');
        $this->manager->handleDaemonMessage($this->binary('parked'));
        $this->flush();
        $this->assertSame([], $this->batches());
        $this->assertSame([], $this->manager->events);

        // First subscribe times out; the second opens its own wait and binary reparks behind it.
        $this->manager->pass(microtime(true) + AgentConstants::START_DEADLINE_SECONDS + 1.0);
        $this->assertSame([], $this->pending());
        $this->assertSame([], $this->manager->events);
        $frames = new ReflectionProperty(WorkerManager::class, 'parkedFrames')->getValue($this->manager);
        $this->assertSame('parked', $frames[SourceConsumer::page('socket')][1]->uploadReceiptId);

        SourceInterestRegistry::markReady(SourceChange::KIND_RT, WorkerUploadReceiptReadingPage::COLLECTION);
        $this->manager->pass(microtime(true));
        $this->assertSame(['parked'], $this->pending());
        $this->assertSame(['worker:socket', 'agent:socket', 'page:socket'], $this->manager->events);
        $this->flush();
        $this->manager->pass(microtime(true));
        $this->flush();
        $this->assertSame([['parked']], $this->batches());
    }

    /**
     * Closing one waiting page discards its portions while another page remains live.
     */
    public function testFinalDropConsumesOnlyFramesRemovedFromThatConsumerAndReleasesItsInterest(): void
    {
        $this->subscribe('socket');
        $this->subscribe('other');
        $this->manager->handleDaemonMessage($this->binary('drop-first'));
        $this->manager->handleDaemonMessage($this->binary('drop-second'));
        $this->manager->handleDaemonMessage($this->binary('keep', 'other'));
        $this->manager->handleDaemonMessage($this->binary('live', 'live'));
        $this->assertSame(['live'], $this->pending());
        $this->manager->handleDaemonMessage($this->close('socket'));
        $this->manager->handleDaemonMessage($this->close('socket'));
        $this->assertSame(['live', 'drop-first', 'drop-second'], $this->pending());
        $this->assertNotContains(SourceConsumer::page('socket'), SourceInterestRegistry::consumersOf(
            SourceChange::KIND_RT, WorkerUploadReceiptReadingPage::COLLECTION,
        ));
        $this->assertContains(SourceConsumer::page('other'), SourceInterestRegistry::consumersOf(
            SourceChange::KIND_RT, WorkerUploadReceiptReadingPage::COLLECTION,
        ));
        $this->flush();
        $this->assertSame([['live', 'drop-first', 'drop-second']], $this->batches());

        SourceInterestRegistry::markReady(SourceChange::KIND_RT, WorkerUploadReceiptReadingPage::COLLECTION);
        $this->manager->pass(microtime(true));
        $this->flush();
        $this->assertSame([['live', 'drop-first', 'drop-second'], ['keep']], $this->batches());
        $this->assertSame([
            'worker:live', 'agent:live', 'page:live', 'worker:other', 'agent:other', 'page:other',
        ], $this->manager->events);
    }

    /**
     * A refused start releases its retained binary into a terminal missing-agent drop.
     */
    public function testAFrameBehindAFailedAgentStartIsConsumedOnlyOnTheFinalMissingAgentDrop(): void
    {
        $this->manager->handleDaemonMessage(new AgentStartDTO(WorkerUploadReceiptWaitingAgent::AGENT_TYPE));
        $this->manager->handleDaemonMessage($this->binary('missing', agentId: WorkerUploadReceiptWaitingAgent::AGENT_TYPE));
        $this->flush();
        $this->assertSame([], $this->batches());
        $this->manager->pass(microtime(true) + AgentConstants::START_DEADLINE_SECONDS + 1.0);
        $this->flush();
        $this->assertCount(1, $this->manager->failures);
        $this->assertInstanceOf(AgentCreationFailedException::class, $this->manager->failures[0]->failure);
        $this->assertSame([], $this->manager->events);
        $this->assertFalse(SourceInterestRegistry::isDeclared(SourceChange::KIND_RT, WorkerUploadReceiptWaitingAgent::COLLECTION));
        $this->manager->pass(microtime(true) + 2 * AgentConstants::START_DEADLINE_SECONDS);
        $this->flush();
        $this->assertSame([['missing']], $this->batches());
    }

    /**
     * An optional sink does not bypass registration enforcement.
     */
    public function testUnregisteredConsumedIsRefusedEvenWithNoSink(): void
    {
        $worker = new WorkerUploadReceiptReceivingClient();
        try {
            $worker->receive(new WorkerUploadConsumedDTO(['id']));
            $this->fail('An unregistered worker cannot consume receipts.');
        } catch (InvalidFormatException $failure) {
            $this->assertSame('Upload consumption requires a registered worker', $failure->getMessage());
        }
    }

    /**
     * The real ledger rejects foreign incarnations and derives every released byte count.
     */
    public function testTheRegisteredChannelAndLedgerAloneDetermineWhichBytesAreFreed(): void
    {
        $previousCluster = Hilos::$cluster;
        Hilos::$cluster = null;
        try {
            $pool = new UploadMemoryPool(12, 'boot-new');
            $sink = new WorkerUploadReceiptLedgerSink($pool);
            $worker = new WorkerUploadReceiptReceivingClient();
            $worker->setUploadMemoryPoolSink($sink);
            try {
                $worker->receive(new WorkerUploadConsumedDTO(['id']));
                $this->fail('An unregistered worker must not reach the sink.');
            } catch (InvalidFormatException) {
                $this->assertSame([], $sink->workers);
            }
            $worker->receive(new WorkerRegisterDTO(7));
            $other = new WorkerUploadReceiptReceivingClient();
            $other->receive(new WorkerRegisterDTO(8));
            $other->setUploadMemoryPoolSink($sink);
            $sink->bind($worker, 'regular:7:channel-1');
            $sink->bind($other, 'regular:8:channel-1');

            $pool->request('socket', 'upload', 12);
            $reservation = $pool->reserveNext();
            $this->assertNotNull($reservation);
            $pool->publish($reservation->id);
            $first = $pool->accept($reservation->id, 0, 4, 'regular:7:channel-1');
            $second = $pool->accept($reservation->id, 4, 3, 'regular:8:channel-1');
            $this->assertNotNull($first);
            $this->assertNotNull($second);
            $this->assertSame(12, $pool->getUsedBytes());

            $oldPool = new UploadMemoryPool(4, 'boot-old');
            $oldPool->request('socket', 'upload', 4);
            $oldReservation = $oldPool->reserveNext();
            $this->assertNotNull($oldReservation);
            $oldPool->publish($oldReservation->id);
            $oldReceipt = $oldPool->accept($oldReservation->id, 0, 4, 'regular:7:channel-1');
            $this->assertNotNull($oldReceipt);
            $worker->receive(new WorkerUploadConsumedDTO(['unknown', $second->id, $oldReceipt->id]));
            $this->assertSame(12, $pool->getUsedBytes());

            // Reusing the worker id on a new socket does not reuse its receipt channel incarnation.
            $replacement = new WorkerUploadReceiptReceivingClient();
            $replacement->receive(new WorkerRegisterDTO(7));
            $replacement->setUploadMemoryPoolSink($sink);
            $sink->bind($replacement, 'regular:7:channel-2');
            $replacement->receive(new WorkerUploadConsumedDTO([$first->id]));
            $this->assertSame(12, $pool->getUsedBytes());
            $worker->receiveJson((string)json_encode([
                'type' => 'worker_upload_consumed', 'receiptIds' => [$first->id, $first->id], 'byteCount' => PHP_INT_MAX,
            ]));
            $this->assertSame(8, $pool->getUsedBytes(), 'Only the ledger\'s four bytes are released.');
            $this->assertSame($worker, $sink->workers[array_key_last($sink->workers)]);
            $worker->receive(new WorkerUploadConsumedDTO([$first->id]));
            $this->assertSame(8, $pool->getUsedBytes());
            $other->receive(new WorkerUploadConsumedDTO([$second->id]));
            $this->assertSame(5, $pool->getUsedBytes(), 'Unaccepted permission remains reserved.');
            $this->assertSame($other, $sink->workers[array_key_last($sink->workers)]);
        } finally {
            Hilos::$cluster = $previousCluster;
        }
    }

    /**
     * A cumulative portion held by the master becomes consumable only after actual channel assignment.
     */
    public function testCrossWindowPendingReceiptCanUseTheExistingRegisteredConsumedWire(): void
    {
        $previousCluster = Hilos::$cluster;
        Hilos::$cluster = null;
        try {
            $pool = new UploadMemoryPool(3, 'boot');
            $pool->request('socket', 'upload', 9);
            $first = $pool->reserveNext();
            $this->assertNotNull($first);
            $pool->publish($first->id);
            $pool->setLimitBytes(9);
            $second = $pool->reserveNext();
            $this->assertNotNull($second);
            $pool->publish($second->id);
            $pending = $pool->acceptPending($first->requestId, 0, 7);
            $this->assertNotNull($pending);
            $this->assertSame([$first->id => 3, $second->id => 4], $pending->reservationBytes);
            $this->assertNull($pending->channel);
            $this->assertSame(9, $pool->getUsedBytes());

            $sink = new WorkerUploadReceiptLedgerSink($pool);
            $worker = new WorkerUploadReceiptReceivingClient();
            $worker->receive(new WorkerRegisterDTO(7));
            $worker->setUploadMemoryPoolSink($sink);
            $sink->bind($worker, 'regular:7:live');
            $worker->receive(new WorkerUploadConsumedDTO([$pending->id]));
            $this->assertSame(9, $pool->getUsedBytes(), 'Unassigned bytes are still in the master queue.');
            $bound = $pool->assign($pending->id, 'regular:7:live');
            $this->assertNotNull($bound);
            $this->assertSame($pending->id, $bound->id);
            $this->assertSame(9, $pool->getUsedBytes());

            $replacement = new WorkerUploadReceiptReceivingClient();
            $replacement->receive(new WorkerRegisterDTO(7));
            $replacement->setUploadMemoryPoolSink($sink);
            $sink->bind($replacement, 'regular:7:replacement');
            $replacement->receive(new WorkerUploadConsumedDTO([$bound->id]));
            $this->assertSame(9, $pool->getUsedBytes());
            $worker->receive(new WorkerUploadConsumedDTO([$bound->id, $bound->id]));
            $this->assertSame(2, $pool->getUsedBytes());
            $this->assertSame($worker, $sink->workers[array_key_last($sink->workers)]);
            $pool->cancel('socket', 'upload');
            $this->assertSame(0, $pool->getUsedBytes());
        } finally {
            Hilos::$cluster = $previousCluster;
        }
    }

    /** Fragmented parser admission stays charged through the real worker handler and registered consumed decoder. */
    public function testCoalescedInputReceiptSurvivesCloseUntilTheRealWorkerFinallyReportsIt(): void
    {
        $previousCluster = Hilos::$cluster;
        Hilos::$cluster = null;
        try {
            $pool = new UploadMemoryPool(9, 'node:boot');
            $pool->request('socket', 'upload', 9);
            $hold = $pool->reserveNext();
            $this->assertNotNull($hold);
            $grant = $pool->publish($hold->id);
            $this->assertNotNull($grant);
            $input = new UploadMemoryInput($pool, 'socket');
            $input->allow($grant);
            $input->observe("\x06uploadab", 9);
            $input->observe("\x06uploadabcdefg", 14);
            $input->cancel('upload');
            $this->assertSame(7, $pool->getUsedBytes());
            $receipt = $input->finishMessage();
            $this->assertNotNull($receipt);
            $this->assertSame(7, $receipt->byteCount);
            $input->close();
            $this->assertSame(7, $pool->getUsedBytes());

            $sink = new WorkerUploadReceiptLedgerSink($pool);
            $worker = new WorkerUploadReceiptReceivingClient();
            $worker->receive(new WorkerRegisterDTO(7));
            $worker->setUploadMemoryPoolSink($sink);
            $sink->bind($worker, 'regular:7:live');
            $worker->receive(new WorkerUploadConsumedDTO([$receipt->id]));
            $this->assertSame(7, $pool->getUsedBytes());
            $pool->assign($receipt->id, 'regular:7:live');
            $this->manager->duringHandler = function () use ($pool): void {
                $this->assertSame(7, $pool->getUsedBytes());
                $this->assertSame([], $this->pending());
            };
            $this->manager->handleDaemonMessage(new DaemonAgentMessageDTO(
                WorkerUploadReceiptAgent::AGENT_TYPE,
                new SignalDTO(
                    new SignalSource(SignalSource::WEBSOCKET),
                    new SignalType(SignalTypeConstants::FRAME_BINARY),
                    new SignalName(SignalTypeConstants::FRAME_BINARY),
                    new WebSocketFrameBinarySignalDTO('socket', "\x06uploadabcdefg"),
                ),
                $receipt->id,
            ));
            $this->assertSame(7, $pool->getUsedBytes());
            $this->assertSame([$receipt->id], $this->pending());
            $this->assertSame([], $this->batches());
            $this->flush();
            $this->assertSame([[$receipt->id]], $this->batches());
            $worker->receive($this->manager->client->consumed()[0]);
            $this->assertSame(0, $pool->getUsedBytes());
            $worker->receive($this->manager->client->consumed()[0]);
            $this->assertSame(0, $pool->getUsedBytes());
        } finally {
            Hilos::$cluster = $previousCluster;
        }
    }

    /**
     * Master-side receipt delivery operates without data contexts or a mounted pool.
     */
    public function testOptionalConsumedSinkNeedsNoDatabaseRuntimeSettingsOrCluster(): void
    {
        $previous = [Hilos::$db, Hilos::$rt, Hilos::$setting, Hilos::$cluster];
        Hilos::$db = null;
        Hilos::$rt = null;
        Hilos::$setting = null;
        Hilos::$cluster = null;
        try {
            $worker = new WorkerUploadReceiptReceivingClient();
            $worker->receive(new WorkerRegisterDTO(1));
            $worker->receive(new WorkerUploadConsumedDTO(['unknown']));
            $this->assertTrue($worker->isRegistered());
            $this->assertNull(Hilos::$db);
            $this->assertNull(Hilos::$rt);
            $this->assertNull(Hilos::$setting);
        } finally {
            [Hilos::$db, Hilos::$rt, Hilos::$setting, Hilos::$cluster] = $previous;
        }
    }

    /** @return list<string> Pending ids, without exposing production test API */
    private function pending(): array
    {
        return array_values(new ReflectionProperty(WorkerManager::class, 'pendingUploadReceiptIds')->getValue($this->manager));
    }

    /** Takes the same private flush step the loop takes after the queue. */
    private function flush(): void
    {
        $flush = Closure::bind(static function (WorkerManager $manager): void {
            $manager->flushUploadReceipts();
        }, null, WorkerManager::class);
        $flush($this->manager);
    }

    /** @return list<list<string>> Sent consumed batches in wire order */
    private function batches(): array
    {
        return array_map(static fn (WorkerUploadConsumedDTO $dto): array => $dto->receiptIds, $this->manager->client->consumed());
    }

    /**
     * @param ?string $receiptId Optional receipt
     * @param string $acceptKey Connection address
     * @param string $agentId Agent address
     * @return DaemonAgentMessageDTO Binary message on the existing inner wire
     */
    private function binary(
        ?string $receiptId,
        string $acceptKey = 'socket',
        string $agentId = WorkerUploadReceiptAgent::AGENT_TYPE,
    ): DaemonAgentMessageDTO {
        return new DaemonAgentMessageDTO($agentId, new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::FRAME_BINARY),
            new SignalName(SignalTypeConstants::FRAME_BINARY),
            new WebSocketFrameBinarySignalDTO($acceptKey, "raw\0\xff"),
        ), $receiptId);
    }

    /** @param string $acceptKey Connection opening a real source wait */
    private function subscribe(string $acceptKey): void
    {
        $this->manager->handleDaemonMessage(new DaemonAgentMessageDTO(WorkerUploadReceiptAgent::AGENT_TYPE, new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::PAGE_SUBSCRIBE),
            new SignalName(WorkerUploadReceiptReadingPage::PAGE),
            new WebSocketPageSubscribeSignalDTO($acceptKey, WorkerUploadReceiptReadingPage::PAGE),
        )));
    }

    /**
     * @param string $acceptKey Departing connection
     * @return DaemonAgentMessageDTO Ordinary close, which finally drops its page's parked queue
     */
    private function close(string $acceptKey): DaemonAgentMessageDTO
    {
        return new DaemonAgentMessageDTO(WorkerUploadReceiptAgent::AGENT_TYPE, new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::CONNECTION_CLOSE),
            new SignalName(SignalTypeConstants::CONNECTION_CLOSE),
            new WebSocketCloseSignalDTO($acceptKey),
        ));
    }
}

/** Only fixture topology is mounted; no new production agent, routes or source claims. */
abstract class WorkerUploadReceiptHilos extends Hilos
{
    public const array AGENTS = [
        WorkerUploadReceiptAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => WorkerUploadReceiptAgent::class],
        WorkerUploadReceiptWaitingAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => WorkerUploadReceiptWaitingAgent::class],
    ];

    public const array PAGES = [
        WorkerUploadReceiptPage::PAGE => WorkerUploadReceiptPage::class,
        WorkerUploadReceiptReadingPage::PAGE => WorkerUploadReceiptReadingPage::class,
    ];
}

/** Real dispatch and one loop pass, with only the socket and process lifetime substituted. */
final class WorkerUploadReceiptManager extends WorkerManager
{
    public readonly WorkerUploadReceiptDaemonClient $client;
    public ?Closure $duringHandler = null;
    public ?string $failingStage = null;
    /** @var list<string> Binary callback order */
    public array $events = [];
    /** @var list<string> Receipt flush relative to the worker tick */
    public array $loopEvents = [];
    /** @var list<ContainedFailure> Failures contained by the real loop */
    public array $failures = [];

    /**
     * Builds the worker fixture with its process-local recording channel.
     */
    public function __construct()
    {
        parent::__construct(1);
        $this->client = new WorkerUploadReceiptDaemonClient($this);
        $this->daemonClient = $this->client;
    }

    /**
     * @param string $stage Callback that is executing
     * @param WebSocketFrameBinarySignalDTO $data Handled portion
     * @throws RuntimeException When the case fails this callback
     */
    public function record(string $stage, WebSocketFrameBinarySignalDTO $data): void
    {
        $this->events[] = $stage . ':' . $data->acceptKey;
        ($this->duringHandler)?->__invoke();
        if ($this->failingStage === $stage) {
            throw new RuntimeException('binary handler failed');
        }
    }

    /** @param float $now Release pass time */
    public function pass(float $now): void
    {
        $this->releaseParkedFrames($now);
    }

    /**
     * @return SignalRouter Empty worker fixture router
     */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    /**
     * @return AgentManager Factory for the two fixture agents
     */
    protected function createAgentManager(): AgentManager
    {
        return new WorkerUploadReceiptAgentManager($this);
    }

    /**
     * @param AgentInterface $agent Agent serving the fixture pages
     * @return PageSignalRouter Real page dispatcher with its binary route
     */
    protected function createPageSignalRouter(AgentInterface $agent): PageSignalRouter
    {
        return new PageSignalRouter(
            new WorkerUploadReceiptPageFactory($agent, $this),
            new ActionRouteConfig(),
            new SignalRouteConfig([SignalTypeConstants::FRAME_BINARY => WorkerUploadReceiptPage::PAGE]),
        );
    }

    /**
     * @param WebSocketFrameBinarySignalDTO $data Handled raw portion
     * @param string $source Signal source
     * @param string $name Signal name
     * @throws RuntimeException When the case fails this callback
     */
    protected function onFrameBinaryHandled(WebSocketFrameBinarySignalDTO $data, string $source, string $name): void
    {
        $this->record('worker', $data);
    }

    /**
     * Keeps the existing scripted daemon channel for this one-pass fixture.
     */
    protected function connectToDaemon(): void
    {
    }

    /**
     * Records that receipt flushing has finished before the project tick.
     */
    protected function onTick(): void
    {
        $this->loopEvents[] = 'tick';
    }

    /**
     * @param ContainedFailure $failure Failure contained by the real worker loop
     */
    protected function onTickFailure(ContainedFailure $failure): void
    {
        $this->failures[] = $failure;
    }

    /**
     * Keeps PHPUnit in charge of errors during the fixture loop.
     */
    protected function setupErrorHandling(): void
    {
    }

    /**
     * Keeps process signal ownership with the test runner.
     */
    protected function setupSignalHandlers(): void
    {
    }

    /**
     * @param float $loopStartTime Pass time; no supervisor is needed by this fixture
     */
    protected function checkDaemonLiveness(float $loopStartTime): void
    {
    }

    /**
     * @param float $loopStartTime Pass time
     * @param int $targetLoopTimeMicroseconds Unused pacing target; this fixture exits after one pass
     */
    protected function sleepWithPreciseTiming(float $loopStartTime, int $targetLoopTimeMicroseconds = 10000): void
    {
        $this->shouldExit = true;
    }

    /**
     * Leaves recorded sends and failures available to the assertions.
     */
    protected function cleanup(): void
    {
    }
}

/** Real JSON decoder and message queue, with no network operations. */
final class WorkerUploadReceiptDaemonClient extends WorkerDaemonClient
{
    /** @var list<WorkerDTO|array<string, mixed>> Outgoing metadata frames */
    public array $sent = [];

    /** @param WorkerUploadReceiptManager $manager Event recorder */
    public function __construct(private readonly WorkerUploadReceiptManager $manager)
    {
    }

    /** @param WorkerDTO $message Wire frame to enqueue */
    public function parse(WorkerDTO $message): void
    {
        $parse = Closure::bind(static function (WorkerDaemonClient $client, string $wire): void {
            $client->readBuffer .= $wire . "\n";
            $client->processReadBuffer();
        }, null, WorkerDaemonClient::class);
        $parse($this, $message->toJson());
    }

    /**
     * @return bool True for the in-process daemon channel
     */
    public function isConnected(): bool
    {
        return true;
    }

    /**
     * Leaves the real decoder queue intact for the fixture loop to drain.
     */
    public function read(): void
    {
    }

    /**
     * Keeps the outgoing frames in the fixture recorder.
     */
    public function write(): void
    {
    }

    /** @param WorkerDTO|array<string, mixed> $data Worker transport frame */
    public function send(WorkerDTO|array $data): void
    {
        $this->sent[] = $data;
        if ($data instanceof WorkerUploadConsumedDTO) {
            $this->manager->loopEvents[] = 'receipts';
        }
    }

    /** @return list<WorkerUploadConsumedDTO> Only the consumed control frames */
    public function consumed(): array
    {
        return array_values(array_filter($this->sent, static fn (WorkerDTO|array $dto): bool => $dto instanceof WorkerUploadConsumedDTO));
    }
}

final class WorkerUploadReceiptAgentManager extends AgentManager
{
    /** @param WorkerUploadReceiptManager $manager Callback recorder */
    public function __construct(private readonly WorkerUploadReceiptManager $manager)
    {
    }

    /**
     * @param string $agentType One of the two fixture agent types
     * @param ?string $agentIndex Unused singleton index
     * @return AgentInterface Live receiver or source-waiting receiver
     */
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        return $agentType === WorkerUploadReceiptWaitingAgent::AGENT_TYPE
            ? new WorkerUploadReceiptWaitingAgent($this->manager)
            : new WorkerUploadReceiptAgent($this->manager);
    }
}

class WorkerUploadReceiptAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_upload_receipts';

    /** @param WorkerUploadReceiptManager $manager Callback recorder */
    public function __construct(private readonly WorkerUploadReceiptManager $manager)
    {
    }

    /**
     * @param WebSocketFrameBinarySignalDTO $data Portion reaching this callback
     * @param string $source Signal source
     * @param string $name Signal name
     * @throws RuntimeException When the case fails this callback
     */
    public function onSignalFrameBinary(WebSocketFrameBinarySignalDTO $data, string $source, string $name): void
    {
        $this->manager->record('agent', $data);
    }

    /**
     * No resources are owned by the fixture agent.
     */
    public function onStop(): void
    {
    }
}

/** A real agent start that waits for withheld sources and is refused on its deadline. */
final class WorkerUploadReceiptWaitingAgent extends WorkerUploadReceiptAgent
{
    public const string AGENT_TYPE = 'unit_upload_receipt_waiting';
    public const string COLLECTION = 'unitUploadReceiptStartRows';
    public const array READS_RT = [self::COLLECTION];
}

/** @extends AbstractPageFactory<AgentInterface> */
final class WorkerUploadReceiptPageFactory extends AbstractPageFactory
{
    /**
     * @param AgentInterface $agent Fixture agent
     * @param WorkerUploadReceiptManager $manager Callback recorder
     */
    public function __construct(AgentInterface $agent, private readonly WorkerUploadReceiptManager $manager)
    {
        parent::__construct($agent);
    }

    /**
     * @param string $pageName Fixture page address
     * @return AbstractPage Binary receiver or source-reading page
     * @throws PageNotFoundException When the case names an unknown fixture page
     */
    protected function createPage(string $pageName): AbstractPage
    {
        return match ($pageName) {
            WorkerUploadReceiptPage::PAGE => new WorkerUploadReceiptPage($this->agent, $this->manager),
            WorkerUploadReceiptReadingPage::PAGE => new WorkerUploadReceiptReadingPage($this->agent, $this->manager),
            default => throw new PageNotFoundException($pageName),
        };
    }

    /**
     * @param string $pageName Page address to check
     * @return bool Whether the fixture factory serves this page
     */
    public function hasPage(string $pageName): bool
    {
        return in_array($pageName, [WorkerUploadReceiptPage::PAGE, WorkerUploadReceiptReadingPage::PAGE], true);
    }
}

class WorkerUploadReceiptPage extends AbstractPage
{
    public const string PAGE = 'unit_upload_receipts';

    /**
     * @param AgentInterface $agent Fixture agent
     * @param WorkerUploadReceiptManager $manager Callback recorder
     */
    public function __construct(AgentInterface $agent, private readonly WorkerUploadReceiptManager $manager)
    {
        parent::__construct($agent);
    }

    /**
     * @param WebSocketFrameBinarySignalDTO $data Portion reaching this callback
     * @param string $source Signal source
     * @param string $name Signal name
     * @throws RuntimeException When the case fails this callback
     */
    public function onSignalFrameBinary(WebSocketFrameBinarySignalDTO $data, string $source, string $name): void
    {
        $this->manager->record('page', $data);
    }
}

final class WorkerUploadReceiptReadingPage extends WorkerUploadReceiptPage
{
    public const string PAGE = 'unit_upload_receipt_reading';
    public const string COLLECTION = 'unitUploadReceiptRows';
    public const array READS_RT = [self::COLLECTION];
}

/** Uses real registration and consumed handlers, without a socket. */
final class WorkerUploadReceiptReceivingClient extends WorkerClient
{
    /**
     * Builds a socket-free fixture client using the real registration handler.
     */
    public function __construct()
    {
    }

    /** @param WorkerDTO $frame Incoming worker control frame */
    public function receive(WorkerDTO $frame): void
    {
        $this->receiveJson($frame->toJson());
    }

    /** @param string $wire Incoming JSON, including untrusted extra fields */
    public function receiveJson(string $wire): void
    {
        $this->readBuffer = $wire . "\n";
        $this->processReadBuffer();
    }
}

/** Test-bound seam over the real pure ledger; production mapping and cleanup belong to slice 8. */
final class WorkerUploadReceiptLedgerSink implements UploadMemoryPoolSink
{
    /** @var list<WorkerClient> Registered sources received by the sink */
    public array $workers = [];

    /** @var array<int, string> Channel incarnations by actual client object */
    private array $channels = [];

    /** @param UploadMemoryPool $pool Pure master-private metadata ledger */
    public function __construct(private readonly UploadMemoryPool $pool)
    {
    }

    /**
     * @param string $acceptKey Not accepted by this worker-only fixture
     * @return UploadMemoryInput Never returns a WebSocket input
     * @throws LogicException When a worker test accidentally requests socket admission
     */
    public function getUploadInput(string $acceptKey): UploadMemoryInput
    {
        throw new LogicException('Worker-only fixture has no WebSocket input');
    }

    /**
     * @param WebSocketFrameBinarySignalDTO $frame Unexpected WebSocket payload
     * @param UploadMemoryReceipt $receipt Unexpected admission
     * @throws LogicException When a worker test accidentally queues a WebSocket payload
     */
    public function onUploadFrameQueued(
        WebSocketFrameBinarySignalDTO $frame,
        UploadMemoryReceipt $receipt,
    ): void {
        throw new LogicException('Worker-only fixture cannot queue WebSocket bytes');
    }

    /** @param string $acceptKey WebSocket cleanup is unused by this fixture */
    public function onUploadConnectionClosed(string $acceptKey): void
    {
    }

    /**
     * @param WorkerClient $worker Real channel object
     * @param string $channel Unique destination incarnation
     */
    public function bind(WorkerClient $worker, string $channel): void
    {
        $this->channels[spl_object_id($worker)] = $channel;
    }

    /**
     * @param WorkerClient $worker Reporting channel
     * @param int $memoryPoolBytes Effective ledger limit
     */
    public function onUploadSettings(WorkerClient $worker, int $memoryPoolBytes): void
    {
        $this->pool->setLimitBytes($memoryPoolBytes);
    }

    /**
     * @param WorkerClient $worker Actual registered source object
     * @param list<string> $receiptIds Terminal facts to settle against its ledger channel
     */
    public function onUploadConsumed(WorkerClient $worker, array $receiptIds): void
    {
        $this->workers[] = $worker;
        $channel = $this->channels[spl_object_id($worker)] ?? null;
        if ($channel === null) {
            return;
        }
        foreach ($receiptIds as $receiptId) {
            $this->pool->settle($receiptId, $channel);
        }
    }
}

/** Exercises read/getNextMessage on a real local socket without another process. */
final class WorkerUploadReceiptSocketClient extends WorkerDaemonClient
{
    /** @param Socket $socket Connected nonblocking worker end */
    public function adopt(Socket $socket): void
    {
        $this->socket = $socket;
        $this->state = DaemonConnectionState::CONNECTED;
    }
}
