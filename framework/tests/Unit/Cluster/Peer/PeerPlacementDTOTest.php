<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\Peer\DTO\PeerAgentRestartDTO;
use Hilos\Cluster\Peer\DTO\PeerAgentStatusDTO;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerPlaceAgentDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacedAgentEntry;
use Hilos\Cluster\Peer\DTO\PeerPlacementQueryDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementReleaseDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementReportDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementVerdictDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementViewDTO;
use Hilos\Cluster\Peer\DTO\PeerStopAgentDTO;
use Hilos\Cluster\Peer\PeerProtocol;
use Hilos\Cluster\Placement\PlacementSnapshot;
use Hilos\Cluster\Placement\PlacementRecord;
use Hilos\Cluster\Placement\PlacementRefusal;
use Hilos\Cluster\Placement\PlacementRefusalCode;
use Hilos\Cluster\Placement\PlacementState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the agent-placement peer frames (HIL-179).
 *
 * Each frame round-trips through the shared {@see PeerDTO::fromWire()} dispatch so a
 * frame serialized on one node parses back to the same concrete DTO on another. The failed
 * status and the not-placed verdict carry the gate's structured refusals (HIL-447), which must
 * survive the wire whole and be refused in any form the writer never produces.
 */
final class PeerPlacementDTOTest extends TestCase
{
    public function testAgentRestartFrameRoundTripsWithoutChangingItsReturnAddress(): void
    {
        $frame = new PeerAgentRestartDTO('command-7', 'origin', 'chat', '42', 'host', true);
        $parsed = PeerDTO::fromWire($frame->toJson());

        $this->assertInstanceOf(PeerAgentRestartDTO::class, $parsed);
        $this->assertSame($frame->toArray(), $parsed->toArray());
    }

    public function testAgentRestartFrameRejectsAMissingInPlaceFlag(): void
    {
        $this->expectException(PeerTransportException::class);
        PeerAgentRestartDTO::fromArray([
            PeerAgentRestartDTO::FIELD_CORRELATION_ID => 'command-7',
            PeerAgentRestartDTO::FIELD_ORIGIN_NODE_ID => 'origin',
            PeerAgentRestartDTO::FIELD_AGENT_TYPE => 'chat',
            PeerAgentRestartDTO::FIELD_AGENT_INDEX => null,
            PeerAgentRestartDTO::FIELD_NODE_ID => null,
        ]);
    }

    public function testProtocolVersionBumpedForPlacementFrames(): void
    {
        // The placement-verdict frame extends the peer channel, so the version moved to at least 7.
        $this->assertGreaterThanOrEqual(7, PeerProtocol::VERSION);
    }

    public function testPlaceAgentFrameRoundTrips(): void
    {
        $frame = new PeerPlaceAgentDTO('chat', '42');
        $parsed = PeerDTO::fromWire($frame->toJson());

        $this->assertInstanceOf(PeerPlaceAgentDTO::class, $parsed);
        $this->assertSame('chat', $parsed->agentType);
        $this->assertSame('42', $parsed->agentIndex);
    }

    public function testPlaceAgentFrameKeepsANullIndexForSingletons(): void
    {
        $parsed = PeerDTO::fromWire(new PeerPlaceAgentDTO('moderator', null)->toJson());

        $this->assertInstanceOf(PeerPlaceAgentDTO::class, $parsed);
        $this->assertNull($parsed->agentIndex);
    }

    public function testPlaceAgentFrameRejectsAMissingType(): void
    {
        $this->expectException(PeerTransportException::class);
        PeerPlaceAgentDTO::fromArray([PeerPlaceAgentDTO::TYPE => PeerPlaceAgentDTO::MESSAGE_TYPE]);
    }

    public function testStopAgentFrameRoundTrips(): void
    {
        $parsed = PeerDTO::fromWire(new PeerStopAgentDTO('bot', 'b1')->toJson());

        $this->assertInstanceOf(PeerStopAgentDTO::class, $parsed);
        $this->assertSame('bot', $parsed->agentType);
        $this->assertSame('b1', $parsed->agentIndex);
    }

