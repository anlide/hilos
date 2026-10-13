<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\AdminViewMode\WireField;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\NodeRole;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\DaemonSection\ClusterDaemonNodeSlot;
use Hilos\DaemonSection\ClusterDaemonNodeView;
use Hilos\DaemonSection\ClusterDaemonPictureMirror;
use Hilos\DaemonSection\DaemonEnvMismatchSummary;
use Hilos\DaemonSection\DTO\DaemonClusterPicturePortionSignalData;
use Hilos\DaemonSection\NodeDaemonPicture;
use Hilos\DaemonSection\NodeEnvironmentFingerprint;
use Hilos\DaemonSection\NodeEnvironmentSummary;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvSource;
use Hilos\Hilos;
use Hilos\Pages\Daemon\AbstractHilosDaemonEnvMismatchPage;
use Hilos\Runtime\State\Item\HilosClusterNode;
use PHPUnit\Framework\TestCase;

/** The environment mismatch page answers its summary without exposing secret values. */
final class HilosDaemonEnvMismatchPageTest extends TestCase
{
    private ?ClusterContext $previousCluster = null;
    private ?EnvAccessor $previousEnv = null;
    private ?string $previousClusterEnabled = null;
    private ?string $previousClusterNodeId = null;
    private ?string $previousClusterNodeRole = null;

    protected function setUp(): void
    {
        $this->previousCluster = Hilos::$cluster;
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        $this->previousClusterEnabled = getenv('CLUSTER_ENABLED') !== false ? (string)getenv('CLUSTER_ENABLED') : null;
        $this->previousClusterNodeId = getenv('CLUSTER_NODE_ID') !== false ? (string)getenv('CLUSTER_NODE_ID') : null;
        $this->previousClusterNodeRole = getenv('CLUSTER_NODE_ROLE') !== false ? (string)getenv('CLUSTER_NODE_ROLE') : null;
        ClusterDaemonPictureMirror::forgetPicture();
        AbstractHilosDaemonEnvMismatchPage::onPictureForgotten();
        Hilos::$cluster = null;
        parent::setUp();
    }

    protected function tearDown(): void
    {
        Hilos::$cluster = $this->previousCluster;
        if ($this->previousEnv !== null) {
            Hilos::$env = $this->previousEnv;
        }
        if ($this->previousClusterEnabled !== null) {
            putenv("CLUSTER_ENABLED={$this->previousClusterEnabled}");
        } else {
            putenv('CLUSTER_ENABLED');
        }
        if ($this->previousClusterNodeId !== null) {
            putenv("CLUSTER_NODE_ID={$this->previousClusterNodeId}");
        } else {
            putenv('CLUSTER_NODE_ID');
        }
        if ($this->previousClusterNodeRole !== null) {
            putenv("CLUSTER_NODE_ROLE={$this->previousClusterNodeRole}");
        } else {
            putenv('CLUSTER_NODE_ROLE');
        }
        ClusterDaemonPictureMirror::forgetPicture();
        AbstractHilosDaemonEnvMismatchPage::onPictureForgotten();
        foreach (ClusterDaemonPictureMirror::viewerKeys() as $acceptKey) {
            ClusterDaemonPictureMirror::removeViewer($acceptKey);
        }
        parent::tearDown();
    }

    public function testStandaloneAnswersWithoutComparison(): void
    {
        putenv('CLUSTER_ENABLED=false');
        putenv('CLUSTER_NODE_ID');
        Hilos::$env = new EnvAccessor();
        Hilos::$cluster = new ClusterContext();
        $page = new DaemonEnvMismatchTestPage(new DaemonEnvMismatchTestAgent());
        $data = $page->payload('ak', new PageRouteParams([]))->data;

        $this->assertSame([
            AbstractHilosDaemonEnvMismatchPage::ENV_MISMATCH => [
                DaemonEnvMismatchSummary::clustered => false,
                DaemonEnvMismatchSummary::servingNodeId => HilosClusterNode::STANDALONE_NODE_ID,
                DaemonEnvMismatchSummary::comparison => null,
            ],
        ], $data);
    }

    public function testStandaloneWithClusterNodeIdUsesConfiguredServingNode(): void
    {
        putenv('CLUSTER_ENABLED=false');
        putenv('CLUSTER_NODE_ID=custom-standalone-node');
        Hilos::$env = new EnvAccessor();
        Hilos::$cluster = new ClusterContext();
        $page = new DaemonEnvMismatchTestPage(new DaemonEnvMismatchTestAgent());
        $data = $page->payload('ak', new PageRouteParams([]))->data;

        $this->assertSame([
            AbstractHilosDaemonEnvMismatchPage::ENV_MISMATCH => [
                DaemonEnvMismatchSummary::clustered => false,
                DaemonEnvMismatchSummary::servingNodeId => 'custom-standalone-node',
                DaemonEnvMismatchSummary::comparison => null,
            ],
        ], $data);
    }

