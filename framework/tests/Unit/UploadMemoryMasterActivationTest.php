<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Cluster\Peer\PeerLink;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerSignalDTO;
use Hilos\Cluster\Peer\DTO\PeerUploadReserveDTO;
use Hilos\Cluster\Peer\DTO\PeerUploadGrantedDTO;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Feature\Definition\UploadsFeature;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Process;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\Destination\AgentAddressedDestination;
use Hilos\Core\Router\Destination\RemoteAgentDestination;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Environment\EnvAccessor;
use Hilos\Files\Upload\DTO\UploadCreditRequestSignalData;
use Hilos\Files\Upload\DTO\UploadCreditSignalData;
use Hilos\Files\Upload\DTO\UploadStateSignalData;
use Hilos\Files\Upload\UploadFrame;
use Hilos\Files\Upload\UploadPhase;
use Hilos\Files\Upload\UploadsAgent;
use Hilos\Socket\Client\AbstractClient;
use Hilos\Socket\Server\AbstractServer;
use Hilos\Socket\WebSocket\DTO\WebSocketFrameBinarySignalDTO;
use Hilos\Files\Upload\UploadMemoryMaster;
use Hilos\Files\Upload\UploadMemoryPool;
use Hilos\Hilos;
use Hilos\Runtime\RtSyncApplicator;
use Hilos\Runtime\RtStaleness;
use Hilos\Runtime\State\Item\HilosUpload as StateHilosUpload;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Server\WebSocketServer;
use Hilos\Socket\Client\Interface\WebSocketClientInterface;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerUploadConsumedDTO;
use Hilos\Socket\Worker\DTO\WorkerUploadSettingsDTO;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\Socket\WebSocket\Exception\InvalidFrameException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use SplQueue;

/** Actual daemon drain, agent-start hold and WorkerServer delivery share one production ledger. */
final class UploadMemoryMasterActivationTest extends TestCase
{
    private string $previousApp;
    private ?RtContext $previousRt;
    private ?SignalRouter $previousRouter;
    private ?EnvAccessor $previousEnv;
    private UploadMemoryActivationManager $manager;
    private UploadMemoryMaster $memory;
    private UploadMemoryPool $pool;