    public function testStartedStatusRoundTripsWithWorkerId(): void
    {
        $parsed = PeerDTO::fromWire(PeerAgentStatusDTO::started('chat', '7', -3)->toJson());

        $this->assertInstanceOf(PeerAgentStatusDTO::class, $parsed);
        $this->assertSame(PlacementState::Started, $parsed->state);
        $this->assertSame(-3, $parsed->workerId);
        $this->assertNull($parsed->error);
    }

    public function testFailedStatusRoundTripsWithReason(): void
    {
        $parsed = PeerDTO::fromWire(PeerAgentStatusDTO::failed('chat', null, 'no worker')->toJson());

        $this->assertInstanceOf(PeerAgentStatusDTO::class, $parsed);
        $this->assertSame(PlacementState::Failed, $parsed->state);
        $this->assertNull($parsed->workerId);
        $this->assertSame('no worker', $parsed->error);
    }

    public function testAgentStatusRejectsAnInvalidState(): void
    {
        $this->expectException(PeerTransportException::class);
        PeerAgentStatusDTO::fromArray([
            PeerAgentStatusDTO::TYPE => PeerAgentStatusDTO::MESSAGE_TYPE,
            PeerAgentStatusDTO::FIELD_AGENT_TYPE => 'chat',
            PeerAgentStatusDTO::FIELD_STATE => 'exploded',
        ]);
    }

    public function testAPlacedVerdictRoundTripsWithTheNodeId(): void
    {
        $parsed = PeerDTO::fromWire(PeerPlacementVerdictDTO::placed('chat', '7', 'node-b')->toJson());

        $this->assertInstanceOf(PeerPlacementVerdictDTO::class, $parsed);
        $this->assertSame(PlacementState::Started, $parsed->state);
        $this->assertSame('node-b', $parsed->nodeId);
        $this->assertNull($parsed->reason);
        $this->assertSame('chat', $parsed->agentType);
        $this->assertSame('7', $parsed->agentIndex);
    }

    public function testANotPlacedVerdictRoundTripsWithTheReason(): void
    {
        $parsed = PeerDTO::fromWire(
            PeerPlacementVerdictDTO::notPlaced('chat', null, PlacementState::Unplaced, 'no capable node')->toJson(),
        );

        $this->assertInstanceOf(PeerPlacementVerdictDTO::class, $parsed);
        $this->assertSame(PlacementState::Unplaced, $parsed->state);
        $this->assertNull($parsed->nodeId);
        $this->assertSame('no capable node', $parsed->reason);
        $this->assertNull($parsed->agentIndex);
    }

    public function testAPlacementVerdictRejectsAnInvalidState(): void
    {
        $this->expectException(PeerTransportException::class);
        PeerPlacementVerdictDTO::fromArray([
            PeerPlacementVerdictDTO::TYPE => PeerPlacementVerdictDTO::MESSAGE_TYPE,
            PeerPlacementVerdictDTO::FIELD_AGENT_TYPE => 'chat',
            PeerPlacementVerdictDTO::FIELD_STATE => 'placing',
        ]);
    }

    public function testAPlacedVerdictRejectsAMissingNodeId(): void
    {
        $this->expectException(PeerTransportException::class);
        PeerPlacementVerdictDTO::fromArray([
            PeerPlacementVerdictDTO::TYPE => PeerPlacementVerdictDTO::MESSAGE_TYPE,
            PeerPlacementVerdictDTO::FIELD_AGENT_TYPE => 'chat',
            PeerPlacementVerdictDTO::FIELD_STATE => PlacementState::Started->value,
            PeerPlacementVerdictDTO::FIELD_REFUSALS => [],
        ]);
    }

