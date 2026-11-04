<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Table\DTO\TableWindowDescriptorDTO;
use Hilos\Core\Table\DTO\TableWindowRefusedSignalData;
use Hilos\Core\Table\DTO\TableWindowSignalData;
use Hilos\Core\Table\TableWindowRefusalCode;
use Hilos\Socket\WebSocket\DTO\DeliveryCompleteSignalData;
use Hilos\Socket\Worker\DTO\WorkerAgentMessageDTO;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\Hilos;
use Hilos\Status\DTO\StatusFilesChangedSignalData;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * What the daemon does with a signal it cannot turn into a frame (HIL-583, a tail of
 * the HIL-543 review).
 *
 * A payload that json_encode refuses leaves the node a choice of two bad answers, and the
 * one it takes is worth holding down: the frame is not sent, and a line naming the signal
 * is written instead. Without the line a client would simply stop receiving that signal,
 * and nothing in the process would say why.
 *
 * The encoder is driven directly through a subclass rather than through either of its
 * two callers. Both of them answer null in the same three lines, while reaching the
 * broadcast one asks for a stand-in WebSocketServer — a concrete class holding a socket,
 * not an interface — which is the obvious reason this path had no test until now.
 *
 * The refusal is a broken UTF-8 sequence, the one refusal of the three (NAN and recursion
 * being the others) that arrives from outside the process: off a socket, out of the
 * database, from somebody else's client.
 */
final class DaemonManagerSignalFrameTest extends TestCase
{
    /** Signal name carried by every frame here; also what the written line is searched for */
    private const string SIGNAL_NAME = 'unit-frame-drop';

    /** A 256 KiB leaf makes ancestor copies measurable without exhausting the unit runner. */
    private const int FILTER_SCALAR_BYTES = 256 * 1024;

    /** Leaves room for the IPC envelope and leaf shapes inside the socket's 100-container limit. */
    private const int FILTER_WRAPPER_COUNT = 80;

    /** Generous allowance for decoding and encoding; 80 raw leaf copies alone exceed 20 MiB. */
    private const int FILTER_IPC_PEAK_BUDGET_BYTES = 8 * 1024 * 1024;

    /** Temporary main log file the assertions read the written line back from */
    private string $logFile = '';

    protected function setUp(): void
    {
        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-signal-frame-log');
        Logger::setLogFile($this->logFile);
    }

    protected function tearDown(): void
    {
        Logger::resetLogFile();

        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }

        Hilos::$sr = null;

