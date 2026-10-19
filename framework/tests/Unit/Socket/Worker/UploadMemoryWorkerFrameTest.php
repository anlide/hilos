<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Socket\Worker;

use Hilos\Constants\WorkerConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Files\Upload\UploadMemoryPoolSink;
use Hilos\Files\Upload\UploadMemoryInput;
use Hilos\Files\Upload\UploadMemoryReceipt;
use Hilos\Files\Upload\UploadsSettingsCatalog;
use Hilos\Hilos;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\WebSocket\DTO\WebSocketFrameBinarySignalDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerProtectedModeSettingsDTO;
use Hilos\Socket\Worker\DTO\WorkerRegisterDTO;
use Hilos\Socket\Worker\DTO\WorkerUploadConsumedDTO;
use Hilos\Socket\Worker\DTO\WorkerUploadSettingsDTO;
use Hilos\Socket\Worker\WorkerDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/** Upload control frames are strict, backward compatible and attributed to their registered channel. */
final class UploadMemoryWorkerFrameTest extends TestCase
{
    /**
     * @return array<string, array{int}> Budgets below a frame and the catalog default
     */
    public static function budgets(): array
    {
        return ['one' => [1], 'three' => [3], 'default' => [UploadsSettingsCatalog::DEFAULT_MEMORY_POOL_BYTES]];
    }

    /**
     * @return array<string, array{mixed}> Invalid wire field values
     */
    public static function invalidBudgets(): array
    {
        return [
            'null' => [null], 'false' => [false], 'true' => [true], 'string' => ['3'],
            'float' => [3.0], 'zero' => [0], 'negative' => [-1], 'array' => [[]],
        ];
    }

    #[DataProvider('budgets')]
    public function testSettingsRoundTripThroughTheFactory(int $bytes): void
    {
        $frame = new WorkerUploadSettingsDTO($bytes);
        $this->assertSame('worker_upload_settings', WorkerConstants::MESSAGE_UPLOAD_SETTINGS);
        $this->assertSame(['type' => 'worker_upload_settings', 'memoryPoolBytes' => $bytes], $frame->toArray());
        $restored = WorkerDTO::factoryWorkerDTO($frame->toJson());
        $this->assertInstanceOf(WorkerUploadSettingsDTO::class, $restored);
        $this->assertSame($bytes, $restored->memoryPoolBytes);
        $this->assertSame($frame->toArray(), $restored->toArray());
    }