    public function testANotPlacedVerdictRejectsAMissingReason(): void
    {
        $this->expectException(PeerTransportException::class);
        PeerPlacementVerdictDTO::fromArray([
            PeerPlacementVerdictDTO::TYPE => PeerPlacementVerdictDTO::MESSAGE_TYPE,
            PeerPlacementVerdictDTO::FIELD_AGENT_TYPE => 'chat',
            PeerPlacementVerdictDTO::FIELD_STATE => PlacementState::Failed->value,
            PeerPlacementVerdictDTO::FIELD_REFUSALS => [],
        ]);
    }

    /**
     * One refusal of every code, as the gate and the coordinator build them (HIL-447).
     *
     * @return array<string, array{PlacementRefusal}> Refusals keyed by code
     */
    public static function everyRefusalCode(): array
    {
        return [
            'missing_capabilities' => [PlacementRefusal::unmetCapabilities('s1', ['gpu', 'ssd'])],
            'no_declared_capacity' => [PlacementRefusal::noDeclaredCapacity('m1')],
            'insufficient_capacity' => [PlacementRefusal::insufficientCapacity('s2', [
                'ram' => ['cost' => 2.0, 'free' => 0.5],
                'slots' => ['cost' => 1.0, 'free' => -1.0],
            ])],
            'no_online_nodes' => [PlacementRefusal::noOnlineNodes()],
        ];
    }

    #[DataProvider('everyRefusalCode')]
    public function testAFailedStatusCarriesEachRefusalAcrossTheWire(PlacementRefusal $refusal): void
    {
        $frame = PeerAgentStatusDTO::failed('worker', '7', 'refused', [$refusal]);
        $parsed = PeerDTO::fromWire($frame->toJson());

        $this->assertInstanceOf(PeerAgentStatusDTO::class, $parsed);
        $this->assertSame('refused', $parsed->error, 'The text stays the one a person reads');
        $this->assertCount(1, $parsed->refusals);
        $this->assertTrue($refusal->equals($parsed->refusals[0]), 'The structure survives the wire whole');
        $this->assertSame($frame->toJson(), $parsed->toJson());
    }

    #[DataProvider('everyRefusalCode')]
    public function testANotPlacedVerdictCarriesEachRefusalAcrossTheWire(PlacementRefusal $refusal): void
    {
        $other = PlacementRefusal::noDeclaredCapacity('a0');
        $frame = PeerPlacementVerdictDTO::notPlaced('worker', null, PlacementState::Unplaced, 'no node', [$other, $refusal]);
        $parsed = PeerDTO::fromWire($frame->toJson());

        $this->assertInstanceOf(PeerPlacementVerdictDTO::class, $parsed);
        $this->assertSame('no node', $parsed->reason);
        $this->assertCount(2, $parsed->refusals, 'Every node refusal travels, not one of them');
        $this->assertTrue($other->equals($parsed->refusals[0]));
        $this->assertTrue($refusal->equals($parsed->refusals[1]));
        $this->assertSame($frame->toJson(), $parsed->toJson());
    }

    public function testARefusalOnTheWireKeepsShortfallsAnObjectAndCapabilitiesAList(): void
    {
        $this->assertSame(
            '{"nodeId":"m1","reasonCode":"no_declared_capacity","missingCapabilities":[],"shortfalls":{}}',
            json_encode(PlacementRefusal::noDeclaredCapacity('m1')->toArray()),
        );
        $this->assertSame(
            '{"nodeId":null,"reasonCode":"no_online_nodes","missingCapabilities":[],"shortfalls":{}}',
            json_encode(PlacementRefusal::noOnlineNodes()->toArray()),
        );
    }

