<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Runtime\RtStaleness;
use Hilos\Runtime\Snapshot\RtSnapshotScope;
use Hilos\Runtime\Snapshot\RtWorkBudget;
use Hilos\Runtime\State\Collection\HilosProbeNotes;
use Hilos\Runtime\State\Item\HilosProbeNote;
use Hilos\Runtime\Stream\RtSyncStreamChannel;
use Hilos\Runtime\Stream\RtSyncStreamEncoder;
use Hilos\Runtime\Stream\RtSyncStreamFrame;
use Hilos\Runtime\Stream\RtSyncStreamOperation;
use Hilos\Runtime\Stream\RtSyncStreamSnapshot;
use Hilos\Runtime\Stream\RtSyncStreamStatus;
use Hilos\Runtime\View\Actions\Collection\HilosProbeNotesActions;
use Hilos\Runtime\View\Actions\Item\HilosProbeNoteActions;
use Hilos\Runtime\View\Collection\HilosProbeNotes as ViewHilosProbeNotes;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Client\AbstractClient;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Worker\DTO\WorkerRtStalenessMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSyncStreamDTO;
use Hilos\Socket\Worker\WorkerDTO;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Socket;

/** The enqueue/encoder window lives inside a tick, so these cases drive its allowances directly. */
final class WorkerClientRtStalenessTest extends TestCase
{
    private const string ROW = 'row';
    private const string LATE = 'late';
    private const string FALLBACK = 'fallback';
    private const string KEPT_A = 'kept-a';
    private const string KEPT_B = 'kept-b';
    private const string OTHER = 'other-notes';
    private const string NODE = 'node-a';
    private const float OLD = 1000.5;
    private const float EFFECTIVE = 2000.5;
    private const float DELIVERED = 3000.5;
    private const string OLD_TEXT = 'cut text';
    private const int MAX_PASSES = 2000;

    private ?EnvAccessor $previousEnv;
    private ?RtContext $previousRt;
    /** @var list<WorkerClient> Local connections, without a worker process */
    private array $clients = [];
    /** @var list<Socket> Other ends of the local connections */
    private array $peers = [];

    /** Installs only the socket catalog; the source belongs to this case. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousEnv = Hilos::$env;
        $this->previousRt = Hilos::$rt;
        Hilos::$env = new EnvAccessor();
        RtStaleness::reset();
    }

    /** Releases both socket ends and restores the process's facade. */
    protected function tearDown(): void
    {
        foreach ($this->clients as $client) {
            $client->close();
        }
        foreach ($this->peers as $peer) {
            socket_close($peer);
        }
        RtStaleness::reset();
        Hilos::$rt = $this->previousRt;
        Hilos::$env = $this->previousEnv;
        parent::tearDown();
    }

    /** @return array<string, array{string, string, bool}> Queue, exact encoder boundary, and whether the mark starts a new epoch */
    public static function boundaries(): array
    {
        return [
            'waiting C' => ['one', 'queued', false],
            'all waiting C among D and ordinary' => ['mixed', 'queued', false],
            'no stream' => ['closed', 'queued', false],
            'empty stream' => ['empty', 'queued', false],
            'ordinary only' => ['ordinary', 'queued', false],
            'D only' => ['other', 'queued', false],
            'encoder before head' => ['one', 'head', false],
            'encoder inside stale rows' => ['one', 'middle', false],
            'current and queued C among D and ordinary' => ['mixed', 'middle', false],
            'encoder after last stale row yield' => ['one', 'tail', false],
            'encoder at empty stale rows yield' => ['one', 'empty-tail', false],
            'first part already sent' => ['one', 'started', false],
            'started C followed by unstarted C' => ['mixed', 'started', false],
            'new epoch while queued' => ['one', 'queued', true],
            'new epoch inside metadata' => ['one', 'middle', true],
            'new epoch after last metadata yield' => ['one', 'tail', true],
        ];
    }

