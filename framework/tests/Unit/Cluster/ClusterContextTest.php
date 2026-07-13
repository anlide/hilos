<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster;

use Hilos\Cluster\ClusterCommandConstants;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Exception\ClusterDisabledException;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the cluster facade context: mode gating and identity access (HIL-177).
 */
final class ClusterContextTest extends TestCase
{
    private ?EnvAccessor $previousEnv = null;

    protected function setUp(): void
    {
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        Hilos::$env = new EnvAccessor();
    }

    protected function tearDown(): void
    {
        Hilos::$env = $this->previousEnv;
        foreach (['CLUSTER_ENABLED', 'CLUSTER_NODE_ID', 'CLUSTER_NODE_ROLE', 'CLUSTER_NODE_CAPABILITIES'] as $key) {
            putenv($key);
        }
    }

    public function testDisabledByDefault(): void
    {
        $this->assertFalse((new ClusterContext())->isEnabled());
    }

    public function testEnabledWhenFlagSet(): void
    {
        putenv('CLUSTER_ENABLED=true');

        $this->assertTrue((new ClusterContext())->isEnabled());
    }

    public function testIdentityThrowsWhenDisabled(): void
    {
        putenv('CLUSTER_NODE_ID=node-a');
        putenv('CLUSTER_NODE_ROLE=master');

        $this->expectException(ClusterDisabledException::class);

        (new ClusterContext())->identity();
    }

    public function testIdentityResolvedWhenEnabled(): void
    {
        putenv('CLUSTER_ENABLED=true');
        putenv('CLUSTER_NODE_ID=node-a');
        putenv('CLUSTER_NODE_ROLE=master');

        $this->assertSame('node-a', (new ClusterContext())->identity()->nodeId);
    }

    public function testIdentityIsMemoized(): void
    {
        putenv('CLUSTER_ENABLED=true');
        putenv('CLUSTER_NODE_ID=node-a');
        putenv('CLUSTER_NODE_ROLE=master');

        $context = new ClusterContext();

        $this->assertSame($context->identity(), $context->identity());
    }

    public function testSnapshotWhenDisabledHasNoNodes(): void
    {
        $snapshot = (new ClusterContext())->snapshot();

        $this->assertFalse($snapshot[ClusterCommandConstants::FIELD_ENABLED]);
        $this->assertSame([], $snapshot[ClusterCommandConstants::FIELD_NODES]);
    }

    public function testSnapshotWhenEnabledCarriesTheLocalNode(): void
    {
        putenv('CLUSTER_ENABLED=true');
        putenv('CLUSTER_NODE_ID=node-a');
        putenv('CLUSTER_NODE_ROLE=master');
        putenv('CLUSTER_NODE_CAPABILITIES=gpu-local');

        $snapshot = (new ClusterContext())->snapshot();

        $this->assertTrue($snapshot[ClusterCommandConstants::FIELD_ENABLED]);
        $this->assertSame(
            [[
                ClusterCommandConstants::FIELD_NODE_ID => 'node-a',
                ClusterCommandConstants::FIELD_NODE_ROLE => 'master',
                ClusterCommandConstants::FIELD_NODE_CAPABILITIES => ['gpu-local'],
                ClusterCommandConstants::FIELD_NODE_ONLINE => true,
            ]],
            $snapshot[ClusterCommandConstants::FIELD_NODES],
        );
    }
}