    public function testTheContractExampleReadsWithIntegerAmounts(): void
    {
        $refusal = PlacementRefusal::fromArray(json_decode(
            '{"nodeId":"s1","reasonCode":"insufficient_capacity","missingCapabilities":[],"shortfalls":{"slots":{"cost":1,"free":0}}}',
            true,
        ));

        $this->assertSame('s1', $refusal->nodeId);
        $this->assertSame(PlacementRefusalCode::InsufficientCapacity, $refusal->reasonCode);
        $this->assertSame(['slots' => ['cost' => 1.0, 'free' => 0.0]], $refusal->shortfalls);
        $this->assertTrue($refusal->equals(PlacementRefusal::insufficientCapacity('s1', ['slots' => ['cost' => 1.0, 'free' => 0.0]])));
    }

    public function testSuccessfulFramesCarryAnEmptyRefusalList(): void
    {
        foreach ([
            PeerAgentStatusDTO::started('worker', '7', 3),
            PeerAgentStatusDTO::stopped('worker', '7'),
            PeerAgentStatusDTO::failed('worker', '7', 'no worker'),
            PeerPlacementVerdictDTO::placed('worker', '7', 's1'),
        ] as $frame) {
            $wire = json_decode($frame->toJson(), true);
            $this->assertArrayHasKey('refusals', $wire, 'The key is always written');
            $this->assertSame([], $wire['refusals']);
            $this->assertSame([], PeerDTO::fromWire($frame->toJson())->refusals);
        }
    }

    public function testAStatusWithoutTheRefusalsKeyIsRefused(): void
    {
        $wire = PeerAgentStatusDTO::failed('worker', '7', 'no worker')->toArray();
        unset($wire[PeerAgentStatusDTO::FIELD_REFUSALS]);

        $this->expectException(PeerTransportException::class);
        $this->expectExceptionMessage("Peer agent-status frame is missing the 'refusals' list");
        PeerAgentStatusDTO::fromArray($wire);
    }

    public function testAVerdictWithoutTheRefusalsKeyIsRefused(): void
    {
        $wire = PeerPlacementVerdictDTO::notPlaced('worker', '7', PlacementState::Failed, 'no worker')->toArray();
        unset($wire[PeerPlacementVerdictDTO::FIELD_REFUSALS]);

        $this->expectException(PeerTransportException::class);
        $this->expectExceptionMessage("Peer placement-verdict frame is missing the 'refusals' list");
        PeerPlacementVerdictDTO::fromArray($wire);
    }

    public function testAStartedStatusWithRefusalsIsRefused(): void
    {
        $wire = PeerAgentStatusDTO::started('worker', '7', 3)->toArray();
        $wire[PeerAgentStatusDTO::FIELD_REFUSALS] = [$this->wireRefusal(PlacementRefusal::noDeclaredCapacity('s1'))];

        $this->expectException(PeerTransportException::class);
        $this->expectExceptionMessage("Peer agent-status frame carries refusals in state 'started'");
        PeerAgentStatusDTO::fromArray($wire);
    }

    public function testAStoppedStatusWithRefusalsIsRefused(): void
    {
        $wire = PeerAgentStatusDTO::stopped('worker', '7')->toArray();
        $wire[PeerAgentStatusDTO::FIELD_REFUSALS] = [$this->wireRefusal(PlacementRefusal::noDeclaredCapacity('s1'))];

        $this->expectException(PeerTransportException::class);
        $this->expectExceptionMessage("Peer agent-status frame carries refusals in state 'stopped'");
        PeerAgentStatusDTO::fromArray($wire);
    }

    public function testAPlacedVerdictWithRefusalsIsRefused(): void
    {
        $wire = PeerPlacementVerdictDTO::placed('worker', '7', 's1')->toArray();
        $wire[PeerPlacementVerdictDTO::FIELD_REFUSALS] = [$this->wireRefusal(PlacementRefusal::noOnlineNodes())];

        $this->expectException(PeerTransportException::class);
        $this->expectExceptionMessage('Peer placement-verdict frame carries refusals on a placed verdict');
        PeerPlacementVerdictDTO::fromArray($wire);
    }