    /**
     * @param string $queue Operations waiting on this link
     * @param string $boundary Encoder pause before the freeze
     * @param bool $renewed Whether an old freeze was actually lifted after the cut
     */
    #[DataProvider('boundaries')]
    public function testFreezeEnrichesOnlySnapshotsWhoseFirstPartHasNotLeft(string $queue, string $boundary, bool $renewed): void
    {
        $source = new WorkerClientFreezeRtContext();
        $source->configure();
        $source->bindStateCollectionNames();
        Hilos::$rt = $source;
        $text = $boundary === 'started' ? str_repeat(self::OLD_TEXT, RtSyncStreamFrame::MAX_PART_BYTES) : self::OLD_TEXT;
        $source->notes->add(HilosProbeNote::create(self::ROW, self::NODE, $text));
        $original = $source->notes[self::ROW]->toArray();
        $client = $this->worker();
        $stream = $queue === 'closed' ? null : $client->rtSyncStream();
        $marks = $boundary === 'empty-tail' ? [] : [self::KEPT_A => self::OLD, self::KEPT_B => self::OLD];
        if ($renewed) {
            $marks[self::ROW] = self::OLD;
            RtStaleness::mark(WorkerClientFreezeRtContext::NOTES, [self::ROW], self::OLD);
        }
        $count = match ($queue) { 'one' => 1, 'mixed' => 3, default => 0 };
        for ($index = 0; $index < $count; $index++) {
            $stream->sendSnapshot(WorkerClientFreezeRtContext::NOTES, RtSnapshotScope::whole(), staleRows: $marks);
            if ($queue === 'mixed') {
                $stream->sendSnapshot(self::OTHER, RtSnapshotScope::whole(), staleRows: [self::KEPT_A => self::OLD]);
                $stream->send(new RtSyncCreatedSignalData(WorkerClientFreezeRtContext::NOTES, self::ROW, [HilosProbeNote::text => self::OLD_TEXT]));
            }
        }
        if ($queue === 'other') {
            $stream->sendSnapshot(self::OTHER, RtSnapshotScope::whole(), staleRows: [self::KEPT_A => self::OLD]);
        } elseif ($queue === 'ordinary') {
            $stream->send(new RtSyncCreatedSignalData(WorkerClientFreezeRtContext::NOTES, self::ROW, [HilosProbeNote::text => self::OLD_TEXT]));
        }
        $otherClient = $this->worker();
        $otherStream = $otherClient->rtSyncStream();
        $otherStream->sendSnapshot(WorkerClientFreezeRtContext::NOTES, RtSnapshotScope::whole(), staleRows: $marks);
        $source->notes[self::ROW]->applyDiff([HilosProbeNote::text => 'changed after cut']);
        $source->notes->add(HilosProbeNote::create(self::LATE, self::NODE, 'not in cut'));
        if ($boundary !== 'queued') {
            $this->pause($client, $stream, $boundary);
        }
        if ($renewed) {
            RtStaleness::clear(WorkerClientFreezeRtContext::NOTES, [self::ROW]);
            $thaw = new WorkerRtStalenessMessageDTO(WorkerClientFreezeRtContext::NOTES, [self::ROW], null);
            $client->sendRtStaleness($thaw, $thaw->toJson());
        }
        RtStaleness::mark(WorkerClientFreezeRtContext::NOTES, [self::ROW, 'not-announced'], self::EFFECTIVE);
        $freeze = new WorkerRtStalenessMessageDTO(WorkerClientFreezeRtContext::NOTES, [self::ROW, self::FALLBACK], self::DELIVERED);
        $before = $this->messages($client);
        $client->sendRtStaleness($freeze, $freeze->toJson());
        $now = $this->messages($client);
        $this->assertCount(count($before) + 1, $now, 'The single freeze is written before pumping resumes');
        $this->assertSame($freeze->toArray(), $now[array_key_last($now)]->toArray());
        if ($queue === 'closed') {
            $this->assertNull(new ReflectionProperty(WorkerClient::class, 'rtSyncStream')->getValue($client));
        }
        $objects = $stream === null ? [] : $this->drain($client, $stream);
        $freezes = array_values(array_filter($this->messages($client), static fn (WorkerDTO $dto): bool =>
            $dto instanceof WorkerRtStalenessMessageDTO));
        $this->assertCount(1, $freezes, 'Neither a repeated freeze nor the canceled thaw is written');
        $seen = 0;
        foreach ($objects as $object) {
            if ($object[RtSyncStreamOperation::FIELD_SIGNAL_TYPE] !== RtSyncStreamSnapshot::SIGNAL_TYPE) {
                $this->assertSame([HilosProbeNote::text => self::OLD_TEXT], $object[RtSyncStreamOperation::FIELD_ROW]);
                continue;
            }
            if ($object[RtSyncStreamOperation::FIELD_COLLECTION_KEY] === self::OTHER) {
                $this->assertSame([self::KEPT_A => self::OLD], $object[RtSyncStreamSnapshot::FIELD_STALE_ROWS]);
                continue;
            }
            ++$seen;
            $expected = $marks;
            if ($boundary !== 'started' || $seen > 1) {
                $expected[self::ROW] = self::EFFECTIVE;
                $expected[self::FALLBACK] = self::DELIVERED;
            }
            $this->assertSame($expected, $object[RtSyncStreamSnapshot::FIELD_STALE_ROWS]);
            $this->assertSame([self::ROW => $original], $object[RtSyncStreamSnapshot::FIELD_ROWS], 'The original cut survives encoder pauses');
        }
        $this->assertSame($count, $seen);
        $otherObjects = $this->drain($otherClient, $otherStream);
        $this->assertSame($marks, $otherObjects[0][RtSyncStreamSnapshot::FIELD_STALE_ROWS]);
    }

