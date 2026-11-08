<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Closure;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\ReadyWork;
use Hilos\ProtectedMode\ClusterProtectedMode;
use Hilos\ProtectedMode\ProtectedModeExecutor;
use ReflectionProperty;
use Hilos\Cluster\Leadership;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeSyncDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeSyncedDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementQueryDTO;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Cluster\Placement\ClusterPlacement;
use Hilos\Cluster\Placement\PlacementExecutor;
use Hilos\ProtectedMode\ProtectedModeCoordinator;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\DTO\PeerDbReHydrateDTO;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeCircleDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeDisableDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeEnableDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeLiftDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModePassDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeAdmitDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeProgressDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeQuiesceDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeQuiescedDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeReadyDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeRefusedDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeRefreezeDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeSettledDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeVerifyDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeWindowDTO;
use Hilos\Hilos;
use Hilos\ProtectedMode\DTO\ProtectedModeEnableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeQuiesceData;
use Hilos\ProtectedMode\VerifierCircleSnapshot;
use Hilos\Runtime\View\Context\RtContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests the protected-mode peer transport frames (HIL-267 slices 3 and 4).
 *
 * The initiator↔leader hand-off cannot use the agent-signal fabric — a worker-sent signal never
 * reaches the leader daemon — so enable/ready/disable ride the peer channel instead, and their
 * cluster-wide mirror (quiesce/quiesced/settled/lift) rides it too as the leader freezes its followers.
 * These frames are thin envelopes over the domain payload DTOs; here we lock the wire shape and the
 * transport error on a malformed payload. Leader-side handling lands with the orchestration slices.
 */