    /**
     * Refusal forms the writer never produces, each beside the message it is refused with.
     *
     * @return array<string, array{mixed, string}> Raw refusals list and expected message part
     */
    public static function malformedRefusals(): array
    {
        $refusal = static fn(array $override): array => array_merge([
            'nodeId' => 's1',
            'reasonCode' => 'insufficient_capacity',
            'missingCapabilities' => [],
            'shortfalls' => ['slots' => ['cost' => 1, 'free' => 0]],
        ], $override);
        $withoutNode = $refusal([]);
        unset($withoutNode['nodeId']);

        return [
            'refusals as a map' => [['first' => $refusal([])], "missing the 'refusals' list"],
            'refusals as a string' => ['none', "missing the 'refusals' list"],
            'a refusal that is not an object' => [['insufficient_capacity'], 'refusal that is not an object'],
            'unknown code' => [[$refusal(['reasonCode' => 'too_busy'])], "unknown reason code 'too_busy'"],
            'code of another type' => [[$refusal(['reasonCode' => 3])], "unknown reason code 'int'"],
            'no node id key' => [[$withoutNode], 'missing the node id'],
            'blank node id' => [[$refusal(['nodeId' => ' '])], "'insufficient_capacity' names no node"],
            'null node id on a node code' => [[$refusal(['nodeId' => null])], "'insufficient_capacity' names no node"],
            'a node on no online nodes' => [
                [$refusal(['reasonCode' => 'no_online_nodes', 'shortfalls' => []])],
                'with no online nodes names a node',
            ],
            'capabilities as a map' => [[$refusal(['missingCapabilities' => ['a' => 'gpu']])], 'are not a list'],
            'a blank capability' => [
                [$refusal(['reasonCode' => 'missing_capabilities', 'missingCapabilities' => [''], 'shortfalls' => []])],
                'not a tag',
            ],
            'missing capabilities with none named' => [
                [$refusal(['reasonCode' => 'missing_capabilities', 'shortfalls' => []])],
                'does not fit its missing capabilities',
            ],
            'capabilities on a capacity refusal' => [
                [$refusal(['missingCapabilities' => ['gpu']])],
                'does not fit its missing capabilities',
            ],
            'insufficient capacity with no shortfall' => [[$refusal(['shortfalls' => []])], 'does not fit its shortfalls'],
            'shortfalls on no declared capacity' => [
                [$refusal(['reasonCode' => 'no_declared_capacity'])],
                'does not fit its shortfalls',
            ],
            'shortfalls as a list' => [[$refusal(['shortfalls' => [['cost' => 1, 'free' => 0]]])], 'not a map by resource'],
            'shortfalls missing' => [[$refusal(['shortfalls' => null])], 'not a map by resource'],
            'a shortfall without free' => [[$refusal(['shortfalls' => ['slots' => ['cost' => 1]]])], "shortfall 'slots'"],
            'a shortfall cost as text' => [
                [$refusal(['shortfalls' => ['slots' => ['cost' => '1', 'free' => 0]]])],
                "shortfall 'slots'",
            ],
        ];
    }

    #[DataProvider('malformedRefusals')]
    public function testAFailedStatusRefusesMalformedRefusals(mixed $refusals, string $message): void
    {
        $wire = PeerAgentStatusDTO::failed('worker', '7', 'refused')->toArray();
        $wire[PeerAgentStatusDTO::FIELD_REFUSALS] = $refusals;

        $this->expectException(PeerTransportException::class);
        $this->expectExceptionMessage($message);
        PeerDTO::fromWire(json_encode($wire));
    }

    #[DataProvider('malformedRefusals')]
    public function testANotPlacedVerdictRefusesMalformedRefusals(mixed $refusals, string $message): void
    {
        $wire = PeerPlacementVerdictDTO::notPlaced('worker', '7', PlacementState::Unplaced, 'no node')->toArray();
        $wire[PeerPlacementVerdictDTO::FIELD_REFUSALS] = $refusals;

        $this->expectException(PeerTransportException::class);
        $this->expectExceptionMessage($message);
        PeerDTO::fromWire(json_encode($wire));
    }