    #[DataProvider('invalidBudgets')]
    public function testInvalidWireValuesAreRefused(mixed $value): void
    {
        $this->expectException(InvalidFormatException::class);
        WorkerDTO::factoryWorkerDTO((string)json_encode(
            ['type' => 'worker_upload_settings', 'memoryPoolBytes' => $value],
            JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    public function testMissingValueIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);
        WorkerDTO::factoryWorkerDTO('{"type":"worker_upload_settings"}');
    }

    public function testConstructorRejectsANonpositiveBudget(): void
    {
        $this->expectException(InvalidFormatException::class);
        new WorkerUploadSettingsDTO(0);
    }

    public function testExistingFramesKeepTheirShapes(): void
    {
        $this->assertSame(['type' => 'worker_register', 'workerIndex' => 7, 'monopolistic' => false], new WorkerRegisterDTO(7)->toArray());
        $this->assertSame(
            ['type' => 'worker_protected_mode_settings', 'manualRestartIsNormal' => false],
            new WorkerProtectedModeSettingsDTO(false)->toArray(),
        );
    }

    public function testRegisteredChannelsSupplyTheirOwnIdentityAndExactIntegerToTheSink(): void
    {
        $previousCluster = Hilos::$cluster;
        Hilos::$cluster = null;
        try {
            $sink = new UploadMemoryRecordingSink();
            foreach ([false, true] as $monopolistic) {
                $worker = new UploadMemoryReceivingWorker();
                $worker->setUploadMemoryPoolSink($sink);
                $worker->receive(new WorkerRegisterDTO(7, $monopolistic));
                $worker->receive(new WorkerUploadSettingsDTO(3));
                $this->assertSame($worker, $sink->workers[array_key_last($sink->workers)]);
                $this->assertTrue($worker->isRegistered());
                $this->assertSame($monopolistic ? -7 : 7, $worker->getWorkerId());
            }
            $this->assertSame([3, 3], $sink->values);
        } finally {
            Hilos::$cluster = $previousCluster;
        }
    }

    public function testUnregisteredChannelIsRefusedBeforeCallingTheSink(): void
    {
        $sink = new UploadMemoryRecordingSink();
        $worker = new UploadMemoryReceivingWorker();
        $worker->setUploadMemoryPoolSink($sink);
        try {
            $worker->receive(new WorkerUploadSettingsDTO(3));
            $this->fail('An unregistered upload settings report must be refused');
        } catch (InvalidFormatException $exception) {
            $this->assertSame('Upload settings require a registered worker', $exception->getMessage());
            $this->assertSame([], $sink->values);
        }
    }

    public function testOptionalSinkWorksWithoutDatabaseOrRuntime(): void
    {
        $previousDb = Hilos::$db;
        $previousRt = Hilos::$rt;
        $previousSettings = Hilos::$setting;
        $previousCluster = Hilos::$cluster;
        Hilos::$db = null;
        Hilos::$rt = null;
        Hilos::$setting = null;
        Hilos::$cluster = null;
        try {
            $worker = new UploadMemoryReceivingWorker();
            $worker->receive(new WorkerRegisterDTO(2));
            $worker->receive(new WorkerUploadSettingsDTO(1));
            $this->assertTrue($worker->isRegistered());
            $sink = new UploadMemoryRecordingSink();
            $worker->setUploadMemoryPoolSink($sink);
            $worker->receive(new WorkerUploadSettingsDTO(3));
            $this->assertSame([3], $sink->values);
            $this->assertSame([$worker], $sink->workers);
            $this->assertNull(Hilos::$db);
            $this->assertNull(Hilos::$rt);
        } finally {
            Hilos::$db = $previousDb;
            Hilos::$rt = $previousRt;
            Hilos::$setting = $previousSettings;
            Hilos::$cluster = $previousCluster;
        }
    }

    /** @return iterable<string, array{?string}> Canonical optional strings, without a new token grammar */
    public static function receiptIds(): iterable
    {
        yield 'absent' => [null];
        yield 'ledger' => ['node:boot:3'];
        yield 'numeric string' => ['0'];
        yield 'empty string' => [''];
    }

    /**
     * @param ?string $receiptId Optional receipt using the canonical string contract
     */
    #[DataProvider('receiptIds')]
    public function testOptionalDaemonReceiptRoundTripsWithoutChangingTheInnerSignal(?string $receiptId): void
    {
        $signal = new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::FRAME_BINARY),
            new SignalName(SignalTypeConstants::FRAME_BINARY),
            new WebSocketFrameBinarySignalDTO('socket', "raw\0\xff"),
        );
        $old = ['type' => 'daemon_agent_message', 'agentId' => 'uploads', 'signal' => $signal->toArray()];
        $frame = new DaemonAgentMessageDTO('uploads', $signal, $receiptId);
        $this->assertSame($receiptId === null ? $old : $old + ['uploadReceiptId' => $receiptId], $frame->toArray());
        if ($receiptId === null) {
            $this->assertSame((string)json_encode($old), $frame->toJson());
        }
        $restored = WorkerDTO::factoryWorkerDTO($frame->toJson());
        $this->assertInstanceOf(DaemonAgentMessageDTO::class, $restored);
        $this->assertSame($receiptId, $restored->uploadReceiptId);
        $this->assertSame($signal->toArray(), $restored->signal->toArray());
        $this->assertSame($frame->toArray(), $restored->toArray());
        $this->assertSame($signal, DaemonAgentMessageDTO::fromArray([
            'agentId' => 'uploads', 'signal' => $signal, 'uploadReceiptId' => $receiptId,
        ])->signal);
    }

    /**
     * Explicit null follows the canonical optional reader and is omitted on output.
     */
    public function testExplicitNullDaemonReceiptUsesTheOldWireShape(): void
    {
        $frame = DaemonAgentMessageDTO::fromArray([
            'agentId' => 'uploads',
            'signal' => new SignalDTO(
                new SignalSource(SignalSource::WEBSOCKET),
                new SignalType(SignalTypeConstants::FRAME_BINARY),
                new SignalName(SignalTypeConstants::FRAME_BINARY),
                new WebSocketFrameBinarySignalDTO('socket', 'raw'),
            ),
            'uploadReceiptId' => null,
        ]);
        $this->assertNull($frame->uploadReceiptId);
        $this->assertArrayNotHasKey('uploadReceiptId', $frame->toArray());
    }

    /** @return iterable<string, array{mixed}> Present optional values with invalid scalar/list types */
    public static function invalidReceiptIds(): iterable
    {
        foreach ([false, true, 0, -1, 1.5, [], ['receipt'], new stdClass()] as $index => $value) {
            yield (string)$index => [$value];
        }
    }

    /**
     * @param mixed $receiptId Present value that must be refused without coercion
     */
    #[DataProvider('invalidReceiptIds')]
    public function testDaemonReceiptRejectsNonStrings(mixed $receiptId): void
    {
        $this->expectException(InvalidFormatException::class);
        WorkerDTO::factoryWorkerDTO((string)json_encode([
            'type' => 'daemon_agent_message', 'agentId' => 'uploads',
            'signal' => new SignalDTO(
                new SignalSource(SignalSource::WEBSOCKET),
                new SignalType(SignalTypeConstants::FRAME_BINARY),
                new SignalName(SignalTypeConstants::FRAME_BINARY),
                new WebSocketFrameBinarySignalDTO('socket', 'raw'),
            )->toArray(),
            'uploadReceiptId' => $receiptId,
        ]));
    }

    /** @return iterable<string, array{list<string>}> Wire lists, preserving their values and order */
    public static function consumedLists(): iterable
    {
        yield 'empty' => [[]];
        yield 'one' => [['node:boot:3']];
        yield 'duplicates' => [['a', 'b', 'a']];
        yield 'canonical strings' => [['', '0', 'node:boot:3']];
    }

    /**
     * @param list<string> $receiptIds Exact ordered wire values
     */
    #[DataProvider('consumedLists')]
    public function testConsumedRoundTripThroughTheFactory(array $receiptIds): void
    {
        $frame = new WorkerUploadConsumedDTO($receiptIds);
        $this->assertSame('worker_upload_consumed', WorkerConstants::MESSAGE_UPLOAD_CONSUMED);
        $this->assertSame(['type' => 'worker_upload_consumed', 'receiptIds' => $receiptIds], $frame->toArray());
        $restored = WorkerDTO::factoryWorkerDTO($frame->toJson());
        $this->assertInstanceOf(WorkerUploadConsumedDTO::class, $restored);
        $this->assertSame($receiptIds, $restored->receiptIds);
        $this->assertSame($frame->toArray(), $restored->toArray());
    }

    /** @return iterable<string, array{mixed}> Malformed lists, including malformed individual elements */
    public static function invalidConsumedLists(): iterable
    {
        foreach ([null, false, true, 0, -1, 1.5, 'id', ['a', null], ['a', false], ['a', 1], ['a', []],
            ['id' => 'a'], [1 => 'a'], [0 => 'a', 2 => 'b']] as $index => $value) {
            yield (string)$index => [$value];
        }
    }

    /**
     * @param mixed $receiptIds Malformed list or element that must refuse the whole payload
     */
    #[DataProvider('invalidConsumedLists')]
    public function testConsumedRejectsMalformedListsWithoutSkippingElements(mixed $receiptIds): void
    {
        $this->expectException(InvalidFormatException::class);
        WorkerDTO::factoryWorkerDTO((string)json_encode(['type' => 'worker_upload_consumed', 'receiptIds' => $receiptIds]));
    }

    /**
     * A consumed frame without receiptIds is malformed.
     */
    public function testConsumedRequiresItsList(): void
    {
        $this->expectException(InvalidFormatException::class);
        WorkerDTO::factoryWorkerDTO('{"type":"worker_upload_consumed"}');
    }

    /**
     * In-process construction applies the same list-element checks as wire decoding.
     */
    public function testConsumedConstructorAlsoRejectsMalformedLists(): void
    {
        $this->expectException(InvalidFormatException::class);
        new WorkerUploadConsumedDTO(['first', -1]);
    }
}

/** Uses the real decoder and registration handler without opening a socket. */
final class UploadMemoryReceivingWorker extends WorkerClient
{
    public function __construct()
    {
    }

    public function receive(WorkerDTO $frame): void
    {
        $this->readBuffer = $frame->toJson() . "\n";
        $this->processReadBuffer();
    }
}

/** Records typed delivery without creating a pool or reaching a data context. */
final class UploadMemoryRecordingSink implements UploadMemoryPoolSink
{
    /** @var list<WorkerClient> Registered reporting channels */
    public array $workers = [];

    /** @var list<int> Effective budgets */
    public array $values = [];

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

    public function onUploadSettings(WorkerClient $worker, int $memoryPoolBytes): void
    {
        $this->workers[] = $worker;
        $this->values[] = $memoryPoolBytes;
    }

    /**
     * @param WorkerClient $worker Reporting channel, unused by settings cases
     * @param list<string> $receiptIds Terminal ids, unused by settings cases
     */
    public function onUploadConsumed(WorkerClient $worker, array $receiptIds): void
    {
    }
}