        parent::tearDown();
    }

    /**
     * The name is asserted separately from the wording because it is the only thing
     * telling one dropped frame from another; a line without it costs the reader the
     * whole search.
     */
    public function testAPayloadJsonRefusesIsDroppedAndWrittenWithItsSignalName(): void
    {
        $manager = new DaemonManagerSignalFrameTestManager();

        $this->assertNull($manager->encodeFrame($this->signal(['s' => "bad-\xB1\x31"])));

        $written = $this->written();
        $this->assertStringContainsString('Signal frame dropped', $written);
        $this->assertStringContainsString(self::SIGNAL_NAME, $written);
    }

    /**
     * Without this case the file above stays green on an encoder broken to return null
     * always, which is to say it would hold nothing at all.
     */
    public function testAnEncodablePayloadComesBackAsTheFrameAndIsNotWritten(): void
    {
        $manager = new DaemonManagerSignalFrameTestManager();

        $frame = $manager->encodeFrame($this->signal(['s' => 'ok']));

        $this->assertSame('{"type":"unit-frame-drop","data":{"s":"ok"}}', $frame);
        $this->assertSame('', $this->written());
    }

    public function testStatusHintKeepsItsClassAcrossIpcAndBecomesAnObjectOnlyInTheBrowserFrame(): void
    {
        $payload = new WebSocketSignalData(new StatusFilesChangedSignalData());
        self::assertSame([
            'data' => [], 'dataType' => StatusFilesChangedSignalData::class,
        ], $payload->toArray());
        $restored = WebSocketSignalData::fromArray(json_decode(json_encode($payload->toArray()), true));
        self::assertInstanceOf(StatusFilesChangedSignalData::class, $restored->data);
        $signal = new SignalDTO(
            new SignalSource(SignalSource::WORKER), new SignalType(SignalTypeConstants::WS_ALL_CONNECTED),
            new SignalName(HilosSignalConstants::STATUS_FILES_CHANGED), $restored,
        );
        self::assertSame('{"type":"status_files_changed","data":{}}', new DaemonManagerSignalFrameTestManager()->encodeFrame($signal));
        self::assertSame([], $restored->data->toArray());
    }

    /**
     * The real worker reader must preserve the JSON shape of the entire applied filter,
     * including empty objects and numeric object keys, through repeated deliveries.
     */
    public function testPageWindowFiltersRemainObjectsInTheBrowserFrameAfterWorkerJsonIpc(): void
    {
        $coordinates = [
            TableWindowSignalData::rows => [],
            TableWindowDescriptorDTO::SORT => [],
            TableWindowSignalData::limit => 20,
            TableWindowSignalData::totalCount => 0,
            TableWindowSignalData::totalExact => true,
            TableWindowSignalData::firstAnchor => null,
            TableWindowSignalData::lastAnchor => null,
            TableWindowSignalData::rowsBefore => 0,
        ];
        $payload = new PagePayload(windows: [
            'empty' => [...$coordinates, TableWindowDescriptorDTO::FILTER => (object) []],
            'filtered' => [...$coordinates, TableWindowDescriptorDTO::FILTER => (object) [
                'search' => 'Ada', 'ids' => [3, 7], 'options' => [],
                'criteria' => (object) [],
                'values' => (object) ['null' => null, 'empty' => '', 'flag' => false, 'zero' => 0, 'ratio' => 1.5, 'text' => '0'],
                'numeric' => (object) ['0' => 'first', '1' => (object) []],
                'records' => [(object) [], (object) ['id' => 7, 'ids' => [], 'criteria' => (object) ['active' => true]]],
            ]],
        ]);
        $message = new WorkerAgentMessageDTO(null, new SignalDTO(
            new SignalSource(SignalSource::WORKER),
            new SignalType(SignalTypeConstants::WS_USER),
            new SignalName(SignalTypeConstants::PAGE_RESPONSE),
            new WebSocketSignalData(new PageResponseSignalData('unit-page', $payload), targetAcceptKey: 'ak'),
        ));

        $restored = WorkerDTO::factoryWorkerDTO($message->toJson());
        self::assertInstanceOf(WorkerAgentMessageDTO::class, $restored);
        self::assertInstanceOf(SignalDTO::class, $restored->signal);
        self::assertInstanceOf(WebSocketSignalData::class, $restored->signal->data);
        self::assertSame('ak', $restored->signal->data->targetAcceptKey);
        self::assertInstanceOf(PageResponseSignalData::class, $restored->signal->data->data);
        self::assertSame([], $restored->signal->data->data->payload->windows['empty'][TableWindowDescriptorDTO::FILTER]);

        foreach ([$restored, WorkerDTO::factoryWorkerDTO($restored->toJson())] as $delivery) {
            self::assertInstanceOf(WorkerAgentMessageDTO::class, $delivery);
            self::assertInstanceOf(WebSocketSignalData::class, $delivery->signal->data);
            self::assertInstanceOf(PageResponseSignalData::class, $delivery->signal->data->data);
            $frame = new DaemonManagerSignalFrameTestManager()->encodeFrame($delivery->signal);
            self::assertIsString($frame);
            $decoded = json_decode($frame, flags: JSON_THROW_ON_ERROR);
            self::assertSame(SignalTypeConstants::PAGE_RESPONSE, $decoded->type);
            self::assertSame('unit-page', $decoded->data->page);
            self::assertInstanceOf(stdClass::class, $decoded->data->payload->windows->empty->filter);
            self::assertSame([], get_object_vars($decoded->data->payload->windows->empty->filter));
            $filter = $decoded->data->payload->windows->filtered->filter;
            self::assertInstanceOf(stdClass::class, $filter);
            self::assertInstanceOf(stdClass::class, $filter->criteria);
            self::assertSame([], get_object_vars($filter->criteria));
            self::assertSame([3, 7], $filter->ids);
            self::assertSame([], $filter->options);
            self::assertInstanceOf(stdClass::class, $filter->values);
            self::assertSame(
                ['null' => null, 'empty' => '', 'flag' => false, 'zero' => 0, 'ratio' => 1.5, 'text' => '0'],
                get_object_vars($filter->values),
            );
            self::assertInstanceOf(stdClass::class, $filter->numeric);
            self::assertSame('first', $filter->numeric->{'0'});
            self::assertInstanceOf(stdClass::class, $filter->numeric->{'1'});
            self::assertSame([], get_object_vars($filter->numeric->{'1'}));
            self::assertIsArray($filter->records);
            self::assertCount(2, $filter->records);
            self::assertInstanceOf(stdClass::class, $filter->records[0]);
            self::assertSame([], get_object_vars($filter->records[0]));
            self::assertInstanceOf(stdClass::class, $filter->records[1]);
            self::assertSame(7, $filter->records[1]->id);
            self::assertSame([], $filter->records[1]->ids);
            self::assertInstanceOf(stdClass::class, $filter->records[1]->criteria);
            self::assertTrue($filter->records[1]->criteria->active);
            self::assertSame(
                json_encode($payload->windows['filtered'][TableWindowDescriptorDTO::FILTER], JSON_THROW_ON_ERROR),
                json_encode($filter, JSON_THROW_ON_ERROR),
            );
            self::assertSame($frame, new DaemonManagerSignalFrameTestManager()->encodeFrame($delivery->signal));
            foreach (['empty', 'filtered'] as $tableKey) {
                self::assertSame($coordinates, array_diff_key(
                    get_object_vars($decoded->data->payload->windows->$tableKey),
                    [TableWindowDescriptorDTO::FILTER => true],
                ));
            }
        }
        self::assertSame([], $restored->signal->data->data->payload->windows['empty'][TableWindowDescriptorDTO::FILTER]);
        self::assertInstanceOf(stdClass::class, $payload->windows['empty'][TableWindowDescriptorDTO::FILTER]);
    }

    /**
     * Peak PHP allocations expose temporary subtree copies that disappear before delivery.
     * Measure only factory-to-frame work after fixture construction, independently of the
     * suite's previous peak; this budget describes the fixture, not production RSS or limits.
     */
    public function testDeepPageWindowFilterUsesBoundedMemoryAcrossWorkerJsonIpc(): void
    {
        $text = str_repeat('a', self::FILTER_SCALAR_BYTES);
        $filter = (object) [
            'text' => $text, 'shapes' => [(object) [], [], (object) ['0' => (object) []]],
            'escaped"\\key' => "braces {[]} and a quote \" with a slash \\ and newline \n",
        ];
        for ($depth = 0; $depth < self::FILTER_WRAPPER_COUNT; $depth++) {
            $filter = (object) ['x' => $filter];
        }
        $payload = new PagePayload(windows: ['table' => [
            TableWindowSignalData::rows => [],
            TableWindowDescriptorDTO::SORT => [],
            TableWindowSignalData::limit => 20,
            TableWindowSignalData::totalCount => 0,
            TableWindowSignalData::totalExact => true,
            TableWindowSignalData::firstAnchor => null,
            TableWindowSignalData::lastAnchor => null,
            TableWindowSignalData::rowsBefore => 0,
            TableWindowDescriptorDTO::FILTER => $filter,
        ]]);
        $message = new WorkerAgentMessageDTO(null, new SignalDTO(
            new SignalSource(SignalSource::WORKER),
            new SignalType(SignalTypeConstants::WS_USER),
            new SignalName(SignalTypeConstants::PAGE_RESPONSE),
            new WebSocketSignalData(new PageResponseSignalData('unit-page', $payload), targetAcceptKey: 'ak'),
        ));
        $json = " \t\n" . $message->toJson() . "\r\n";
        $manager = new DaemonManagerSignalFrameTestManager();

        memory_reset_peak_usage();
        $baselineBytes = memory_get_usage();
        $restored = WorkerDTO::factoryWorkerDTO($json);
        $frame = $manager->encodeFrame($restored->signal);
        $peakBytes = memory_get_peak_usage() - $baselineBytes;

        self::assertInstanceOf(WorkerAgentMessageDTO::class, $restored);
        self::assertInstanceOf(SignalDTO::class, $restored->signal);
        self::assertInstanceOf(WebSocketSignalData::class, $restored->signal->data);
        self::assertSame('ak', $restored->signal->data->targetAcceptKey);
        self::assertInstanceOf(PageResponseSignalData::class, $restored->signal->data->data);
        self::assertIsString($frame);
        $decoded = json_decode($frame, flags: JSON_THROW_ON_ERROR);
        self::assertSame(SignalTypeConstants::PAGE_RESPONSE, $decoded->type);
        self::assertSame('unit-page', $decoded->data->page);
        $leaf = $decoded->data->payload->windows->table->filter;
        for ($depth = 0; $depth < self::FILTER_WRAPPER_COUNT; $depth++) {
            self::assertInstanceOf(stdClass::class, $leaf);
            self::assertSame(['x'], array_keys(get_object_vars($leaf)));
            $leaf = $leaf->x;
        }
        self::assertInstanceOf(stdClass::class, $leaf);
        self::assertSame($text, $leaf->text);
        self::assertIsArray($leaf->shapes);
        self::assertCount(3, $leaf->shapes);
        self::assertInstanceOf(stdClass::class, $leaf->shapes[0]);
        self::assertSame([], get_object_vars($leaf->shapes[0]));
        self::assertSame([], $leaf->shapes[1]);
        self::assertInstanceOf(stdClass::class, $leaf->shapes[2]);
        self::assertInstanceOf(stdClass::class, $leaf->shapes[2]->{'0'});
        self::assertSame([], get_object_vars($leaf->shapes[2]->{'0'}));
        self::assertSame(
            json_encode($filter, JSON_THROW_ON_ERROR),
            json_encode($decoded->data->payload->windows->table->filter, JSON_THROW_ON_ERROR),
        );
        self::assertLessThan(
            self::FILTER_IPC_PEAK_BUDGET_BYTES, $peakBytes,
            'Factory-to-frame peak must not retain a raw copy of the large leaf at each ancestor.',
        );
    }

    /**
     * A valid NUL-prefixed data key must not disable object/list preservation in filters.
     */
    public function testPageWindowFilterShapesSurviveUnrelatedNulPrefixedDataKeys(): void
    {
        $filter = (object) [
            'criteria' => (object) [], 'ids' => [],
            'numeric' => (object) ['0' => 'first', '1' => (object) []],
            'records' => [(object) [], (object) ['id' => 7, 'ids' => [], 'criteria' => (object) ['active' => true]]],
            'values' => (object) ['null' => null, 'empty' => '', 'flag' => false, 'zero' => 0, 'ratio' => 1.5, 'text' => '0'],
        ];
        $payload = new PagePayload(
            data: ['example' => ["\0key" => 1], 'text' => 'braces {[]} and an escaped quote "'],
            windows: ['table' => [
                TableWindowSignalData::rows => [],
                TableWindowDescriptorDTO::SORT => [],
                TableWindowSignalData::limit => 20,
                TableWindowSignalData::totalCount => 0,
                TableWindowSignalData::totalExact => true,
                TableWindowSignalData::firstAnchor => null,
                TableWindowSignalData::lastAnchor => null,
                TableWindowSignalData::rowsBefore => 0,
                TableWindowDescriptorDTO::FILTER => $filter,
            ]],
            refusedWindows: ['refused' => [TableWindowRefusedSignalData::errorCode => TableWindowRefusalCode::INTERNAL_ERROR]],
        );
        $message = new WorkerAgentMessageDTO(null, new SignalDTO(
            new SignalSource(SignalSource::WORKER),
            new SignalType(SignalTypeConstants::WS_USER),
            new SignalName(SignalTypeConstants::PAGE_RESPONSE),
            new WebSocketSignalData(new PageResponseSignalData('unit-page', $payload), targetAcceptKey: 'ak'),
        ));

        $restored = WorkerDTO::factoryWorkerDTO($message->toJson());
        self::assertInstanceOf(WorkerAgentMessageDTO::class, $restored);
        foreach ([$restored, WorkerDTO::factoryWorkerDTO($restored->toJson())] as $delivery) {
            self::assertInstanceOf(WorkerAgentMessageDTO::class, $delivery);
            self::assertInstanceOf(SignalDTO::class, $delivery->signal);
            self::assertInstanceOf(WebSocketSignalData::class, $delivery->signal->data);
            self::assertSame('ak', $delivery->signal->data->targetAcceptKey);
            self::assertInstanceOf(PageResponseSignalData::class, $delivery->signal->data->data);
            $frame = new DaemonManagerSignalFrameTestManager()->encodeFrame($delivery->signal);
            self::assertIsString($frame);
            self::assertStringContainsString('"filter":' . json_encode($filter, JSON_THROW_ON_ERROR), $frame);
            // Object-mode decoding of the whole frame would itself reject the valid data key.
            self::assertSame(
                ['type' => SignalTypeConstants::PAGE_RESPONSE, 'data' => json_decode(
                    new PageResponseSignalData('unit-page', $payload)->toJson(), true, flags: JSON_THROW_ON_ERROR,
                )],
                json_decode($frame, true, flags: JSON_THROW_ON_ERROR),
            );
            self::assertSame($frame, new DaemonManagerSignalFrameTestManager()->encodeFrame($delivery->signal));
        }
    }

    /**
     * Missing and malformed filters stay as sent; other sections use the shared decoder.
     */
    public function testWorkerPageResponseDoesNotInventFiltersOrChangeOtherSections(): void
    {
        $refused = ['refused' => [TableWindowRefusedSignalData::errorCode => TableWindowRefusalCode::INTERNAL_ERROR]];
        foreach ([
            new PagePayload(
                data: ['criteria' => (object) []],
                windows: [
                    'missing' => [PagePayload::rows => []],
                    'null' => [TableWindowDescriptorDTO::FILTER => null],
                    'scalar' => [TableWindowDescriptorDTO::FILTER => false],
                ],
                refusedWindows: $refused,
            ),
            new PagePayload(data: ['criteria' => (object) []]),
            new PagePayload(refusedWindows: $refused),
            new PagePayload(),
        ] as $payload) {
            $response = new PageResponseSignalData('unit-page', $payload);
            $message = new WorkerAgentMessageDTO(null, new SignalDTO(
                new SignalSource(SignalSource::WORKER),
                new SignalType(SignalTypeConstants::WS_USER),
                new SignalName(SignalTypeConstants::PAGE_RESPONSE),
                new WebSocketSignalData($response),
            ));

            $restored = WorkerDTO::factoryWorkerDTO($message->toJson());
            self::assertInstanceOf(WorkerAgentMessageDTO::class, $restored);
            self::assertInstanceOf(WebSocketSignalData::class, $restored->signal->data);
            self::assertInstanceOf(PageResponseSignalData::class, $restored->signal->data->data);
            $expected = json_decode($response->toJson(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame($expected, $restored->signal->data->data->toArray());
            $frame = new DaemonManagerSignalFrameTestManager()->encodeFrame($restored->signal);
            self::assertIsString($frame);
            self::assertSame(
                ['type' => SignalTypeConstants::PAGE_RESPONSE, 'data' => $expected],
                json_decode($frame, true, flags: JSON_THROW_ON_ERROR),
            );
        }
    }

    /**
     * A matching signal name and payload layout do not opt an untyped payload into preservation.
     */
    public function testUntypedWorkerPayloadKeepsItsAssociativeDecoding(): void
    {
        $message = new WorkerAgentMessageDTO(null, new SignalDTO(
            new SignalSource(SignalSource::WORKER),
            new SignalType(SignalTypeConstants::WS_USER),
            new SignalName(SignalTypeConstants::PAGE_RESPONSE),
            new WebSocketSignalData(new SignalData([
                'page' => 'unit-page',
                'payload' => ['windows' => ['table' => ['filter' => (object) ['criteria' => (object) []]]]],
            ])),
        ));

        $restored = WorkerDTO::factoryWorkerDTO($message->toJson());
        self::assertInstanceOf(WorkerAgentMessageDTO::class, $restored);
        self::assertInstanceOf(WebSocketSignalData::class, $restored->signal->data);
        self::assertInstanceOf(SignalData::class, $restored->signal->data->data);
        self::assertSame([
            'page' => 'unit-page',
            'payload' => ['windows' => ['table' => ['filter' => ['criteria' => []]]]],
        ], $restored->signal->data->data->toArray());
    }

    public function testOtherEmptyPayloadsKeepTheirExistingArrayEncoding(): void
    {
        self::assertSame('{"type":"unit-frame-drop","data":[]}', new DaemonManagerSignalFrameTestManager()->encodeFrame($this->signal([])));
    }

    public function testServiceMarkerCannotBeEncodedEvenOnANonAddressedPath(): void
    {
        $manager = new DaemonManagerSignalFrameTestManager();
        foreach ([new DeliveryCompleteSignalData('p', 'token'), new SignalData(['page' => 'p', 'deliveryId' => 'token'])] as $payload) {
            $signal = new SignalDTO(
                new SignalSource(SignalSource::WORKER), new SignalType(SignalTypeConstants::WS_ALL),
                new SignalName(SignalConstants::DELIVERY_COMPLETE), new WebSocketSignalData($payload),
            );
            self::assertNull($manager->encodeFrame($signal));
        }
        self::assertSame('', $this->written());
    }

    /**
     * Builds the one signal shape every case here shares apart from its payload.
     *
     * @param array<string, mixed> $payload Payload the frame is built from
     * @return SignalDTO Signal carrying that payload
     */
    private function signal(array $payload): SignalDTO
    {
        return new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::SYSTEM),
            new SignalName(self::SIGNAL_NAME),
            new SignalData($payload),
        );
    }

    /**
     * Reads back whatever the encoder wrote to the temporary log.
     *
     * @return string Log contents, empty when the encoder stayed silent
     */
    private function written(): string
    {
        return (string)file_get_contents($this->logFile);
    }
}

/**
 * Daemon manager offering the encoder to the test; nothing else about it is exercised.
 */
final class DaemonManagerSignalFrameTestManager extends DaemonManager
{
    /**
     * @param SignalDTO $signal Signal to serialize
     * @return ?string Frame JSON, or null when the payload cannot be encoded
     */
    public function encodeFrame(SignalDTO $signal): ?string
    {
        return $this->encodeSignalFrame($signal);
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new DaemonManagerSignalFrameTestAgentManagerDaemon();
    }
}

final class DaemonManagerSignalFrameTestAgentManagerDaemon extends AgentManagerDaemon
{
    /**
     * @param string $agentType Agent type that was asked for
     * @param ?string $agentIndex Agent index that was asked for
     * @return AgentDaemonInterface Never returned; the test starts no agent
     * @throws AgentDaemonCreationFailedException Always
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException('not used in test');
    }
}