    protected function setUp(): void
    {
        $this->previousApp = Hilos::appClass();
        $this->previousRt = Hilos::$rt;
        $this->previousRouter = Hilos::$sr;
        $this->previousEnv = Hilos::$env;
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, UploadMemoryActivationApp::class);
        Hilos::$env = new EnvAccessor();
        Hilos::$rt = new UploadMemoryActivationContext();
        Hilos::$rt->mountFeatureRuntime([new UploadsFeature()]);
        $this->manager = new UploadMemoryActivationManager();
        $this->memory = new ReflectionProperty(DaemonManager::class, 'uploadMemory')->getValue($this->manager);
        $this->pool = new ReflectionProperty(UploadMemoryMaster::class, 'pool')->getValue($this->memory);
    }

    protected function tearDown(): void
    {
        Hilos::$rt = $this->previousRt;
        Hilos::$sr = $this->previousRouter;
        Hilos::$env = $this->previousEnv;
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousApp);
    }

    /** One live socket cannot retain an unbounded set of requests for uploads it never initialized. */
    public function testUnknownCreditRequestsRetainOnlyTheExistingConnectionBound(): void
    {
        $client = WebSocketClientTestProbe::createSocketless();
        $client->setUploadMemoryPoolSink($this->memory);
        new ReflectionProperty($client, 'handshakeCompleted')->setValue($client, true);
        new ReflectionProperty($client, 'acceptKey')->setValue($client, 'flood');
        $rejected = [];
        for ($index = 0; $index < 320; $index++) {
            new ReflectionMethod($client, 'onFrame')->invoke($client, json_encode([
                'type' => SignalTypeConstants::UPLOAD_CREDIT_REQUEST,
                'clientUploadId' => 'unknown_' . $index, 'bytes' => 1,
            ], JSON_THROW_ON_ERROR));
            $request = Hilos::$sr->getNextQueuedSignal();
            self::assertInstanceOf(UploadCreditRequestSignalData::class, $request->data);
            self::assertSame('flood', $request->data->acceptKey);
            $this->memory->requestCredit($request->data);
            while (($reply = Hilos::$sr->getNextQueuedSignal()) !== null) {
                self::assertSame(HilosSignalConstants::HILOS_UPLOAD_STATE, $reply->signalName->getName());
                self::assertInstanceOf(WebSocketSignalData::class, $reply->data);
                self::assertSame('flood', $reply->data->targetAcceptKey);
                self::assertInstanceOf(UploadStateSignalData::class, $reply->data->data);
                self::assertNull($reply->data->data->phase);
                $rejected[] = $reply->data->data->clientUploadId;
            }
        }
        $demands = new ReflectionProperty(UploadMemoryMaster::class, 'demands')->getValue($this->memory);
        self::assertCount(UploadsAgent::MAX_OPEN_UPLOADS_PER_CONNECTION, $demands['flood'],
            '320 unknown requests must retain only the 16 existing upload slots');
        self::assertSame(array_map(static fn (int $id): string => 'unknown_' . $id, range(16, 319)), $rejected);
        self::assertSame(0, $this->pool->getUsedBytes());
        for ($pass = 0; $pass < 3; $pass++) {
            $this->memory->onUploadsChanged();
            $this->memory->afterDispatch();
            self::assertFalse(new ReflectionProperty(UploadMemoryMaster::class, 'needsWake')->getValue($this->memory));
            self::assertSame($demands, new ReflectionProperty(UploadMemoryMaster::class, 'demands')->getValue($this->memory));
            self::assertSame([], $this->takeCredits());
        }
        $this->memory->getUploadInput('flood')->close();
        $this->memory->onUploadConnectionClosed('flood');
        self::assertSame([], new ReflectionProperty(UploadMemoryMaster::class, 'demands')->getValue($this->memory));
    }

    /** @return iterable<string, array{mixed}> The init grammar and strict input types also bind credit requests */
    public static function invalidCreditIds(): iterable
    {
        yield 'above signature limit' => [str_repeat('a', UploadFrame::MAX_ID_LENGTH + 1)];
        yield 'large retained string' => [str_repeat('a', 65536)];
        yield 'separator' => ['file|other'];
        yield 'whitespace' => ['file name'];
        yield 'newline' => ["file\n"];
        yield 'non ASCII' => ['файл'];
        yield 'empty' => [''];
        yield 'integer without coercion' => [123];
        yield 'boolean without coercion' => [false];
        yield 'array without coercion' => [['file']];
        yield 'null without coercion' => [null];
    }

    /** @param mixed $id Invalid wire value which must never reach the router or demand store */
    #[DataProvider('invalidCreditIds')]
    public function testInvalidCreditIdentityIsRefusedBeforeRouting(mixed $id): void
    {
        $client = WebSocketClientTestProbe::createSocketless();
        $client->setUploadMemoryPoolSink($this->memory);
        new ReflectionProperty($client, 'handshakeCompleted')->setValue($client, true);
        new ReflectionProperty($client, 'acceptKey')->setValue($client, 'actual');
        try {
            new ReflectionMethod($client, 'onFrame')->invoke($client, json_encode([
                'type' => SignalTypeConstants::UPLOAD_CREDIT_REQUEST,
                'clientUploadId' => $id, 'bytes' => 3,
            ], JSON_THROW_ON_ERROR));
            self::fail('Invalid upload identity entered the credit route');
        } catch (InvalidFrameException) {
            self::assertNull(Hilos::$sr->getNextQueuedSignal());
            self::assertSame([], new ReflectionProperty(UploadMemoryMaster::class, 'demands')->getValue($this->memory));
            self::assertSame(0, $this->pool->getUsedBytes());
        }
    }

    /** All legitimate late replicas fit; repeats and changed-size requests never take another slot. */
    public function testTheWholeConnectionBoundSurvivesLateDeclarationsAndRepeats(): void
    {
        $this->memory->getUploadInput('a');
        $this->memory->getUploadInput('b');
        for ($index = 0; $index < UploadsAgent::MAX_OPEN_UPLOADS_PER_CONNECTION; $index++) {
            for ($repeat = 0; $repeat < 5; $repeat++) {
                $this->memory->requestCredit(new UploadCreditRequestSignalData('a', 'late_' . $index, 2));
            }
        }
        $this->memory->requestCredit(new UploadCreditRequestSignalData('a', 'late_0', 3));
        $this->memory->requestCredit(new UploadCreditRequestSignalData('b', 'late_0', 7));
        $demands = new ReflectionProperty(UploadMemoryMaster::class, 'demands')->getValue($this->memory);
        self::assertCount(16, $demands['a']);
        self::assertCount(1, $demands['b']);
        self::assertSame(2, $demands['a']['late_0']->request->bytes);
        self::assertSame([], $this->takeCredits());
        self::assertSame(0, $this->pool->getUsedBytes());

        for ($index = 0; $index < UploadsAgent::MAX_OPEN_UPLOADS_PER_CONNECTION; $index++) {
            $this->declare('a', 'late_' . $index, 2);
        }
        $this->manager->drain();
        self::assertSame(0, $this->pool->getUsedBytes(), 'Declarations do not invent a missing settings report');
        $this->manager->workers->worker->receive(new WorkerUploadSettingsDTO(32));
        self::assertSame(array_map(static fn (int $id): array => ['a', 'late_' . $id, 2], range(0, 15)), $this->takeCredits());
        self::assertSame(32, $this->pool->getUsedBytes());
        self::assertNull($this->pool->getSendUpToBytes('b', 'late_0'), 'The same id on another socket is not declared');
        $this->memory->requestCredit(new UploadCreditRequestSignalData('a', 'late_0', 9));
        $this->memory->cancel('b', 'late_0');
        self::assertSame(2, $this->pool->getSendUpToBytes('a', 'late_0'));
        self::assertSame(32, $this->pool->getUsedBytes());
        self::assertSame([], $this->takeCredits());
    }

    /** A terminal phase applied in the current drain frees its old slot before another request is judged. */
    public function testFullMetadataAdmissionReconcilesAlreadyAppliedSourceChanges(): void
    {
        $this->memory->getUploadInput('a');
        for ($index = 0; $index < UploadsAgent::MAX_OPEN_UPLOADS_PER_CONNECTION; $index++) {
            $this->declare('a', 'old_' . $index, 1);
        }
        $this->manager->drain();
        for ($index = 0; $index < UploadsAgent::MAX_OPEN_UPLOADS_PER_CONNECTION; $index++) {
            $this->memory->requestCredit(new UploadCreditRequestSignalData('a', 'old_' . $index, 1));
        }
        self::assertTrue(new ReflectionProperty(UploadMemoryMaster::class, 'demands')->getValue($this->memory)['a']['old_0']->admitted);
        Hilos::$sr->queueSignal(new SignalSource(SignalSource::WORKER),
            new SignalType(SignalTypeConstants::RT_SYNC_UPDATED), new SignalName('rt_sync_updated'),
            new RtSyncUpdatedSignalData(StateHilosUpload::RT_COLLECTION, StateHilosUpload::keyFor('a', 'old_0'),
                [StateHilosUpload::phase => UploadPhase::FAILED->value]));
        $this->request('a', 'next', 1);
        $this->manager->drain();
        self::assertSame(UploadPhase::FAILED, Hilos::$rt->hilosUploads->find('a', 'old_0')->phase);
        $demands = new ReflectionProperty(UploadMemoryMaster::class, 'demands')->getValue($this->memory);
        self::assertCount(16, $demands['a']);
        self::assertArrayNotHasKey('old_0', $demands['a']);
        self::assertArrayHasKey('next', $demands['a'], 'A replacement keeps its one original request');
        $this->declare('a', 'next', 1);
        $this->manager->drain();
        $this->manager->workers->worker->receive(new WorkerUploadSettingsDTO(16));
        self::assertCount(16, $this->takeCredits());
        self::assertSame(1, $this->pool->getSendUpToBytes('a', 'next'));
    }

    /** Socket cleanup deletes waits, while already assigned bytes remain owed to the exact worker. */
    public function testWaitingMetadataCleanupPreservesOtherSocketsAndAcceptedDebt(): void
    {
        $this->startUpload('a', 'file', 5, 5);
        $this->queueBytes('a', 'file', 'abc');
        $this->manager->drain();
        $this->manager->started();
        $this->manager->drain();
        $worker = $this->manager->workers->worker;
        $receipt = $worker->messages()[0]->uploadReceiptId;
        $this->memory->getUploadInput('b');
        $this->memory->requestCredit(new UploadCreditRequestSignalData('b', 'file', 2));
        for ($index = 0; $index < 32; $index++) {
            $this->memory->requestCredit(new UploadCreditRequestSignalData('a', 'wait_' . $index, 1));
            $this->memory->cancel('a', 'wait_' . $index);
        }
        $this->memory->requestCredit(new UploadCreditRequestSignalData('a', 'last_wait', 1));
        $this->memory->getUploadInput('a')->close();
        $this->memory->onUploadConnectionClosed('a');
        $demands = new ReflectionProperty(UploadMemoryMaster::class, 'demands')->getValue($this->memory);
        self::assertSame(['b'], array_keys($demands));
        self::assertSame(['file'], array_keys($demands['b']));
        self::assertSame(3, $this->pool->getUsedBytes(), 'Closing releases only the two unused bytes');
        $worker->receive(new WorkerUploadConsumedDTO([$receipt]));
        self::assertSame(0, $this->pool->getUsedBytes());
        self::assertSame([], $this->takeCredits());
        $this->declare('b', 'file', 2);
        $this->manager->drain();
        self::assertSame(2, $this->pool->getUsedBytes());
        self::assertSame(2, $this->pool->getSendUpToBytes('b', 'file'));
        $this->memory->cancel('b', 'file');
        self::assertSame([], new ReflectionProperty(UploadMemoryMaster::class, 'demands')->getValue($this->memory));
        self::assertSame(0, $this->pool->getUsedBytes());
    }

    public function testSuspensionLateDeclarationAndSettingsReportsWakeTheActualServiceQueue(): void
    {
        $this->memory->getUploadInput('socket-a');
        $this->request('socket-a', 'first', 8);
        $this->manager->drain();
        self::assertSame(0, $this->pool->getUsedBytes());
        self::assertSame([], $this->takeCredits());

        $worker = $this->manager->workers->worker;
        $worker->receive(new WorkerUploadSettingsDTO(3));
        self::assertSame(3, $this->pool->getLimitBytes());
        self::assertSame(0, $this->pool->getUsedBytes(), 'A setting is not an upload declaration');
        $this->declare('socket-a', 'first', 8);
        $this->manager->drain();
        self::assertSame(3, $this->pool->getUsedBytes());
        self::assertSame(3, $this->pool->getSendUpToBytes('socket-a', 'first'));

        $this->request('socket-a', 'first', 8);
        $this->manager->drain();
        self::assertSame(3, $this->pool->getUsedBytes(), 'A repeat never makes a second request');
        $worker->receive(new WorkerUploadSettingsDTO(1));
        self::assertSame(3, $this->pool->getUsedBytes(), 'Shrink preserves published windows');
        $worker->receive(new WorkerUploadSettingsDTO(8));
        $credits = $this->takeCredits();
        self::assertSame([['socket-a', 'first', 8]], $credits);
        self::assertSame(8, $this->pool->getUsedBytes());
    }

    public function testFifoPartialWindowsAndOnlyTheExactWorkerCanSettle(): void
    {
        $this->memory->getUploadInput('a');
        $this->memory->getUploadInput('b');
        $this->declare('a', 'one', 5);
        $this->declare('b', 'two', 2);
        $this->manager->drain();
        $this->request('a', 'one', 5);
        $this->request('b', 'two', 2);
        $this->manager->drain();
        $worker = $this->manager->workers->worker;
        $worker->receive(new WorkerUploadSettingsDTO(3));
        self::assertSame([['a', 'one', 3]], $this->takeCredits());

        $this->queueBytes('a', 'one', 'abc');
        $this->manager->drain();
        self::assertSame(3, $this->pool->getUsedBytes(), 'The real agent-start hold retains raw bytes');
        $this->manager->started();
        $this->manager->drain();
        $wire = $worker->messages();
        self::assertCount(1, $wire);
        self::assertNotNull($wire[0]->uploadReceiptId);
        self::assertSame(3, $this->pool->getUsedBytes(), 'Serializing into the actual worker write buffer releases nothing');

        $foreign = new UploadMemoryActivationWorker($this->manager->roster(), $this->manager->workers);
        $foreign->setUploadMemoryPoolSink($this->memory);
        $foreign->receive(new WorkerUploadConsumedDTO([$wire[0]->uploadReceiptId]));
        self::assertSame(3, $this->pool->getUsedBytes(), 'A replacement with the same worker index is foreign');
        $worker->receive(new WorkerUploadConsumedDTO(['stale:receipt', $wire[0]->uploadReceiptId, $wire[0]->uploadReceiptId]));
        self::assertSame([['b', 'two', 2], ['a', 'one', 4]], $this->takeCredits());
        self::assertSame(3, $this->pool->getUsedBytes());
    }

    public function testFailedStartDropsPayloadBeforePendingBytesAreReleased(): void
    {
        $this->startUpload('a', 'file', 5, 5);
        $this->queueBytes('a', 'file', 'abc');
        $this->manager->drain();
        $this->memory->cancel('a', 'file');
        self::assertSame(3, $this->pool->getUsedBytes());
        $this->manager->onAgentStartFailed(HilosAgentType::HILOS_UPLOADS, 'unit refusal');
        self::assertSame(3, $this->pool->getUsedBytes(), 'No release inside a stack that still held the raw frame');
        $this->manager->drain();
        self::assertSame(0, $this->pool->getUsedBytes());
    }

    public function testSocketCloseKeepsMasterHeldAndWorkerAssignedBytes(): void
    {
        $this->startUpload('a', 'file', 5, 5);
        $this->queueBytes('a', 'file', 'abc');
        $this->manager->drain();
        $this->memory->getUploadInput('a')->close();
        $this->memory->onUploadConnectionClosed('a');
        $this->manager->drain();
        self::assertSame(3, $this->pool->getUsedBytes());
        $this->manager->started();
        $this->manager->drain();
        self::assertNotNull($this->manager->workers->worker->messages()[0]->uploadReceiptId);
        self::assertSame(3, $this->pool->getUsedBytes());
    }

    /** @return iterable<string, array{bool}> Both orders of the independent lifecycle facts */
    public static function exitOrders(): iterable
    {
        yield 'EOF first' => [true];
        yield 'process exit first' => [false];
    }

    #[DataProvider('exitOrders')]
    public function testOnlyProcessExitTogetherWithQueueDeletionDiscardsTheChannel(bool $eofFirst): void
    {
        $this->startUpload('a', 'file', 3, 3);
        $this->queueBytes('a', 'file', 'abc');
        $this->manager->drain();
        $this->manager->started();
        $this->manager->drain();
        $worker = $this->manager->workers->worker;
        self::assertTrue($worker->hasPendingWrite());
        if ($eofFirst) {
            $this->manager->workers->dropClient($worker);
            self::assertFalse($worker->hasPendingWrite());
            self::assertSame(3, $this->pool->getUsedBytes(), 'EOF alone does not prove process exit');
            $this->manager->workers->reportExit();
        } else {
            $this->manager->workers->reportExit();
            self::assertSame(3, $this->pool->getUsedBytes(), 'Process exit alone does not delete queued IPC');
            $this->manager->workers->dropClient($worker);
        }
        self::assertSame(0, $this->pool->getUsedBytes());
        $this->manager->workers->reportExit();
        self::assertSame(0, $this->pool->getUsedBytes(), 'A repeated exit cannot settle twice');
    }

    public function testWrongSizeAndZeroSizeNeverReserve(): void
    {
        $this->memory->getUploadInput('a');
        $this->declare('a', 'file', 3);
        $this->manager->drain();
        $this->manager->workers->worker->receive(new WorkerUploadSettingsDTO(10));
        $this->request('a', 'file', 4);
        $this->request('a', 'empty', 0);
        $this->manager->drain();
        self::assertSame(0, $this->pool->getUsedBytes());
        self::assertSame([], $this->takeCredits());
    }

    public function testTheTwoRegisteredServersReceiveTheSameInitiallySuspendedOwner(): void
    {
        $server = new class('127.0.0.1', 0) extends WebSocketServer {
            protected function onCreateClient($socket): WebSocketClientInterface
            {
                return WebSocketClientTestProbe::createSocketless();
            }
            protected function onStart(): void
            {
            }
        };
        $this->manager->registerServer($server);
        self::assertSame($this->memory, new ReflectionProperty(WebSocketServer::class, 'uploadMemoryPoolSink')->getValue($server));
        self::assertSame($this->memory, new ReflectionProperty(WorkerServer::class, 'uploadMemoryPoolSink')->getValue($this->manager->workers));
        self::assertSame(0, $this->pool->getLimitBytes());
    }

    public function testAStaleDeclarationWaitsForTheActualFreshnessFact(): void
    {
        $this->memory->getUploadInput('a');
        $this->declare('a', 'file', 3);
        $this->manager->drain();
        $rowId = StateHilosUpload::keyFor('a', 'file');
        RtStaleness::mark(StateHilosUpload::RT_COLLECTION, [$rowId], microtime(true));
        try {
            $this->manager->workers->worker->receive(new WorkerUploadSettingsDTO(3));
            $this->request('a', 'file', 3);
            $this->manager->drain();
            self::assertSame(0, $this->pool->getUsedBytes());
            new ReflectionMethod(DaemonManager::class, 'clearStaleness')->invoke(
                $this->manager, StateHilosUpload::RT_COLLECTION, [$rowId],
            );
            $this->manager->drain();
            self::assertSame(3, $this->pool->getUsedBytes());
        } finally {
            RtStaleness::clear(StateHilosUpload::RT_COLLECTION, [$rowId]);
        }
    }

    public function testAnUnroutedPayloadReleasesOnlyAfterTheRouterDropsItsLastReference(): void
    {
        $this->startUpload('a', 'file', 5, 5);
        Hilos::$sr = new SignalRouter();
        $this->queueBytes('a', 'file', 'abc');
        $this->memory->cancel('a', 'file');
        self::assertSame(3, $this->pool->getUsedBytes());
        $this->manager->drain();
        self::assertSame(0, $this->pool->getUsedBytes());
    }

    public function testAStaleCapacityWaitDoesNotHoldTheHealthyUploadBehindIt(): void
    {
        $this->startUpload('a', 'first', 6, 3);
        $this->memory->getUploadInput('b');
        $this->declare('b', 'second', 2);
        $this->request('b', 'second', 2);
        $this->manager->drain();
        $this->queueBytes('a', 'first', 'abc');
        $this->manager->drain();
        $this->manager->started();
        $this->manager->drain();
        $receipt = $this->manager->workers->worker->messages()[0]->uploadReceiptId;
        $rowId = StateHilosUpload::keyFor('a', 'first');
        RtStaleness::mark(StateHilosUpload::RT_COLLECTION, [$rowId], microtime(true));
        try {
            $this->manager->workers->worker->receive(new WorkerUploadConsumedDTO([$receipt]));
            self::assertSame([['b', 'second', 2]], $this->takeCredits());
            self::assertSame(2, $this->pool->getUsedBytes());
            self::assertSame(3, $this->pool->getSendUpToBytes('a', 'first'));
        } finally {
            RtStaleness::clear(StateHilosUpload::RT_COLLECTION, [$rowId]);
        }
    }

    public function testAReusedWorkerIndexCannotSubstituteTheProcessExitFact(): void
    {
        $this->startUpload('a', 'file', 3, 3);
        $this->queueBytes('a', 'file', 'abc');
        $this->manager->drain();
        $this->manager->started();
        $this->manager->drain();
        $this->manager->workers->dropClient($this->manager->workers->worker);
        $foreignProcess = $this->createMock(Process::class);
        $foreignProcess->method('getStatus')->willReturn([Process::STATUS_RUNNING => false]);
        $foreignProcess->method('getStdOut')->willReturn('');
        $foreignProcess->method('getStdErr')->willReturn('');
        new ReflectionMethod(WorkerServer::class, 'tickWorkerProcess')->invoke(
            $this->manager->workers, $foreignProcess, WorkerConstants::TYPE_REGULAR, 1, 'regular:1',
        );
        self::assertSame(3, $this->pool->getUsedBytes());
        $this->manager->workers->reportExit();
        self::assertSame(0, $this->pool->getUsedBytes());
    }

    public function testCreditDtoRoundTripsAndServerIgnoresClientSuppliedAcceptKey(): void
    {
        $request = new UploadCreditRequestSignalData('actual', 'file', 3);
        self::assertEquals($request, UploadCreditRequestSignalData::fromArray($request->toArray()));
        $credit = new UploadCreditSignalData('file', 3);
        self::assertEquals($credit, UploadCreditSignalData::fromArray($credit->toArray()));
        $client = WebSocketClientTestProbe::createSocketless();
        $client->setUploadMemoryPoolSink($this->memory);
        new ReflectionProperty($client, 'handshakeCompleted')->setValue($client, true);
        new ReflectionProperty($client, 'acceptKey')->setValue($client, 'actual');
        new ReflectionMethod($client, 'onFrame')->invoke($client, json_encode([
            'type' => SignalTypeConstants::UPLOAD_CREDIT_REQUEST,
            'acceptKey' => 'spoofed', 'clientUploadId' => 'file', 'bytes' => 3,
        ], JSON_THROW_ON_ERROR));
        $queued = Hilos::$sr->getNextQueuedSignal();
        self::assertInstanceOf(UploadCreditRequestSignalData::class, $queued->data);
        self::assertSame('actual', $queued->data->acceptKey);
        self::assertSame(SignalSource::WEBSOCKET, $queued->signalSource->getSource());
    }

    public function testARemoteOwnerWaitsWithMetadataWithoutPublishingOrAcceptingRawBytes(): void
    {
        $this->memory->getUploadInput('a');
        $this->declare('a', 'file', 3);
        $this->manager->drain();
        Hilos::$sr = new class extends SignalRouter {
            public function placeAgentDestination(AgentAddressedDestination $destination): AgentAddressedDestination
            {
                return new RemoteAgentDestination('node-b', $destination->agentType, $destination->agentIndex);
            }
        };
        $this->manager->workers->worker->receive(new WorkerUploadSettingsDTO(3));
        $this->request('a', 'file', 3);
        $this->manager->drain();
        self::assertSame(0, $this->pool->getUsedBytes());
        self::assertNull($this->pool->getSendUpToBytes('a', 'file'));
        self::assertSame([], $this->takeCredits());
        $this->expectException(InvalidFrameException::class);
        $this->queueBytes('a', 'file', 'abc');
    }

    public function testRemoteBrowserWindowsSplitOneLogicalFrameAndPlacementChangeInterrupts(): void
    {
        $this->memory->getUploadInput('browser');
        $this->declare('browser', 'file', 5);
        $this->manager->drain();
        $this->manager->workers->worker->receive(new WorkerUploadSettingsDTO(8));
        $server = new ReflectionClass(PeerServer::class)->newInstanceWithoutConstructor();
        new ReflectionProperty(PeerServer::class, 'localIdentity')->setValue($server, NodeIdentity::of('A', NodeRole::Master, []));
        $link = new ReflectionClass(PeerLink::class)->newInstanceWithoutConstructor();
        new ReflectionProperty(PeerLink::class, 'outboundSegments')->setValue($link, new SplQueue());
        new ReflectionProperty(PeerLink::class, 'stagedOutbound')->setValue($link, new SplQueue());
        $link->activate(NodeIdentity::of('B', NodeRole::Master, []));
        new ReflectionProperty(AbstractServer::class, 'clients')->setValue($server, [$link]);
        new ReflectionProperty(PeerServer::class, 'activeLinks')->setValue($server, ['B' => $link]);
        $server->setUploadMemoryMaster($this->memory);
        $router = new class extends SignalRouter {
            public string $owner = 'B';
            public function placeAgentDestination(AgentAddressedDestination $destination): AgentAddressedDestination
            {
                return new RemoteAgentDestination($this->owner, $destination->agentType, $destination->agentIndex);
            }
        };
        Hilos::$sr = $router;
        $this->memory->requestCredit(new UploadCreditRequestSignalData('browser', 'file', 5));
        self::assertSame([], $this->takeCredits(), 'No browser permission before the receiver grant');
        $buffer = new ReflectionProperty(AbstractClient::class, 'writeBuffer');
        $first = PeerDTO::fromWire(trim($buffer->getValue($link)));
        self::assertInstanceOf(PeerUploadReserveDTO::class, $first);
        self::assertSame(5, $first->bytes);
        $this->memory->onPeerControl($link, new PeerUploadGrantedDTO($first->reservationId, 3));
        $this->memory->afterDispatch();
        self::assertSame([['browser', 'file', 3]], $this->takeCredits());
        $controls = array_map(PeerDTO::fromWire(...), array_filter(explode("\n", trim($buffer->getValue($link)))));
        $requests = array_values(array_filter($controls, static fn(PeerDTO $frame): bool => $frame instanceof PeerUploadReserveDTO));
        self::assertCount(2, $requests);
        self::assertNotSame($requests[0]->reservationId, $requests[1]->reservationId);
        self::assertSame(2, $requests[1]->bytes);
        $this->memory->onPeerControl($link, new PeerUploadGrantedDTO($requests[1]->reservationId, 2));
        $this->memory->afterDispatch();
        self::assertSame([['browser', 'file', 5]], $this->takeCredits());
        $raw = "\x04fileABCDE";
        $this->memory->getUploadInput('browser')->observe($raw, strlen($raw));
        $receipt = $this->memory->getUploadInput('browser')->finishMessage();
        self::assertNotNull($receipt);
        $frame = new WebSocketFrameBinarySignalDTO('browser', $raw);
        $this->memory->onUploadFrameQueued($frame, $receipt);
        $signal = new SignalDTO(new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::FRAME_BINARY), new SignalName(SignalTypeConstants::FRAME_BINARY), $frame, ['trace' => 17]);
        self::assertTrue($server->sendSignalToNode('B', HilosAgentType::HILOS_UPLOADS, null, $signal));
        $frames = array_map(PeerDTO::fromWire(...), array_filter(explode("\n", trim($buffer->getValue($link)))));
        $portions = array_values(array_filter($frames, static fn(PeerDTO $frame): bool => $frame instanceof PeerSignalDTO));
        self::assertCount(2, $portions);
        self::assertSame(['ABC', 'DE'], array_map(static fn(PeerSignalDTO $part): string =>
            UploadFrame::parse($part->signal->data->payload)->bytes, $portions));
        self::assertSame([3, 2], array_map(static fn(PeerSignalDTO $part): int => $part->uploadReservation->bytes, $portions));
        self::assertNotSame($portions[0]->uploadReservation->receiptId, $portions[1]->uploadReservation->receiptId);
        foreach ($portions as $part) {
            self::assertSame(['trace' => 17], $part->signal->meta);
            self::assertSame(SignalSource::WEBSOCKET, $part->signal->signalSource->getSource());
            self::assertSame(HilosAgentType::HILOS_UPLOADS, $part->agentType);
            self::assertSame('browser', $part->signal->data->acceptKey);
            self::assertSame('file', UploadFrame::parse($part->signal->data->payload)->clientUploadId);
        }
        $router->owner = 'C';
        $this->memory->onUploadsChanged();
        $this->memory->afterDispatch();
        $gone = Hilos::$sr->getNextQueuedSignal();
        self::assertSame(HilosSignalConstants::HILOS_UPLOAD_STATE, $gone->signalName->getName());
        self::assertInstanceOf(UploadStateSignalData::class, $gone->data->data);
        self::assertSame('file', $gone->data->data->clientUploadId);
        self::assertNull($gone->data->data->phase);
        self::assertSame(5, $this->pool->getUsedBytes(), 'Placement change does not release still retained source portions');
    }

    public function testTheCreditServiceDoesNotAcceptAWorkerStampedBrowserDemand(): void
    {
        $this->memory->getUploadInput('a');
        $this->declare('a', 'file', 3);
        $this->manager->drain();
        $this->manager->workers->worker->receive(new WorkerUploadSettingsDTO(3));
        Hilos::$sr->queueSignal(
            new SignalSource(SignalSource::WORKER),
            new SignalType(SignalTypeConstants::UPLOAD_CREDIT_REQUEST),
            new SignalName(SignalTypeConstants::UPLOAD_CREDIT_REQUEST),
            new UploadCreditRequestSignalData('a', 'file', 3),
        );
        $this->manager->drain();
        self::assertSame(0, $this->pool->getUsedBytes());
    }

    private function startUpload(string $acceptKey, string $id, int $bytes, int $budget): void
    {
        $this->memory->getUploadInput($acceptKey);
        $this->declare($acceptKey, $id, $bytes);
        $this->manager->drain();
        $this->manager->workers->worker->receive(new WorkerUploadSettingsDTO($budget));
        $this->request($acceptKey, $id, $bytes);
        $this->manager->drain();
    }

    private function declare(string $acceptKey, string $id, int $bytes): void
    {
        $state = StateHilosUpload::create($acceptKey, $id, 'unit', null, 'a.bin', 'application/octet-stream', $bytes, 'unit', time());
        Hilos::$sr->queueSignal(
            new SignalSource(SignalSource::WORKER),
            new SignalType(SignalTypeConstants::RT_SYNC_CREATED),
            new SignalName('rt_sync_created'),
            new RtSyncCreatedSignalData(StateHilosUpload::RT_COLLECTION, $state->getId(), $state->toArray()),
        );
    }

    private function request(string $acceptKey, string $id, int $bytes): void
    {
        Hilos::$sr->queueSignal(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::UPLOAD_CREDIT_REQUEST),
            new SignalName(SignalTypeConstants::UPLOAD_CREDIT_REQUEST),
            new UploadCreditRequestSignalData($acceptKey, $id, $bytes),
        );
    }

    private function queueBytes(string $acceptKey, string $id, string $bytes): void
    {
        $client = WebSocketClientTestProbe::createSocketless();
        new ReflectionProperty($client, 'handshakeCompleted')->setValue($client, true);
        new ReflectionProperty($client, 'acceptKey')->setValue($client, $acceptKey);
        $client->setUploadMemoryPoolSink($this->memory);
        $payload = chr(strlen($id)) . $id . $bytes;
        $client->feed("\x82" . chr(strlen($payload)) . $payload);
    }

    /** @return list<array{string, string, int}> Addressed credit frames queued by actual reports */
    private function takeCredits(): array
    {
        $credits = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            self::assertSame(HilosSignalConstants::HILOS_UPLOAD_CREDIT, $signal->signalName->getName());
            self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
            self::assertInstanceOf(UploadCreditSignalData::class, $signal->data->data);
            $credits[] = [$signal->data->targetAcceptKey, $signal->data->data->clientUploadId, $signal->data->data->sendUpToBytes];
        }
        return $credits;
    }
}

