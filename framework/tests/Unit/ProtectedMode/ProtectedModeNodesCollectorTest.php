<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\ProtectedMode;

use Hilos\Cluster\Peer\DTO\PeerProtectedModeNodeReportDTO;
use Hilos\ProtectedMode\ProtectedModeNodesCollector;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use PHPUnit\Framework\TestCase;

final class ProtectedModeNodesCollectorTest extends TestCase
{
    public function testRoundFactsAndEachNodesOwnPhaseStayIndependent(): void
    {
        $reports = [
            'master' => new PeerProtectedModeNodeReportDTO('active', 7, ['master', 'follower', 'departed'], ['follower'], 'slave'),
            'follower' => new PeerProtectedModeNodeReportDTO('verifying', 7, null, null, null),
            'slave' => new PeerProtectedModeNodeReportDTO('inactive', null, null, null, null),
        ];

        $this->assertSame([
            ['nodeId' => 'departed', 'phase' => null, 'quiesced' => true,
                'isLeader' => false, 'isInitiator' => false, 'reportState' => 'silent'],
            ['nodeId' => 'follower', 'phase' => 'verifying', 'quiesced' => false,
                'isLeader' => false, 'isInitiator' => false, 'reportState' => 'current'],
            ['nodeId' => 'master', 'phase' => 'active', 'quiesced' => true,
                'isLeader' => true, 'isInitiator' => false, 'reportState' => 'current'],
            ['nodeId' => 'offline', 'phase' => null, 'quiesced' => null,
                'isLeader' => false, 'isInitiator' => false, 'reportState' => 'silent'],
            ['nodeId' => 'slave', 'phase' => 'inactive', 'quiesced' => null,
                'isLeader' => false, 'isInitiator' => true, 'reportState' => 'current'],
        ], ProtectedModeNodesCollector::project(
            ['slave', 'master', 'offline', 'follower'], ['slave', 'master', 'follower'], $reports, 'master', 7,
        ));
    }

    public function testMissingReportNullRuntimeAndLostLinkNeverBecomeInactive(): void
    {
        $this->assertSame([
            ['nodeId' => 'a', 'phase' => null, 'quiesced' => null,
                'isLeader' => false, 'isInitiator' => false, 'reportState' => 'missing'],
            ['nodeId' => 'b', 'phase' => null, 'quiesced' => null,
                'isLeader' => false, 'isInitiator' => false, 'reportState' => 'missing'],
            ['nodeId' => 'c', 'phase' => null, 'quiesced' => null,
                'isLeader' => false, 'isInitiator' => false, 'reportState' => 'silent'],
        ], ProtectedModeNodesCollector::project(['c', 'b', 'a'], ['a', 'b'], [
            'b' => new PeerProtectedModeNodeReportDTO(null, null, null, null, null),
            'c' => new PeerProtectedModeNodeReportDTO('active', 1, null, null, null),
            'foreign' => new PeerProtectedModeNodeReportDTO('active', 1, null, null, null),
        ], null, null));
    }

    public function testUnknownOrStaleLeaderAndAnIncompleteRosterGiveNoRoundVerdicts(): void
    {
        foreach ([
            [null, 4, ['a', 'b'], new PeerProtectedModeNodeReportDTO('active', 4, ['a', 'b'], [], 'b')],
            ['a', null, ['a', 'b'], new PeerProtectedModeNodeReportDTO('active', 4, ['a', 'b'], [], 'b')],
            ['a', 5, ['a', 'b'], new PeerProtectedModeNodeReportDTO('active', 4, ['a', 'b'], [], 'b')],
            ['a', 4, ['b'], new PeerProtectedModeNodeReportDTO('active', 4, ['a', 'b'], [], 'b')],
            ['a', 4, ['a', 'b'], new PeerProtectedModeNodeReportDTO('active', 4, null, null, 'b')],
            ['a', 4, ['a', 'b'], new PeerProtectedModeNodeReportDTO('active', 4, ['a', 'b'], null, 'b')],
        ] as [$leader, $term, $links, $report]) {
            $rows = ProtectedModeNodesCollector::project(['a', 'b'], $links, ['a' => $report], $leader, $term);
            $this->assertSame([null, null], array_column($rows, 'quiesced'));
            $this->assertSame([false, false], array_column($rows, 'isInitiator'));
        }
    }

    public function testACompleteNextRoundReplacesEveryPreviousAcknowledgement(): void
    {
        $first = ProtectedModeNodesCollector::project(['a', 'b'], ['a', 'b'], [
            'a' => new PeerProtectedModeNodeReportDTO('verifying', 4, ['a', 'b'], [], 'b'),
        ], 'a', 4);
        $next = ProtectedModeNodesCollector::project(['a', 'b'], ['a', 'b'], [
            'a' => new PeerProtectedModeNodeReportDTO('activating', 4, ['b'], ['b'], 'a'),
        ], 'a', 4);

        $this->assertSame([true, true], array_column($first, 'quiesced'));
        $this->assertSame([null, false], array_column($next, 'quiesced'));
        $this->assertSame([true, false], array_column($next, 'isInitiator'));
    }

    public function testALeaderOutsideItsDirectWindowRoundAnswersByItsOwnPhase(): void
    {
        foreach ([
            ProtectedModeRuntime::PHASE_VERIFYING => [true, false, true],
            ProtectedModeRuntime::PHASE_ACTIVE => [true, false, true],
            ProtectedModeRuntime::PHASE_ACTIVATING => [null, false, true],
        ] as $phase => $quiesced) {
            $rows = ProtectedModeNodesCollector::project(['a', 'b', 'c'], ['a', 'b', 'c'], [
                'a' => new PeerProtectedModeNodeReportDTO($phase, 4, ['b', 'c'], ['b'], 'a'),
            ], 'a', 4);
            $this->assertSame($quiesced, array_column($rows, 'quiesced'), $phase);
        }
    }

    public function testALeaderInsideAFullFreezeRoundAnswersByThePendingSetWhateverItsPhase(): void
    {
        $rows = ProtectedModeNodesCollector::project(['a', 'b'], ['a', 'b'], [
            'a' => new PeerProtectedModeNodeReportDTO(ProtectedModeRuntime::PHASE_VERIFYING, 4, ['a', 'b'], ['a'], 'b'),
        ], 'a', 4);

        $this->assertSame([false, true], array_column($rows, 'quiesced'));
    }
}
