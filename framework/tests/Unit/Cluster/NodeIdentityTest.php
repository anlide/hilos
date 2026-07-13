<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster;

use Hilos\Cluster\Exception\ClusterConfigurationException;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for node identity resolution from configuration (HIL-177).
 */
final class NodeIdentityTest extends TestCase
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
        foreach (['CLUSTER_NODE_ID', 'CLUSTER_NODE_ROLE', 'CLUSTER_NODE_CAPABILITIES'] as $key) {
            putenv($key);
        }
    }

    public function testResolvesIdRoleAndCapabilities(): void
    {
        putenv('CLUSTER_NODE_ID=node-a');
        putenv('CLUSTER_NODE_ROLE=master');
        putenv('CLUSTER_NODE_CAPABILITIES=gpu-local,ssd');

        $identity = NodeIdentity::fromEnv();

        $this->assertSame('node-a', $identity->nodeId);
        $this->assertSame(NodeRole::Master, $identity->role);
        $this->assertSame(['gpu-local', 'ssd'], $identity->capabilities);
    }

    public function testResolvesSlaveRole(): void
    {
        putenv('CLUSTER_NODE_ID=node-b');
        putenv('CLUSTER_NODE_ROLE=slave');

        $identity = NodeIdentity::fromEnv();

        $this->assertSame(NodeRole::Slave, $identity->role);
        $this->assertSame([], $identity->capabilities);
    }

    public function testCapabilityParsingTrimsBlanksAndDeduplicates(): void
    {
        putenv('CLUSTER_NODE_ID=node-a');
        putenv('CLUSTER_NODE_ROLE=master');
        putenv('CLUSTER_NODE_CAPABILITIES= gpu-local , , ssd ,gpu-local');

        $identity = NodeIdentity::fromEnv();

        $this->assertSame(['gpu-local', 'ssd'], $identity->capabilities);
        $this->assertTrue($identity->hasCapability('ssd'));
        $this->assertFalse($identity->hasCapability('nvme'));
    }

    public function testMissingNodeIdThrows(): void
    {
        putenv('CLUSTER_NODE_ROLE=master');

        $this->expectException(ClusterConfigurationException::class);

        NodeIdentity::fromEnv();
    }

    public function testMissingRoleThrows(): void
    {
        putenv('CLUSTER_NODE_ID=node-a');

        $this->expectException(ClusterConfigurationException::class);

        NodeIdentity::fromEnv();
    }

    public function testInvalidRoleThrows(): void
    {
        putenv('CLUSTER_NODE_ID=node-a');
        putenv('CLUSTER_NODE_ROLE=leader');

        $this->expectException(ClusterConfigurationException::class);

        NodeIdentity::fromEnv();
    }
}
