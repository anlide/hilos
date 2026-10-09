<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\NodeRole;
use Hilos\DaemonSection\ClusterDaemonNodeSlot;
use Hilos\DaemonSection\ClusterDaemonNodeView;
use Hilos\DaemonSection\ClusterDaemonPicture;
use Hilos\DaemonSection\ClusterEnvironmentCellState;
use Hilos\DaemonSection\NodeDaemonPicture;
use Hilos\DaemonSection\NodeEnvironmentFingerprint;
use Hilos\DaemonSection\NodeEnvironmentSummary;
use Hilos\Environment\EnvSource;
use PHPUnit\Framework\TestCase;

/** The cluster verdict separates unequal labels, unequal sources and expected node differences. */
final class ClusterEnvironmentComparisonTest extends TestCase
{
    public function testThreeNodesProduceTheThreeBucketsWithoutTreatingSilenceAsAgreement(): void
    {
        $picture = ClusterDaemonPicture::empty()
            ->withNode($this->node('n3', false, [
                $this->fingerprint('DIFF', EnvSource::PROCESS, 'cccccccccccccccc'),
            ]))
            ->withNode($this->node('n2', true, [
                $this->fingerprint('DIFF', EnvSource::ENV_FILE, 'bbbbbbbbbbbbbbbb'),
                $this->fingerprint('SOURCE', EnvSource::ENV_FILE, '1111111111111111'),
                $this->fingerprint('PER_NODE', EnvSource::PROCESS, '2222222222222222', true),
            ]))
            ->withNode($this->node('n1', true, [
                $this->fingerprint('DIFF', EnvSource::PROCESS, 'aaaaaaaaaaaaaaaa'),
                $this->fingerprint('SOURCE', EnvSource::PROCESS, '1111111111111111'),
                $this->fingerprint('PER_NODE', EnvSource::PROCESS, '3333333333333333', true),
            ]));

        $comparison = $picture->environmentComparison();
        self::assertSame(['n1', 'n2', 'n3'], $comparison->nodes);
        self::assertSame(['n1', 'n2'], $comparison->answering);
        self::assertSame(['DIFF'], array_map(static fn ($row): string => $row->key, $comparison->diverged));
        self::assertSame(['SOURCE'], array_map(static fn ($row): string => $row->key, $comparison->sourcesDiffer));
        self::assertSame(['PER_NODE'], $comparison->perNode);
        self::assertSame([
            ClusterEnvironmentCellState::Known,
            ClusterEnvironmentCellState::Known,
            ClusterEnvironmentCellState::Unknown,
        ], array_map(static fn ($cell): ClusterEnvironmentCellState => $cell->state, $comparison->diverged[0]->cells));
        self::assertNull($comparison->diverged[0]->cells[2]->fingerprint);
    }

    public function testUndeclaredAndDifferentTypesDivergeButMissingEnvironmentIsUnknown(): void
    {
        $picture = ClusterDaemonPicture::empty()
            ->withNode($this->node('n1', true, [
                $this->fingerprint('UNDECLARED', EnvSource::PROCESS, 'aaaaaaaaaaaaaaaa'),
                $this->fingerprint('TYPE', EnvSource::PROCESS, 'bbbbbbbbbbbbbbbb'),
            ]))
            ->withNode($this->node('n2', true, [
                $this->fingerprint('TYPE', EnvSource::PROCESS, 'bbbbbbbbbbbbbbbb', false, 'boolean'),
            ]))
            ->withNode($this->node('n3', true, null));

        $comparison = $picture->environmentComparison();
        self::assertSame(['n1', 'n2', 'n3'], $comparison->nodes);
        self::assertSame(['n1', 'n2'], $comparison->answering);
        self::assertSame(['UNDECLARED', 'TYPE'], array_map(static fn ($row): string => $row->key, $comparison->diverged));
        self::assertSame(ClusterEnvironmentCellState::Undeclared, $comparison->diverged[0]->cells[1]->state);
        self::assertSame(ClusterEnvironmentCellState::Unknown, $comparison->diverged[0]->cells[2]->state);
    }

    public function testOneAnsweringNodeHasNoDiscrepanciesButKeepsPerNodeKeys(): void
    {
        $picture = ClusterDaemonPicture::empty()
            ->withNode($this->node('n1', true, [
                $this->fingerprint('LOCAL', EnvSource::PROCESS, 'aaaaaaaaaaaaaaaa', true),
                $this->fingerprint('ONLY_HERE', EnvSource::PROCESS, 'bbbbbbbbbbbbbbbb'),
            ]))
            ->withNode($this->node('n2', false, null));

        $comparison = $picture->environmentComparison();
        self::assertSame(['n1'], $comparison->answering);
        self::assertSame([], $comparison->diverged);
        self::assertSame([], $comparison->sourcesDiffer);
        self::assertSame(['LOCAL'], $comparison->perNode);
    }

    /**
     * @param string $id Node id
     * @param bool $online Roster verdict
     * @param ?list<NodeEnvironmentFingerprint> $fingerprints Null before an environment report
     * @return ClusterDaemonNodeView Node view
     */
    private function node(string $id, bool $online, ?array $fingerprints): ClusterDaemonNodeView
    {
        return new ClusterDaemonNodeView($id, $online, new ClusterDaemonNodeSlot($id, new NodeDaemonPicture(
            $id,
            NodeRole::Master,
            1,
            environment: $fingerprints === null ? null : new NodeEnvironmentSummary(count($fingerprints), 0, 0, 0, 0, $fingerprints),
        ), 1));
    }

    /**
     * @param string $key Catalog key
     * @param EnvSource $source Value source
     * @param string $digest Collector label
     * @param bool $perNode Whether the key may differ by node
     * @param string $type Catalog type
     * @return NodeEnvironmentFingerprint Fingerprint
     */
    private function fingerprint(
        string $key,
        EnvSource $source,
        string $digest,
        bool $perNode = false,
        string $type = 'string',
    ): NodeEnvironmentFingerprint {
        return new NodeEnvironmentFingerprint($key, $type, $source, $perNode, $digest);
    }
}