final class ProtectedModePeerFrameTest extends TestCase
{
    use ProtectedModePeerFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mountProtectedModePeers();
    }

    protected function tearDown(): void
    {
        $this->restoreProtectedModePeers();
        parent::tearDown();
    }

    /** @param NodeRole $role Both peer roles share the same master loop binding */
    #[DataProvider('nativeSyncRoles')]
    public function testLateNativeRegistrationReplacementAndSameLoopAttachKeepExactOwner(NodeRole $role): void
    {
        $loop = new EventLoop();
        $server = $this->protectedModePeerServer($role);
        $server->attachEventLoop($loop);
        $first = new ClusterProtectedMode('node-a', $server, $this->createStub(ProtectedModeExecutor::class));
        $second = new ClusterProtectedMode('node-a', $server, $this->createStub(ProtectedModeExecutor::class));
        try {
            $server->registerProtectedMode($first);
            self::assertTrue($server->hasProtectedModeSyncReadyWork());
            $work = new ReflectionProperty(ClusterProtectedMode::class, 'slaveSyncWork')->getValue($first);
            $escaped = new ReflectionProperty(ReadyWork::class, 'callback')->getValue($work);
            $first->dispatchSlaveSyncReady();
            self::assertFalse($server->hasProtectedModeSyncReadyWork());
            $server->attachEventLoop($loop);
            self::assertSame($work, new ReflectionProperty(ClusterProtectedMode::class, 'slaveSyncWork')->getValue($first));
            $server->registerProtectedMode($second);
            self::assertTrue($work->isClosed());
            $second->dispatchSlaveSyncReady();
            $escaped();
            $first->detachSlaveSyncEventLoop();
            self::assertFalse($first->hasSlaveSyncReadyWork());
            self::assertFalse($server->hasProtectedModeSyncReadyWork());
            $second->noteSlaveSyncChanged();
            self::assertTrue($server->hasProtectedModeSyncReadyWork());
            $server->detachEventLoop();
            self::assertNull(new ReflectionProperty(ClusterProtectedMode::class, 'slaveSyncLoop')->getValue($second));
        } finally {
            $server->detachEventLoop();
            $loop->close();
        }
    }

    /** @return iterable<string, array{NodeRole}> Every clustered process owns the native sync seam */
    public static function nativeSyncRoles(): iterable
    {
        yield 'master' => [NodeRole::Master];
        yield 'slave' => [NodeRole::Slave];
    }

    #[DataProvider('syncFrames')]
    public function testSlaveSyncFramesRoundTripExactly(PeerDTO $frame): void
    {
        $restored = PeerDTO::fromWire($frame->toJson());
        $this->assertSame($frame::class, $restored::class);
        $this->assertSame($frame->toArray(), $restored->toArray());
        $wire = $frame->toArray();
        $this->assertArrayNotHasKey('acceptKey', $wire);
        $this->assertArrayNotHasKey('startedAt', $wire);
        if ($restored instanceof PeerProtectedModeSyncDTO && $restored->payload !== null) {
            $this->assertSame('generation', $restored->payload->freezeId);
            $this->assertSame(['pass'], $restored->passHashes);
            $this->assertSame(['admitted'], $restored->admittedSessionTokenHashes);
            $this->assertSame(2, $restored->circleNamedCount);
            $this->assertSame(['circle'], $restored->circleSessionTokenHashes);
        }
    }

    public static function syncFrames(): array
    {
        $cases = [];
        foreach (PeerProtectedModeSyncedDTO::PHASES as $phase) {
            $cases[] = [new PeerProtectedModeSyncedDTO('sync', $phase)];
            $inactive = $phase === ProtectedModeRuntime::PHASE_INACTIVE;
            $cases[] = [new PeerProtectedModeSyncDTO('sync', $phase,
                $inactive ? null : ProtectedModeRuntime::ENTRY_MODE_FREEZE,
                $inactive ? null : new ProtectedModeQuiesceData('restore', 'backup', null, 'slave', 'operator', 'generation'),
                !$inactive && $phase !== ProtectedModeRuntime::PHASE_ACTIVATING,
                $inactive ? [] : ['pass'], $inactive ? [] : ['admitted'], $inactive ? 0 : 2, $inactive ? [] : ['circle'])];
        }
        return $cases;
    }

    /**
     * @param string $phase Impossible direct-window phase
     * @param bool $closed Claimed confirmation state
     * @param bool $fromWire Whether to exercise the wire reader or constructor
     */
    #[DataProvider('impossibleDirectWindowSnapshots')]
    public function testDirectWindowRejectsImpossiblePhasesBeforeDispatch(string $phase, bool $closed, bool $fromWire): void
    {
        $previousRuntime = Hilos::$rt;
        Hilos::$rt = new ProtectedModePeerFrameTestRtContext();
        Hilos::$rt->mountFeatureRuntime([]);
        $server = $this->protectedModePeerServer(NodeRole::Slave);
        $placement = new ClusterPlacement('node-a', $server, $this->createStub(PlacementExecutor::class));
        Closure::bind(static function () use ($server, $placement): void {
            $server->placement = $placement;
        }, null, PeerServer::class)();
        $port = $this->createMock(ProtectedModeCoordinator::class);
        $port->expects($this->never())->method('onSync');
        $server->registerProtectedMode($port);
        $leader = $this->protectedModePeerLink($server, 'leader', NodeRole::Master);
        socket_write($leader[1], new PeerPlacementQueryDTO()->toJson() . "\n");
        $leader[0]->read();
        $this->protectedModePeerFrames($leader);
        $before = Hilos::$rt->hilosProtectedModeRuntime->toArray();
        $payload = new ProtectedModeQuiesceData('manual_maintenance', 'index', null, 'leader', null, 'generation');
        $wire = json_encode([
            'type' => PeerProtectedModeSyncDTO::MESSAGE_TYPE, 'syncId' => 'bad-direct',
            'phase' => $phase, 'entryMode' => ProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW,
            'payload' => $payload->toArray(), 'barrierClosed' => $closed, 'passHashes' => [],
            'admittedSessionTokenHashes' => [], 'circleNamedCount' => 0, 'circleSessionTokenHashes' => [],
        ], JSON_THROW_ON_ERROR);

        try {
            if ($fromWire) {
                PeerDTO::fromWire($wire);
            } else {
                new PeerProtectedModeSyncDTO('bad-direct', $phase, ProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW,
                    $payload, $closed, [], [], 0, []);
            }
            $this->fail('An impossible direct window must be refused at the DTO boundary');
        } catch (PeerTransportException) {
            socket_write($leader[1], $wire . "\n");
            $leader[0]->read();
            $this->assertTrue($leader[0]->shouldClose(), 'The authenticated transport refuses the malformed snapshot before dispatch');
            $this->assertSame($before, Hilos::$rt->hilosProtectedModeRuntime->toArray());
            $this->assertSame([], $this->protectedModePeerFrames($leader));
        } finally {
            Hilos::$rt = $previousRuntime;
        }
    }

    /** @return iterable<string, array{string, bool, bool}> Impossible phases through both entry paths */
    public static function impossibleDirectWindowSnapshots(): iterable
    {
        foreach ([ProtectedModeRuntime::PHASE_ACTIVATING, ProtectedModeRuntime::PHASE_ACTIVE, ProtectedModeRuntime::PHASE_DEACTIVATING] as $phase) {
            foreach ([false, true] as $closed) {
                foreach ([false, true] as $fromWire) {
                    yield $phase . '-' . (int)$closed . '-' . (int)$fromWire => [$phase, $closed, $fromWire];
                }
            }
        }
    }

    /** @param bool $closed Whether the direct window's peer confirmations finished */
    #[DataProvider('directWindowConfirmationStates')]
    public function testDirectWindowVerifyingRoundTripsBeforeAndAfterConfirmations(bool $closed): void
    {
        $frame = new PeerProtectedModeSyncDTO('direct', ProtectedModeRuntime::PHASE_VERIFYING,
            ProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW,
            new ProtectedModeQuiesceData('manual_maintenance', 'index', null, 'leader', null, 'generation'),
            $closed, ['pass'], ['admitted'], 2, ['circle']);
        $this->assertSame($frame->toArray(), PeerDTO::fromWire($frame->toJson())->toArray());
    }

    /** @return iterable<string, array{bool}> Both valid confirmation states */
    public static function directWindowConfirmationStates(): iterable
    {
        yield 'confirmations pending' => [false];
        yield 'confirmations closed' => [true];
    }

    #[DataProvider('malformedSlaveSyncFrames')]
    public function testMalformedSlaveSynchronizationIsATransportError(array $frame): void
    {
        $this->expectException(PeerTransportException::class);
        PeerDTO::fromWire(json_encode($frame, JSON_THROW_ON_ERROR));
    }

    public static function malformedSlaveSyncFrames(): array
    {
        $active = new PeerProtectedModeSyncDTO('sync', ProtectedModeRuntime::PHASE_ACTIVE, ProtectedModeRuntime::ENTRY_MODE_FREEZE,
            new ProtectedModeQuiesceData('restore', 'backup', null, 'slave', null, 'generation'), true, [], [], 0, [])->toArray();
        $cases = [];
        foreach (['syncId' => '', 'phase' => 'unknown', 'entryMode' => 'unknown', 'payload' => null,
            'barrierClosed' => 'yes', 'passHashes' => [''], 'admittedSessionTokenHashes' => ['key' => 'hash'],
            'circleNamedCount' => -1, 'circleSessionTokenHashes' => [3]] as $key => $value) {
            $cases[] = [array_replace($active, [$key => $value])];
        }
        $cases[] = [array_replace($active, ['payload' => ['operation' => 'restore', 'initiatorAgentType' => 'backup', 'freezeId' => null]])];
        $cases[] = [array_replace($active, ['phase' => ProtectedModeRuntime::PHASE_INACTIVE])];
        $cases[] = [array_replace($active, ['phase' => ProtectedModeRuntime::PHASE_ACTIVATING])];
        $cases[] = [array_replace($active, ['barrierClosed' => false])];
        $cases[] = [array_replace($active, ['phase' => ProtectedModeRuntime::PHASE_VERIFYING, 'barrierClosed' => false])];
        $cases[] = [['type' => PeerProtectedModeSyncedDTO::MESSAGE_TYPE, 'syncId' => 'sync', 'phase' => 'unknown']];
        $cases[] = [['type' => PeerProtectedModeSyncedDTO::MESSAGE_TYPE, 'syncId' => '', 'phase' => ProtectedModeRuntime::PHASE_ACTIVE]];
        return $cases;
    }

    public function testSyncDispatchRequiresASlaveAndItsCurrentLeaderOnTheInstalledAuthenticatedLink(): void
    {
        $server = $this->protectedModePeerServer(NodeRole::Slave);
        $placement = new ClusterPlacement('node-a', $server, $this->createStub(PlacementExecutor::class));
        Closure::bind(static function () use ($server, $placement): void {
            $server->placement = $placement;
        }, null, PeerServer::class)();
        $leader = $this->protectedModePeerLink($server, 'leader', NodeRole::Master);
        $old = $this->protectedModePeerLink($server, 'old', NodeRole::Master);
        $slave = $this->protectedModePeerLink($server, 'slave', NodeRole::Slave);
        $candidate = $this->protectedModePeerLink($server, 'candidate', NodeRole::Master, ready: false);
        socket_write($leader[1], new PeerPlacementQueryDTO()->toJson() . "\n");
        $leader[0]->read();
        $port = $this->createMock(ProtectedModeCoordinator::class);
        $port->expects($this->once())->method('onSync')->with('leader', $this->isInstanceOf(PeerProtectedModeSyncDTO::class));
        $server->registerProtectedMode($port);
        $frame = new PeerProtectedModeSyncDTO('sync', ProtectedModeRuntime::PHASE_INACTIVE, null, null, false, [], [], 0, []);
        foreach ([$old, $slave, $leader] as [$link, $far]) {
            socket_write($far, $frame->toJson() . "\n");
            $link->read();
        }
        $server->onProtectedModeSyncReceived($candidate[0], $frame);
        $this->assertTrue($server->isProtectedModeSyncCurrent('leader', 'sync'));
        $leader[0]->markShouldClose();
        $this->assertFalse($server->isProtectedModeSyncCurrent('leader', 'sync'));
        $server->onProtectedModeSyncReceived($leader[0], $frame);

        $masterServer = $this->protectedModePeerServer(NodeRole::Master);
        $remote = $this->protectedModePeerLink($masterServer, 'leader', NodeRole::Master);
        $masterPort = $this->createMock(ProtectedModeCoordinator::class);
        $masterPort->expects($this->never())->method('onSync');
        $masterServer->registerProtectedMode($masterPort);
        $masterServer->onProtectedModeSyncReceived($remote[0], $frame);
    }

    public function testSyncedDispatchRequiresCurrentLeadershipSlaveRequestAndTheExactActiveConnection(): void
    {
        $leaderId = 'node-a';
        $leadership = $this->createStub(Leadership::class);
        // A mutable leader variable lets the same request outlive a leadership change.
        $leadership->method('leaderId')->willReturnCallback(static function () use (&$leaderId): string { return $leaderId; });
        Hilos::$cluster->registerLeadership($leadership);
        $server = $this->protectedModePeerServer();
        $slave = $this->protectedModePeerLink($server, 'slave', NodeRole::Slave);
        $master = $this->protectedModePeerLink($server, 'master', NodeRole::Master);
        $port = $this->createMock(ProtectedModeCoordinator::class);
        $port->expects($this->once())->method('onSynced')->with('slave', $this->isInstanceOf(PeerProtectedModeSyncedDTO::class));
        $server->registerProtectedMode($port);
        $frame = new PeerProtectedModeSyncDTO('sync', ProtectedModeRuntime::PHASE_INACTIVE, null, null, false, [], [], 0, []);
        $server->sendProtectedModeSync('slave', $frame);
        $server->sendProtectedModeSync('master', $frame);
        $this->assertCount(1, array_filter($this->protectedModePeerFrames($slave),
            static fn(PeerDTO $item): bool => $item instanceof PeerProtectedModeSyncDTO));
        $this->assertSame([], array_values(array_filter($this->protectedModePeerFrames($master),
            static fn(PeerDTO $item): bool => $item instanceof PeerProtectedModeSyncDTO)));
        $ack = new PeerProtectedModeSyncedDTO('sync', ProtectedModeRuntime::PHASE_INACTIVE);
        $server->onProtectedModeSyncedReceived($master[0], $ack);
        $server->onProtectedModeSyncedReceived($slave[0], new PeerProtectedModeSyncedDTO('old', $ack->phase));
        $leaderId = 'other';
        $server->onProtectedModeSyncedReceived($slave[0], $ack);
        $leaderId = 'node-a';
        // A new authenticated stream for the same node still does not inherit the old request.
        $slave[0]->markShouldClose();
        $replacement = $this->protectedModePeerLink($server, 'slave', NodeRole::Slave);
        $server->onProtectedModeSyncedReceived($slave[0], $ack);
        $server->onProtectedModeSyncedReceived($replacement[0], $ack);
        $nextFrame = new PeerProtectedModeSyncDTO('new-sync', ProtectedModeRuntime::PHASE_INACTIVE, null, null, false, [], [], 0, []);
        $server->sendProtectedModeSync('slave', $nextFrame);
        $server->onProtectedModeSyncedReceived($replacement[0], $ack);
        socket_write($replacement[1], new PeerProtectedModeSyncedDTO('new-sync', $ack->phase)->toJson() . "\n");
        $replacement[0]->read();
    }

    public function testBarrierSelectionUsesWorkingLinksOfBothRolesRatherThanRegistryFlags(): void
    {
        $server = $this->protectedModePeerServer();
        $this->protectedModePeerLink($server, 'master', NodeRole::Master);
        $slave = $this->protectedModePeerLink($server, 'slave', NodeRole::Slave);
        $this->protectedModePeerLink($server, 'candidate', NodeRole::Slave, ready: false);
        Hilos::$cluster->registry()->merge(NodeIdentity::of('offline', 'Offline', NodeRole::Slave, []), false, 0.0);
        Hilos::$cluster->registry()->merge(NodeIdentity::of('unlinked', 'Unlinked', NodeRole::Master, []), true, 0.0);

        $this->assertSame(['master', 'slave'], $server->followerNodeIds());
        $this->assertSame(['node-a', 'master', 'unlinked'], $server->onlineMasterIds());

        $slave[0]->markShouldClose();
        $this->assertSame(['master'], $server->followerNodeIds());
    }

    public function testProtectedModeAndDatabaseBroadcastsReachBothRolesInOrder(): void
    {
        $server = $this->protectedModePeerServer();
        $master = $this->protectedModePeerLink($server, 'master', NodeRole::Master);
        $slave = $this->protectedModePeerLink($server, 'slave', NodeRole::Slave);
        $this->protectedModePeerFrames($master);
        $this->protectedModePeerFrames($slave);
        $freeze = new ProtectedModeQuiesceData('restore', 'backup', null, 'slave', null, 'generation-a');

        $server->broadcastQuiesce($freeze);
        $server->broadcastSettled();
        $server->broadcastDbReHydrate(5);
        $server->broadcastVerify('backup', null);
        $server->broadcastPass('backup', null, 'pass-hash');
        $server->broadcastAdmit('pass-hash', 'session-hash');
        $server->broadcastCircle('backup', null, new VerifierCircleSnapshot(1, ['session-hash']));
        $server->broadcastLift();
        $server->broadcastWindow($freeze->withFreezeId('generation-b'));

        $expected = [
            PeerProtectedModeQuiesceDTO::MESSAGE_TYPE,
            PeerProtectedModeSettledDTO::MESSAGE_TYPE,
            PeerDbReHydrateDTO::MESSAGE_TYPE,
            PeerProtectedModeVerifyDTO::MESSAGE_TYPE,
            PeerProtectedModePassDTO::MESSAGE_TYPE,
            PeerProtectedModeAdmitDTO::MESSAGE_TYPE,
            PeerProtectedModeCircleDTO::MESSAGE_TYPE,
            PeerProtectedModeLiftDTO::MESSAGE_TYPE,
            PeerProtectedModeWindowDTO::MESSAGE_TYPE,
        ];
        foreach ([$master, $slave] as $pair) {
            $frames = $this->protectedModePeerFrames($pair);
            $this->assertSame($expected, array_map(static fn(PeerDTO $frame): string => $frame->getType(), $frames));
            $this->assertSame('generation-a', $frames[0]->data->freezeId);
            $this->assertSame('generation-b', $frames[8]->data->freezeId);
            $this->assertSame(5, $frames[2]->round);
            $this->assertSame(['session-hash'], $frames[6]->snapshot->sessionTokenHashes);
        }
    }

    public function testReadyRefusedAndQuiescedAreAddressedToASlave(): void
    {
        $server = $this->protectedModePeerServer();
        $master = $this->protectedModePeerLink($server, 'master', NodeRole::Master);
        $slave = $this->protectedModePeerLink($server, 'slave', NodeRole::Slave);
        $this->protectedModePeerFrames($master);
        $this->protectedModePeerFrames($slave);

        $server->sendReady('slave');
        $server->sendRefused('slave', 'The freeze is occupied.');
        $server->sendQuiesced('slave');

        $this->assertSame([], $this->protectedModePeerFrames($master));
        $frames = $this->protectedModePeerFrames($slave);
        $this->assertSame([
            PeerProtectedModeReadyDTO::MESSAGE_TYPE,
            PeerProtectedModeRefusedDTO::MESSAGE_TYPE,
            PeerProtectedModeQuiescedDTO::MESSAGE_TYPE,
        ], array_map(static fn(PeerDTO $frame): string => $frame->getType(), $frames));
        $this->assertSame('The freeze is occupied.', $frames[1]->reason);
    }

    public function testConsensusBroadcastStillReachesOnlyMasters(): void
    {
        $server = $this->protectedModePeerServer();
        $master = $this->protectedModePeerLink($server, 'master', NodeRole::Master);
        $slave = $this->protectedModePeerLink($server, 'slave', NodeRole::Slave);
        $this->protectedModePeerFrames($master);
        $this->protectedModePeerFrames($slave);

        $server->broadcastToMasters(new PeerProtectedModeReadyDTO());

        $frames = $this->protectedModePeerFrames($master);
        $this->assertCount(1, $frames);
        $this->assertInstanceOf(PeerProtectedModeReadyDTO::class, $frames[0]);
        $this->assertSame([], $this->protectedModePeerFrames($slave));
    }

    public function testDescriptorCopiesPreserveGenerationAndStandaloneAllowsNull(): void
    {
        $standalone = new ProtectedModeQuiesceData('restore', 'backup', null, null, null);
        $this->assertNull(ProtectedModeQuiesceData::fromArray($standalone->toArray())->freezeId);
        $cluster = $standalone->withFreezeId('generation-a');
        $this->assertSame('generation-a', $cluster->withInitiatorSessionTokenHash('operator')->freezeId);
        $this->assertSame('generation-a', ProtectedModeQuiesceData::fromArray($cluster->toArray())->freezeId);
        $this->assertSame('generation-b', $cluster->withFreezeId('generation-b')->freezeId);
        $this->assertSame('generation-a', $cluster->freezeId);
    }

    /**
     * @param class-string<PeerDTO> $frameClass Cluster descriptor envelope
     * @param array<string, mixed> $generation Missing or malformed generation field
     */
    #[DataProvider('invalidGenerations')]
    public function testClusterDescriptorsRejectMissingEmptyAndMistypedGenerations(string $frameClass, array $generation): void
    {
        $this->expectException(PeerTransportException::class);
        $frameClass::fromArray([
            PeerProtectedModeQuiesceDTO::FIELD_PAYLOAD => [
                ProtectedModeQuiesceData::operation => 'restore',
                ProtectedModeQuiesceData::initiatorAgentType => 'backup',
                ...$generation,
            ],
        ]);
    }

    /** @return iterable<string, array{class-string<PeerDTO>, array<string, mixed>}> Invalid cluster generations */
    public static function invalidGenerations(): iterable
    {
        foreach ([PeerProtectedModeQuiesceDTO::class, PeerProtectedModeWindowDTO::class] as $frameClass) {
            foreach ([
                'missing' => [],
                'null' => [ProtectedModeQuiesceData::freezeId => null],
                'empty' => [ProtectedModeQuiesceData::freezeId => ''],
                'blank' => [ProtectedModeQuiesceData::freezeId => '   '],
                'number' => [ProtectedModeQuiesceData::freezeId => 7],
            ] as $case => $generation) {
                yield $frameClass . ':' . $case => [$frameClass, $generation];
            }
        }
    }

    public function testEnableFrameRoundTripsThroughTheWire(): void
    {
        $frame = new PeerProtectedModeEnableDTO(new ProtectedModeEnableSignalData(
            operation: 'restore',
            initiatorAcceptKey: 'accept-9',
            initiatorSessionTokenHash: null,
            initiatorAgentType: 'backup',
            initiatorAgentIndex: 0,
            initiatorNodeId: 'node-a',
        ));

        $restored = PeerProtectedModeEnableDTO::fromJson($frame->toJson());

        $this->assertSame(PeerProtectedModeEnableDTO::MESSAGE_TYPE, $restored->getType());
        $this->assertSame('restore', $restored->data->operation);
        $this->assertSame('accept-9', $restored->data->initiatorAcceptKey);
        $this->assertSame('backup', $restored->data->initiatorAgentType);
        $this->assertSame(0, $restored->data->initiatorAgentIndex);
        $this->assertSame('node-a', $restored->data->initiatorNodeId);
    }

    public function testEnableFrameKeepsNullAgentIndex(): void
    {
        $frame = new PeerProtectedModeEnableDTO(new ProtectedModeEnableSignalData(
            operation: 'restore',
            initiatorAcceptKey: 'accept-9',
            initiatorSessionTokenHash: null,
            initiatorAgentType: 'backup',
            initiatorAgentIndex: null,
            initiatorNodeId: 'node-a',
        ));

        $restored = PeerProtectedModeEnableDTO::fromArray($frame->toArray());

        $this->assertNull($restored->data->initiatorAgentIndex);
    }

    public function testEnableFrameRejectsNonObjectPayload(): void
    {
        $this->expectException(PeerTransportException::class);

        PeerProtectedModeEnableDTO::fromArray([
            PeerProtectedModeEnableDTO::FIELD_PAYLOAD => 'not-an-object',
        ]);
    }

    public function testReadyFrameRoundTripsAsEmptyPayload(): void
    {
        $frame = new PeerProtectedModeReadyDTO();

        $restored = PeerProtectedModeReadyDTO::fromJson($frame->toJson());

        $this->assertSame(PeerProtectedModeReadyDTO::MESSAGE_TYPE, $restored->getType());
        $this->assertSame([], $restored->data->toArray());
    }

    public function testInitiatorFramesRoundTripWithIdentityAndNullableIndex(): void
    {
        foreach ([0, null] as $index) {
            $frames = [
                new PeerProtectedModeDisableDTO('backup', $index),
                new PeerProtectedModeVerifyDTO('backup', $index),
                new PeerProtectedModeProgressDTO('backup', $index),
                new PeerProtectedModeRefreezeDTO('backup', $index),
                new PeerProtectedModePassDTO('backup', $index, 'pass-hash'),
                new PeerProtectedModeCircleDTO('backup', $index, new VerifierCircleSnapshot(1, ['session-hash'])),
            ];

            foreach ($frames as $frame) {
                $restored = PeerDTO::fromWire($frame->toJson());

                $this->assertSame($frame::class, $restored::class);
                $this->assertSame('backup', $restored->initiatorAgentType);
                $this->assertSame($index, $restored->initiatorAgentIndex);
                $this->assertArrayHasKey('initiatorAgentIndex', $restored->toArray());
            }
        }
    }

    public function testInitiatorFrameWithoutIdentityIsRejected(): void
    {
        $this->expectException(PeerTransportException::class);

        PeerProtectedModeDisableDTO::fromArray([PeerDTO::TYPE => PeerProtectedModeDisableDTO::MESSAGE_TYPE]);
    }

    public function testEnableFrameDispatchesThroughTheSharedWireParser(): void
    {
        $frame = new PeerProtectedModeEnableDTO(new ProtectedModeEnableSignalData(
            operation: 'restore',
            initiatorAcceptKey: 'accept-9',
            initiatorSessionTokenHash: null,
            initiatorAgentType: 'backup',
            initiatorAgentIndex: 2,
            initiatorNodeId: 'node-a',
        ));

        $parsed = PeerDTO::fromWire($frame->toJson());

        $this->assertInstanceOf(PeerProtectedModeEnableDTO::class, $parsed);
        $this->assertSame('restore', $parsed->data->operation);
        $this->assertSame(2, $parsed->data->initiatorAgentIndex);
        $this->assertSame('node-a', $parsed->data->initiatorNodeId);
    }

    public function testReadyFrameDispatchesThroughTheSharedWireParser(): void
    {
        $parsed = PeerDTO::fromWire(new PeerProtectedModeReadyDTO()->toJson());

        $this->assertInstanceOf(PeerProtectedModeReadyDTO::class, $parsed);
    }

    public function testDisableFrameDispatchesThroughTheSharedWireParser(): void
    {
        $parsed = PeerDTO::fromWire(new PeerProtectedModeDisableDTO('backup', 0)->toJson());

        $this->assertInstanceOf(PeerProtectedModeDisableDTO::class, $parsed);
    }

    public function testQuiesceFrameRoundTripsThroughTheWire(): void
    {
        $frame = new PeerProtectedModeQuiesceDTO(new ProtectedModeQuiesceData(
            operation: 'restore',
            initiatorAgentType: 'backup',
            initiatorAgentIndex: 3,
            initiatorNodeId: 'node-a',
            initiatorSessionTokenHash: 'operator-hash',
            freezeId: 'freeze-fixture',
        ));

        $restored = PeerProtectedModeQuiesceDTO::fromJson($frame->toJson());

        $this->assertSame(PeerProtectedModeQuiesceDTO::MESSAGE_TYPE, $restored->getType());
        $this->assertSame('restore', $restored->data->operation);
        $this->assertSame('backup', $restored->data->initiatorAgentType);
        $this->assertSame(3, $restored->data->initiatorAgentIndex);
        $this->assertSame('node-a', $restored->data->initiatorNodeId);
        $this->assertSame('operator-hash', $restored->data->initiatorSessionTokenHash);
        $this->assertSame('freeze-fixture', $restored->data->freezeId);
    }

    public function testQuiesceFrameKeepsNullAgentIndex(): void
    {
        $frame = new PeerProtectedModeQuiesceDTO(new ProtectedModeQuiesceData(
            operation: 'restore',
            initiatorAgentType: 'backup',
            initiatorAgentIndex: null,
            initiatorNodeId: 'node-a',
            initiatorSessionTokenHash: null,
            freezeId: 'freeze-fixture',
        ));

        $restored = PeerProtectedModeQuiesceDTO::fromArray($frame->toArray());

        $this->assertNull($restored->data->initiatorAgentIndex);
        $this->assertNull($restored->data->initiatorSessionTokenHash);
    }

    public function testWindowFrameRoundTripsThroughTheWire(): void
    {
        foreach ([3, null] as $index) {
            $frame = new PeerProtectedModeWindowDTO(new ProtectedModeQuiesceData(
                operation: 'manual_maintenance',
                initiatorAgentType: 'index',
                initiatorAgentIndex: $index,
                initiatorNodeId: 'node-a',
                initiatorSessionTokenHash: 'operator-hash',
                freezeId: 'freeze-fixture',
            ));

            $restored = PeerProtectedModeWindowDTO::fromJson($frame->toJson());

            $this->assertSame('peer_protected_mode_window', $restored->getType());
            $this->assertSame([
                PeerDTO::TYPE => 'peer_protected_mode_window',
                PeerProtectedModeWindowDTO::FIELD_PAYLOAD => $frame->data->toArray(),
            ], $restored->toArray());
            $this->assertSame('manual_maintenance', $restored->data->operation);
            $this->assertSame('index', $restored->data->initiatorAgentType);
            $this->assertSame($index, $restored->data->initiatorAgentIndex);
            $this->assertSame('node-a', $restored->data->initiatorNodeId);
            $this->assertSame('operator-hash', $restored->data->initiatorSessionTokenHash);
            $this->assertSame('freeze-fixture', $restored->data->freezeId);
        }
    }

    public function testWindowFrameKeepsANullOperatorSessionHash(): void
    {
        $frame = new PeerProtectedModeWindowDTO(new ProtectedModeQuiesceData(
            'manual_maintenance', 'index', null, 'node-a', null,
            freezeId: 'freeze-fixture',
        ));

        $restored = PeerProtectedModeWindowDTO::fromArray($frame->toArray());

        $this->assertNull($restored->data->initiatorSessionTokenHash);
    }

    public function testWindowFrameRejectsNonObjectPayload(): void
    {
        $this->expectException(PeerTransportException::class);
        $this->expectExceptionMessage('non-object payload');

        PeerProtectedModeWindowDTO::fromArray([
            PeerProtectedModeWindowDTO::FIELD_PAYLOAD => 'not-an-object',
        ]);
    }

    public function testWindowFrameRejectsMissingPayload(): void
    {
        $this->expectException(PeerTransportException::class);
        $this->expectExceptionMessage('window frame is malformed');

        PeerProtectedModeWindowDTO::fromArray([]);
    }

    public function testWindowFrameRejectsIncompletePayload(): void
    {
        $this->expectException(PeerTransportException::class);
        $this->expectExceptionMessage('window frame is malformed');

        PeerProtectedModeWindowDTO::fromArray([
            PeerProtectedModeWindowDTO::FIELD_PAYLOAD => [ProtectedModeQuiesceData::operation => 'manual_maintenance'],
        ]);
    }

    public function testWindowFrameDispatchesThroughTheSharedWireParser(): void
    {
        $frame = new PeerProtectedModeWindowDTO(new ProtectedModeQuiesceData(
            'manual_maintenance', 'index', 1, 'node-a', 'operator-hash',
            freezeId: 'freeze-fixture',
        ));

        $parsed = PeerDTO::fromWire($frame->toJson());

        $this->assertInstanceOf(PeerProtectedModeWindowDTO::class, $parsed);
        $this->assertSame('manual_maintenance', $parsed->data->operation);
        $this->assertSame('index', $parsed->data->initiatorAgentType);
        $this->assertSame(1, $parsed->data->initiatorAgentIndex);
        $this->assertSame('node-a', $parsed->data->initiatorNodeId);
        $this->assertSame('operator-hash', $parsed->data->initiatorSessionTokenHash);
    }

    public function testAdmissionFrameRoundTripsAndDispatchesThroughTheWireParser(): void
    {
        $frame = new PeerProtectedModeAdmitDTO('pass-hash', 'session-hash');

        $restored = PeerDTO::fromWire($frame->toJson());

        $this->assertInstanceOf(PeerProtectedModeAdmitDTO::class, $restored);
        $this->assertSame('pass-hash', $restored->passHash);
        $this->assertSame('session-hash', $restored->sessionTokenHash);
    }

    public function testAdmissionFrameRejectsMissingOrEmptyHashes(): void
    {
        foreach ([
            [PeerProtectedModeAdmitDTO::TYPE => PeerProtectedModeAdmitDTO::MESSAGE_TYPE,
                PeerProtectedModeAdmitDTO::FIELD_PASS_HASH => 'pass-hash'],
            [PeerProtectedModeAdmitDTO::TYPE => PeerProtectedModeAdmitDTO::MESSAGE_TYPE,
                PeerProtectedModeAdmitDTO::FIELD_PASS_HASH => 'pass-hash',
                PeerProtectedModeAdmitDTO::FIELD_SESSION_TOKEN_HASH => ''],
        ] as $frame) {
            try {
                PeerProtectedModeAdmitDTO::fromArray($frame);
                $this->fail('Malformed admission frame was accepted.');
            } catch (PeerTransportException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testQuiesceFrameRejectsNonObjectPayload(): void
    {
        $this->expectException(PeerTransportException::class);

        PeerProtectedModeQuiesceDTO::fromArray([
            PeerProtectedModeQuiesceDTO::FIELD_PAYLOAD => 'not-an-object',
        ]);
    }

    public function testQuiescedFrameRoundTripsAsEmptyPayload(): void
    {
        $restored = PeerProtectedModeQuiescedDTO::fromJson(new PeerProtectedModeQuiescedDTO()->toJson());

        $this->assertSame(PeerProtectedModeQuiescedDTO::MESSAGE_TYPE, $restored->getType());
    }

    public function testSettledFrameRoundTripsAsEmptyPayload(): void
    {
        $restored = PeerProtectedModeSettledDTO::fromJson(new PeerProtectedModeSettledDTO()->toJson());

        $this->assertSame(PeerProtectedModeSettledDTO::MESSAGE_TYPE, $restored->getType());
    }

    public function testLiftFrameRoundTripsAsEmptyPayload(): void
    {
        $restored = PeerProtectedModeLiftDTO::fromJson(new PeerProtectedModeLiftDTO()->toJson());

        $this->assertSame(PeerProtectedModeLiftDTO::MESSAGE_TYPE, $restored->getType());
    }

    public function testQuiesceFrameDispatchesThroughTheSharedWireParser(): void
    {
        $frame = new PeerProtectedModeQuiesceDTO(new ProtectedModeQuiesceData(
            operation: 'restore',
            initiatorAgentType: 'backup',
            initiatorAgentIndex: 1,
            initiatorNodeId: 'node-a',
            initiatorSessionTokenHash: null,
            freezeId: 'freeze-fixture',
        ));

        $parsed = PeerDTO::fromWire($frame->toJson());

        $this->assertInstanceOf(PeerProtectedModeQuiesceDTO::class, $parsed);
        $this->assertSame('restore', $parsed->data->operation);
        $this->assertSame(1, $parsed->data->initiatorAgentIndex);
        $this->assertSame('node-a', $parsed->data->initiatorNodeId);
    }

    public function testQuiescedFrameDispatchesThroughTheSharedWireParser(): void
    {
        $parsed = PeerDTO::fromWire(new PeerProtectedModeQuiescedDTO()->toJson());

        $this->assertInstanceOf(PeerProtectedModeQuiescedDTO::class, $parsed);
    }

    public function testSettledFrameDispatchesThroughTheSharedWireParser(): void
    {
        $parsed = PeerDTO::fromWire(new PeerProtectedModeSettledDTO()->toJson());

        $this->assertInstanceOf(PeerProtectedModeSettledDTO::class, $parsed);
    }

    public function testLiftFrameDispatchesThroughTheSharedWireParser(): void
    {
        $parsed = PeerDTO::fromWire(new PeerProtectedModeLiftDTO()->toJson());

        $this->assertInstanceOf(PeerProtectedModeLiftDTO::class, $parsed);
    }

    public function testCircleFrameRoundTripsThroughTheWire(): void
    {
        $frame = new PeerProtectedModeCircleDTO('backup', 0, new VerifierCircleSnapshot(3, ['hash-a', 'hash-b']));

        $restored = PeerProtectedModeCircleDTO::fromJson($frame->toJson());

        $this->assertSame(PeerProtectedModeCircleDTO::MESSAGE_TYPE, $restored->getType());
        $this->assertSame(3, $restored->snapshot->namedCount);
        $this->assertSame(['hash-a', 'hash-b'], $restored->snapshot->sessionTokenHashes);
    }

    public function testCircleFrameDispatchesThroughTheSharedWireParser(): void
    {
        // Named but nobody online: the count alone still travels, so a node can tell "nobody was
        // named" from "nobody named was online".
        $frame = new PeerProtectedModeCircleDTO('backup', null, new VerifierCircleSnapshot(1, []));

        $parsed = PeerDTO::fromWire($frame->toJson());

        $this->assertInstanceOf(PeerProtectedModeCircleDTO::class, $parsed);
        $this->assertSame(1, $parsed->snapshot->namedCount);
        $this->assertSame([], $parsed->snapshot->sessionTokenHashes);
    }

    public function testCircleFrameRejectsANonIntegerCount(): void
    {
        $this->expectException(PeerTransportException::class);

        PeerProtectedModeCircleDTO::fromArray([
            PeerProtectedModeCircleDTO::FIELD_INITIATOR_AGENT_TYPE => 'backup',
            PeerProtectedModeCircleDTO::FIELD_INITIATOR_AGENT_INDEX => null,
            PeerProtectedModeCircleDTO::FIELD_NAMED_COUNT => '1',
            PeerProtectedModeCircleDTO::FIELD_SESSION_TOKEN_HASHES => [],
        ]);
    }

    public function testCircleFrameRejectsAHashListThatIsNotAList(): void
    {
        $this->expectException(PeerTransportException::class);

        PeerProtectedModeCircleDTO::fromArray([
            PeerProtectedModeCircleDTO::FIELD_INITIATOR_AGENT_TYPE => 'backup',
            PeerProtectedModeCircleDTO::FIELD_INITIATOR_AGENT_INDEX => null,
            PeerProtectedModeCircleDTO::FIELD_NAMED_COUNT => 1,
            PeerProtectedModeCircleDTO::FIELD_SESSION_TOKEN_HASHES => 'hash-a',
        ]);
    }

    public function testCircleFrameRefusesTheWholeListOverOneMalformedHash(): void
    {
        // Thinning the list would lock out a person the photograph named.
        $this->expectException(PeerTransportException::class);

        PeerProtectedModeCircleDTO::fromArray([
            PeerProtectedModeCircleDTO::FIELD_INITIATOR_AGENT_TYPE => 'backup',
            PeerProtectedModeCircleDTO::FIELD_INITIATOR_AGENT_INDEX => null,
            PeerProtectedModeCircleDTO::FIELD_NAMED_COUNT => 2,
            PeerProtectedModeCircleDTO::FIELD_SESSION_TOKEN_HASHES => ['hash-a', 7],
        ]);
    }
}

/** No project state is needed to observe the framework freeze row at the wire boundary. */
final class ProtectedModePeerFrameTestRtContext extends RtContext
{
    /** This fixture mounts only the framework-owned feature rows. */
    public function configure(): void
    {
    }
}