    /** @return WorkerClient Real sender on a socket pair; all stream work waits for the case */
    private function worker(): WorkerClient
    {
        $sockets = [];
        $this->assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets));
        $this->peers[] = $sockets[1];
        $manager = new WorkerClientFreezeAgentManager();
        $server = new ReflectionClass(WorkerClientFreezeServer::class)->newInstanceWithoutConstructor();
        new ReflectionProperty(WorkerServer::class, 'agentManager')->setValue($server, $manager);
        $client = new WorkerClient($sockets[0], $manager, $server);
        $client->setRtSyncStreamWake(static function (): void {});
        $this->clients[] = $client;

        return $client;
    }

    /**
     * @param WorkerClient $client Connection whose actual write buffer is read
     * @return list<WorkerDTO> Decoded transport frames, without writing to the socket
     */
    private function messages(WorkerClient $client): array
    {
        $buffer = new ReflectionProperty(AbstractClient::class, 'writeBuffer')->getValue($client);

        return array_map(WorkerDTO::factoryWorkerDTO(...), array_values(array_filter(explode("\n", $buffer))));
    }

    /**
     * @param WorkerClient $client Connection whose first part must still wait, except in the started case
     * @param RtSyncStreamChannel $stream Link being encoded
     * @param string $boundary Exact buffer boundary to stop at
     */
    private function pause(WorkerClient $client, RtSyncStreamChannel $stream, string $boundary): void
    {
        if ($boundary === 'head') {
            $stream->pump(new RtWorkBudget(1, 4096));
        } else {
            $reached = false;
            for ($pass = 0; $pass < self::MAX_PASSES; $pass++) {
                $snapshot = new ReflectionProperty(RtSyncStreamChannel::class, 'sending')->getValue($stream);
                // Resuming the cut's own wait takes a step before the cut can use its allowance.
                $steps = $snapshot instanceof RtSyncStreamSnapshot && !$snapshot->isReadOut() ? 2 : 1;
                $stream->pump(new RtWorkBudget($steps, 4096));
                if ($boundary === 'started') {
                    $messages = $this->messages($client);
                    if ($messages !== []) {
                        $this->assertInstanceOf(WorkerRtSyncStreamDTO::class, $messages[0]);
                        $this->assertFalse($messages[0]->frame->final);
                        $reached = true;
                        break;
                    }
                }
                $encoder = new ReflectionProperty(RtSyncStreamChannel::class, 'encoder')->getValue($stream);
                if ($encoder === null) {
                    continue;
                }
                $buffer = new ReflectionProperty(RtSyncStreamEncoder::class, 'buffer')->getValue($encoder);
                $prefix = '"' . RtSyncStreamSnapshot::FIELD_STALE_ROWS . '":';
                if (!str_contains($buffer, $prefix)) {
                    continue;
                }
                $metadata = substr($buffer, strpos($buffer, $prefix) + strlen($prefix));
                $reached = match ($boundary) {
                    'middle' => str_contains($metadata, '"' . self::KEPT_A . '"') && !str_contains($metadata, '"' . self::KEPT_B . '"'),
                    'tail' => str_contains($metadata, '"' . self::KEPT_B . '"'),
                    'empty-tail' => $metadata === '',
                    default => false,
                };
                if ($boundary === 'tail') {
                    $snapshot = new ReflectionProperty(RtSyncStreamChannel::class, 'sending')->getValue($stream);
                    $reached = $reached && substr_count($metadata, ':') === count($snapshot->staleRows());
                }
                if ($reached) {
                    break;
                }
            }
            $this->assertTrue($reached, 'The requested encoder boundary is reached with structural steps');
        }
        $this->assertInstanceOf(RtSyncStreamEncoder::class, new ReflectionProperty(RtSyncStreamChannel::class, 'encoder')->getValue($stream));
        $this->assertSame($boundary === 'started' ? 0 : -1, new ReflectionProperty(RtSyncStreamChannel::class, 'sentPart')->getValue($stream));
        if ($boundary !== 'started') {
            $this->assertSame([], $this->messages($client));
        }
    }

    /**
     * @param WorkerClient $client Connection to collect frames from
     * @param RtSyncStreamChannel $stream Sending link to pump and answer
     * @return list<array<string, mixed>> Logical operations assembled from their actual parts
     */
    private function drain(WorkerClient $client, RtSyncStreamChannel $stream): array
    {
        $read = 0;
        $bytes = [];
        $objects = [];
        for ($pass = 0; $pass < self::MAX_PASSES; $pass++) {
            $stream->pump(new RtWorkBudget(32, 4096));
            $messages = $this->messages($client);
            while ($read < count($messages)) {
                $dto = $messages[$read++];
                if (!$dto instanceof WorkerRtSyncStreamDTO) {
                    continue;
                }
                $part = $dto->frame;
                $key = $part->streamId . ':' . $part->sequence;
                if ($part->part === 0) {
                    $bytes[$key] = '';
                }
                $bytes[$key] .= $part->data;
                if ($part->final) {
                    $objects[] = json_decode($bytes[$key], true, flags: JSON_THROW_ON_ERROR);
                }
                $stream->receive(RtSyncStreamFrame::ack($part->streamId, $part->sequence, $part->part,
                    $part->final ? RtSyncStreamStatus::Applied : RtSyncStreamStatus::Next));
            }
            if ($stream->isIdle()) {
                return $objects;
            }
        }
        $this->fail('The stream must finish under bounded passes');
    }
}

/** Minimal roster; the test creates no agents. */
final class WorkerClientFreezeAgentManager extends AgentManagerDaemon
{
    /**
     * @param string $agentType Unused agent type
     * @param ?string $agentIndex Unused instance
     * @return AgentDaemonInterface No agent is started by these cases
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new LogicException('This fixture starts no agents');
    }
}

/** Source of one link's pinned rows, independent of the master freshness store. */
final class WorkerClientFreezeRtContext extends RtContext
{
    public const string NOTES = 'freezeNotes';
    public readonly HilosProbeNotes $notes;

    /** Registers the probe source and its normal view. */
    public function configure(): void
    {
        $this->notes = HilosProbeNotes::init();
        $this->_stateCollections[self::NOTES] = $this->notes;
        $this->setRepresent(self::NOTES, ViewHilosProbeNotes::class, HilosProbeNotesActions::class, HilosProbeNoteActions::class);
    }
}

/** No listener is opened by the socket-pair fixture. */
final class WorkerClientFreezeServer extends WorkerServer
{
    /** This fixture never starts a listener. */
    protected function onStart(): void
    {
    }
}