    public function testPlacementReportRoundTripsEveryHostedAgent(): void
    {
        $frame = new PeerPlacementReportDTO([
            new PeerPlacedAgentEntry('chat', '1'),
            new PeerPlacedAgentEntry('moderator', null),
        ]);
        $parsed = PeerDTO::fromWire($frame->toJson());

        $this->assertInstanceOf(PeerPlacementReportDTO::class, $parsed);
        $this->assertCount(2, $parsed->agents);
        $this->assertSame('chat', $parsed->agents[0]->agentType);
        $this->assertSame('1', $parsed->agents[0]->agentIndex);
        $this->assertNull($parsed->agents[1]->agentIndex);
    }

    public function testPlacementQueryRoundTrips(): void
    {
        $parsed = PeerDTO::fromWire(new PeerPlacementQueryDTO()->toJson());

        $this->assertInstanceOf(PeerPlacementQueryDTO::class, $parsed);
    }

    /** The release carries nothing but its type: the link it arrives on names the sender (HIL-1287). */
    public function testPlacementReleaseRoundTrips(): void
    {
        $parsed = PeerDTO::fromWire(new PeerPlacementReleaseDTO()->toJson());

        $this->assertInstanceOf(PeerPlacementReleaseDTO::class, $parsed);
        $this->assertSame([PeerDTO::TYPE], array_keys($parsed->toArray()));
    }

    /**
     * The view is the report turned around — leader to node instead of node to leader — so what
     * has to survive the wire is the one thing a report leaves implicit: which node each agent
     * is on (HIL-668). Grouping by node is what keeps the entries themselves unchanged.
     */
    public function testPlacementViewRoundTripsEveryNodesAgents(): void
    {
        $frame = new PeerPlacementViewDTO('leader', [
            'node-b' => [new PeerPlacedAgentEntry('chat', '1')],
            'node-c' => [new PeerPlacedAgentEntry('moderator', null), new PeerPlacedAgentEntry('render', '9')],
        ], new PlacementSnapshot('leader', 7, 9, []));
        $parsed = PeerDTO::fromWire($frame->toJson());

        $this->assertInstanceOf(PeerPlacementViewDTO::class, $parsed);
        $this->assertSame('leader', $parsed->leaderNodeId);
        $this->assertSame(['node-b', 'node-c'], array_keys($parsed->agents));
        $this->assertSame('chat', $parsed->agents['node-b'][0]->agentType);
        $this->assertNull($parsed->agents['node-c'][0]->agentIndex);
        $this->assertSame('9', $parsed->agents['node-c'][1]->agentIndex);
    }

    /**
     * A cluster running nothing is a real state, and its view is an empty map rather than a
     * missing one: the receiver has to end up holding nothing, not holding what it had.
     */
    public function testAPlacementViewOfNothingRoundTrips(): void
    {
        $parsed = PeerDTO::fromWire(new PeerPlacementViewDTO('leader', [], new PlacementSnapshot('leader', 7, 10, []))->toJson());

        $this->assertInstanceOf(PeerPlacementViewDTO::class, $parsed);
        $this->assertSame([], $parsed->agents);
    }

    /**
     * Refused whole rather than thinned, because this frame REPLACES the receiver's picture of
     * the cluster: an entry read as blank would index its agents under a node nothing can
     * reach, and the picture that was true is gone.
     */
    public function testAPlacementViewRejectsABlankNodeId(): void
    {
        $this->expectException(PeerTransportException::class);
        $this->expectExceptionMessage('Peer placement view carries a malformed node entry');

        PeerDTO::fromWire(json_encode([
            PeerDTO::TYPE => PeerPlacementViewDTO::MESSAGE_TYPE,
            PeerPlacementViewDTO::FIELD_LEADER_NODE_ID => 'leader',
            PeerPlacementViewDTO::FIELD_AGENTS => ['' => [['agentType' => 'chat', 'agentIndex' => '1']]],
        ]));
    }