abstract class UploadMemoryActivationApp extends Hilos
{
    protected const array FEATURES = [HilosFeature::UPLOADS];
}

final class UploadMemoryActivationContext extends RtContext
{
    public function configure(): void
    {
    }
}

final class UploadMemoryActivationManager extends DaemonManager
{
    public UploadMemoryActivationServer $workers;

    public function __construct()
    {
        parent::__construct();
        $this->workers = new UploadMemoryActivationServer($this->agentManagerDaemon);
        $this->registerServer($this->workers);
    }

    public function drain(): void
    {
        new ReflectionMethod(DaemonManager::class, 'dispatchSignals')->invoke($this);
    }

    public function started(): void
    {
        new ReflectionProperty(AgentManagerDaemon::class, 'startedAgentIds')->setValue(
            $this->agentManagerDaemon, [HilosAgentType::HILOS_UPLOADS => true],
        );
        $this->onAgentStarted(HilosAgentType::HILOS_UPLOADS);
    }

    public function roster(): AgentManagerDaemon
    {
        return $this->agentManagerDaemon;
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new class extends SignalRouter {
            protected function hilosClass(): string
            {
                return UploadMemoryActivationApp::class;
            }
        };
    }

    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new class extends AgentManagerDaemon {
            protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
            {
                return new UploadMemoryActivationAgent();
            }
        };
    }
}