    public function testClusterModeWithoutPictureAnswersNullComparison(): void
    {
        putenv('CLUSTER_ENABLED=true');
        putenv('CLUSTER_NODE_ID=cluster-node-1');
        putenv('CLUSTER_NODE_ROLE=master');
        Hilos::$env = new EnvAccessor();
        Hilos::$cluster = new ClusterContext();
        ClusterDaemonPictureMirror::forgetPicture();
        $page = new DaemonEnvMismatchTestPage(new DaemonEnvMismatchTestAgent());
        $data = $page->payload('ak', new PageRouteParams([]))->data;

        $this->assertSame([
            AbstractHilosDaemonEnvMismatchPage::ENV_MISMATCH => [
                DaemonEnvMismatchSummary::clustered => true,
                DaemonEnvMismatchSummary::servingNodeId => 'cluster-node-1',
                DaemonEnvMismatchSummary::comparison => null,
            ],
        ], $data);
    }

    public function testClusterModeWithThreeNodesAndOneSilentComparesBuckets(): void
    {
        putenv('CLUSTER_ENABLED=true');
        putenv('CLUSTER_NODE_ID=node-a');
        putenv('CLUSTER_NODE_ROLE=master');
        Hilos::$env = new EnvAccessor();
        Hilos::$cluster = new ClusterContext();
        $nodes = [
            $this->node('node-a', true, [
                new NodeEnvironmentFingerprint('DIV_KEY', 'string', EnvSource::PROCESS, false, 'digest-a'),
                new NodeEnvironmentFingerprint('SRC_KEY', 'string', EnvSource::PROCESS, false, 'digest-same'),
                new NodeEnvironmentFingerprint('PER_KEY', 'string', EnvSource::PROCESS, true, 'digest-per-a'),
            ]),
            $this->node('node-b', true, [
                new NodeEnvironmentFingerprint('DIV_KEY', 'string', EnvSource::PROCESS, false, 'digest-b'),
                new NodeEnvironmentFingerprint('SRC_KEY', 'string', EnvSource::ENV_FILE, false, 'digest-same'),
                new NodeEnvironmentFingerprint('PER_KEY', 'string', EnvSource::PROCESS, true, 'digest-per-b'),
            ]),
            $this->node('node-c', false, null),
        ];
        ClusterDaemonPictureMirror::applyPortion(new DaemonClusterPicturePortionSignalData(true, $nodes));

        $page = new DaemonEnvMismatchTestPage(new DaemonEnvMismatchTestAgent());
        $data = $page->payload('ak', new PageRouteParams([]))->data;

        $this->assertSame([
            AbstractHilosDaemonEnvMismatchPage::ENV_MISMATCH => [
                DaemonEnvMismatchSummary::clustered => true,
                DaemonEnvMismatchSummary::servingNodeId => 'node-a',
                DaemonEnvMismatchSummary::comparison => [
                    DaemonEnvMismatchSummary::nodes => ['node-a', 'node-b', 'node-c'],
                    DaemonEnvMismatchSummary::answering => ['node-a', 'node-b'],
                    DaemonEnvMismatchSummary::diverged => 1,
                    DaemonEnvMismatchSummary::sourcesDiffer => 1,
                    DaemonEnvMismatchSummary::perNode => ['PER_KEY'],
                ],
            ],
        ], $data);
    }

    public function testDataFieldsMatchesDataKeys(): void
    {
        $page = new DaemonEnvMismatchTestPage(new DaemonEnvMismatchTestAgent());
        $fields = $page->fields();
        $data = $page->payload('ak', new PageRouteParams([]))->data;

        $this->assertSame(array_keys($data), array_keys($fields));
        $this->assertEquals([
            AbstractHilosDaemonEnvMismatchPage::ENV_MISMATCH => WireField::notPersonal(),
        ], $fields);
    }

    /**
     * @param string $id Node id
     * @param bool $online Whether the node answers
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
}

final class DaemonEnvMismatchTestPage extends AbstractHilosDaemonEnvMismatchPage
{
    /** @param PageRouteParams $params Route parameters */
    public function payload(string $acceptKey, PageRouteParams $params): PagePayload
    {
        return $this->buildPagePayload($acceptKey, $params);
    }

    /** @return array<string, WireField> Value visibility declarations */
    public function fields(): array
    {
        return $this->dataFields();
    }
}

final class DaemonEnvMismatchTestAgent implements PageAgentInterface
{
    public function getId(): string
    {
        return 'test-agent';
    }

    public function getAgentSignalSource(): SignalSourceInterface
    {
        return new SignalSource(SignalSource::AGENT, $this->getId());
    }
}