    /**
     * A cluster whose nodes are named "1", "2", "3" is an ordinary cluster — CLUSTER_NODE_ID is
     * any non-blank string — and its view groups under int keys, because PHP has no string
     * array key that reads as a decimal integer. Refusing those would reject every view such a
     * cluster ever sends, silently, leaving its followers exactly as blind as this frame exists
     * to stop them being.
     */
    public function testAPlacementViewCarriesNodeIdsThatReadAsNumbers(): void
    {
        $frame = new PeerPlacementViewDTO('1', ['2' => [new PeerPlacedAgentEntry('chat', '7')]], new PlacementSnapshot('1', 7, 11, []));
        $parsed = PeerDTO::fromWire($frame->toJson());

        $this->assertInstanceOf(PeerPlacementViewDTO::class, $parsed);
        $this->assertSame('1', $parsed->leaderNodeId);
        $this->assertSame('chat', $parsed->agents['2'][0]->agentType);
    }

    public function testAPlacementViewRejectsAMissingLeaderId(): void
    {
        $this->expectException(PeerTransportException::class);
        $this->expectExceptionMessage('Peer placement view is missing the leader node id');

        PeerDTO::fromWire(json_encode([
            PeerDTO::TYPE => PeerPlacementViewDTO::MESSAGE_TYPE,
            PeerPlacementViewDTO::FIELD_AGENTS => [],
        ]));
    }

    public function testCompleteSnapshotsRoundTripInBothPeerFramesBesideTheLiveProjection(): void
    {
        $snapshot = new PlacementSnapshot('leader', 7, 19, [
            new PlacementRecord('render', 'placing', 'node-b', PlacementState::Placing),
            new PlacementRecord('render', 'started', 'node-c', PlacementState::Started),
            new PlacementRecord('render', 'failed', null, PlacementState::Failed, 'start failed'),
            new PlacementRecord('render', 'waiting', null, PlacementState::Unplaced, 'no room', [
                PlacementRefusal::noDeclaredCapacity('node-b'),
            ]),
            new PlacementRecord('render', 'canceled', null, PlacementState::Stopped),
            new PlacementRecord('render', 'refused', 'node-b', PlacementState::Refused),
        ]);
        $view = new PeerPlacementViewDTO('leader', ['node-b' => [new PeerPlacedAgentEntry('render', 'placing')]], $snapshot);
        $report = new PeerPlacementReportDTO([new PeerPlacedAgentEntry('render', 'actual')], $snapshot);
        foreach ([$view, $report] as $frame) {
            $parsed = PeerDTO::fromWire($frame->toJson());
            self::assertInstanceOf($frame::class, $parsed);
            self::assertEquals($frame->agents, $parsed->agents);
            self::assertEquals($snapshot, $parsed->placementSnapshot);
            self::assertSame(json_encode($snapshot->toArray()), json_encode($parsed->toArray()[$frame::FIELD_PLACEMENT_SNAPSHOT]));
        }
    }

    public function testAReportAlwaysCarriesItsSnapshotKeyAndDistinguishesNullFromEmpty(): void
    {
        $noCopy = new PeerPlacementReportDTO([]);
        self::assertArrayHasKey(PeerPlacementReportDTO::FIELD_PLACEMENT_SNAPSHOT, $noCopy->toArray());
        self::assertNull(PeerDTO::fromWire($noCopy->toJson())->placementSnapshot);
        $empty = new PlacementSnapshot('leader', 7, 20, []);
        $parsed = PeerDTO::fromWire(new PeerPlacementReportDTO([], $empty)->toJson());
        self::assertEquals($empty, $parsed->placementSnapshot);
        self::assertSame([], $parsed->placementSnapshot->records);
    }