final class UploadMemoryActivationAgent extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_UPLOADS;

    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }
}

/** Fake process status, with the real WorkerServer delivery, deletion and process-tick entrypoints. */
final class UploadMemoryActivationServer extends WorkerServer
{
    public UploadMemoryActivationWorker $worker;
    private AgentManagerDaemon $roster;
    private Process $process;

    public function __construct(AgentManagerDaemon $roster)
    {
        $this->roster = $roster;
        new ReflectionProperty(WorkerServer::class, 'agentManager')->setValue($this, $roster);
        new ReflectionProperty(WorkerServer::class, 'cachedLogDirectory')->setValue($this, '/tmp');
        $this->worker = new UploadMemoryActivationWorker($roster, $this);
        $this->clients = [$this->worker];
        $this->process = new class extends Process {
            public function __construct()
            {
            }
            public function tick(): void
            {
            }
            public function getStatus(): array
            {
                return [self::STATUS_RUNNING => false];
            }
            public function getStdOut(): string
            {
                return '';
            }
            public function getStdErr(): string
            {
                return '';
            }
        };
        new ReflectionProperty(WorkerServer::class, 'workers')->setValue($this, [
            'regular:1' => [
                WorkerConstants::FIELD_WORKER_PROCESS => $this->process,
                WorkerConstants::FIELD_WORKER_TYPE => WorkerConstants::TYPE_REGULAR,
                WorkerConstants::FIELD_WORKER_INDEX => 1,
            ],
        ]);
    }