    #[DataProvider('placementFrameKinds')]
    public function testBothPeerFramesRequireTheSnapshotKey(bool $report): void
    {
        $wire = $report ? new PeerPlacementReportDTO([])->toArray()
            : new PeerPlacementViewDTO('leader', [], new PlacementSnapshot('leader', 7, 1, []))->toArray();
        unset($wire[PeerPlacementViewDTO::FIELD_PLACEMENT_SNAPSHOT]);
        $this->expectException(PeerTransportException::class);
        PeerDTO::fromWire(json_encode($wire));
    }

    /** @return iterable<string, array{bool}> Existing frame directions */
    public static function placementFrameKinds(): iterable
    {
        yield 'view' => [false];
        yield 'report' => [true];
    }

    #[DataProvider('malformedSnapshotPayloads')]
    public function testMalformedSnapshotsRejectTheEntireViewAndReport(mixed $rawSnapshot): void
    {
        foreach ([
            new PeerPlacementViewDTO('leader', [], new PlacementSnapshot('leader', 7, 1, [])),
            new PeerPlacementReportDTO([]),
        ] as $frame) {
            $wire = $frame->toArray();
            $wire[PeerPlacementViewDTO::FIELD_PLACEMENT_SNAPSHOT] = $rawSnapshot;
            try {
                PeerDTO::fromWire(json_encode($wire));
                self::fail('A malformed full snapshot must reject the entire frame');
            } catch (PeerTransportException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /** @return iterable<string, array{mixed}> Invalid complete snapshot payloads */
    public static function malformedSnapshotPayloads(): iterable
    {
        yield 'scalar' => [true];
        yield 'missing metadata' => [[]];
        yield 'negative term' => [['leaderNodeId' => 'leader', 'term' => -1, 'revision' => 1, 'records' => []]];
        yield 'noninteger revision' => [['leaderNodeId' => 'leader', 'term' => 7, 'revision' => '1', 'records' => []]];
        $snapshot = new PlacementSnapshot('leader', 7, 1, [new PlacementRecord('render', '9', null, PlacementState::Unplaced)]);
        $malformed = $snapshot->toArray();
        unset($malformed[PlacementSnapshot::FIELD_RECORDS][0][PlacementRecord::FIELD_NODE_ID]);
        yield 'malformed record' => [$malformed];
        $duplicate = $snapshot->toArray();
        $duplicate[PlacementSnapshot::FIELD_RECORDS][] = $duplicate[PlacementSnapshot::FIELD_RECORDS][0];
        yield 'duplicate record' => [$duplicate];
        $partial = $snapshot->toArray();
        $partial[PlacementSnapshot::FIELD_RECORDS][] = ['agentType' => 'broken'];
        yield 'valid record followed by malformed one' => [$partial];
    }

    public function testAViewRejectsANullSnapshot(): void
    {
        $wire = new PeerPlacementViewDTO('leader', [], new PlacementSnapshot('leader', 7, 1, []))->toArray();
        $wire[PeerPlacementViewDTO::FIELD_PLACEMENT_SNAPSHOT] = null;
        $this->expectException(PeerTransportException::class);
        PeerDTO::fromWire(json_encode($wire));
    }

    public function testAViewRejectsANestedLeaderMismatch(): void
    {
        $wire = new PeerPlacementViewDTO('leader', [], new PlacementSnapshot('leader', 7, 1, []))->toArray();
        $wire[PeerPlacementViewDTO::FIELD_PLACEMENT_SNAPSHOT][PlacementSnapshot::FIELD_LEADER_NODE_ID] = 'other';
        $this->expectException(PeerTransportException::class);
        PeerDTO::fromWire(json_encode($wire));
    }

    /**
     * Renders a refusal the way a frame read off the wire holds it: decoded JSON, not objects.
     *
     * @param PlacementRefusal $refusal Refusal to render
     * @return array<string, mixed> Decoded refusal payload
     */
    private function wireRefusal(PlacementRefusal $refusal): array
    {
        return json_decode(json_encode($refusal->toArray(), JSON_THROW_ON_ERROR), true);
    }
}