    public function reportExit(): void
    {
        new ReflectionMethod(WorkerServer::class, 'tickWorkerProcess')->invoke($this, $this->process, WorkerConstants::TYPE_REGULAR, 1, 'regular:1');
    }

    public function ensureAgentUp(string $agentType, ?string $agentIndex): void
    {
        $agent = new UploadMemoryActivationAgent();
        $agent->setWorkerClient($this->worker);
        $this->roster->addAgent(HilosAgentType::HILOS_UPLOADS, $agent, 1, false);
    }

    protected function onStart(): void
    {
    }
}

final class UploadMemoryActivationWorker extends WorkerClient
{
    /**
     * @param AgentManagerDaemon $roster Agent ownership exercised by the fixture
     * @param WorkerServer $server Owner receiving this channel's close notification
     */
    public function __construct(AgentManagerDaemon $roster, WorkerServer $server)
    {
        new ReflectionProperty(WorkerClient::class, 'agentManager')->setValue($this, $roster);
        new ReflectionProperty(WorkerClient::class, 'workerServer')->setValue($this, $server);
        new ReflectionProperty(WorkerClient::class, 'isRegistered')->setValue($this, true);
        $this->setWorkerIndex(1);
    }

    public function receive(WorkerDTO $dto): void
    {
        new ReflectionMethod(WorkerClient::class, 'processMessage')->invoke($this, $dto->toJson());
    }

    /** @return list<DaemonAgentMessageDTO> Actual serialized upload deliveries */
    public function messages(): array
    {
        return array_values(array_filter(
            array_map(WorkerDTO::factoryWorkerDTO(...), array_filter(explode("\n", $this->writeBuffer))),
            static fn (WorkerDTO $dto): bool => $dto instanceof DaemonAgentMessageDTO,
        ));
    }

    public function close(): void
    {
        $this->onClose();
    }
}
