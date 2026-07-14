<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster;

use Hilos\Cluster\Leadership;
use Hilos\Cluster\NodeLifecycleState;
use Hilos\Cluster\NodeRole;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the role + leadership → lifecycle phase resolver (HIL-338).
 */
final class NodeLifecycleStateTest extends TestCase
{
    public function testSlaveResolvesToSlaveRegardlessOfLeadership(): void
    {
        $this->assertSame(
            NodeLifecycleState::Slave,
            NodeLifecycleState::forEnabledNode(NodeRole::Slave, self::leadership(amLeader: true, hasQuorum: true)),
        );
    }

    public function testMasterWithoutQuorumResolvesToMasterNoQuorum(): void
    {
        $this->assertSame(
            NodeLifecycleState::MasterNoQuorum,
            NodeLifecycleState::forEnabledNode(NodeRole::Master, self::leadership(amLeader: false, hasQuorum: false)),
        );
    }

    public function testMasterLeaderWithQuorumResolvesToMasterLeader(): void
    {
        $this->assertSame(
            NodeLifecycleState::MasterLeader,
            NodeLifecycleState::forEnabledNode(NodeRole::Master, self::leadership(amLeader: true, hasQuorum: true)),
        );
    }

    public function testMasterFollowerWithQuorumResolvesToMasterFollowerOrCandidate(): void
    {
        $this->assertSame(
            NodeLifecycleState::MasterFollowerOrCandidate,
            NodeLifecycleState::forEnabledNode(NodeRole::Master, self::leadership(amLeader: false, hasQuorum: true)),
        );
    }

    public function testLeadershipIsIgnoredForASlaveEvenWithoutQuorum(): void
    {
        $this->assertSame(
            NodeLifecycleState::Slave,
            NodeLifecycleState::forEnabledNode(NodeRole::Slave, self::leadership(amLeader: false, hasQuorum: false)),
        );
    }

    /**
     * Builds a fixed-answer leadership seam for the resolver under test.
     *
     * @param bool $amLeader Whether the seam reports the node as leader
     * @param bool $hasQuorum Whether the seam reports a visible quorum
     * @return Leadership Stub leadership with the given answers
     */
    private static function leadership(bool $amLeader, bool $hasQuorum): Leadership
    {
        return new class ($amLeader, $hasQuorum) implements Leadership {
            public function __construct(
                private readonly bool $amLeader,
                private readonly bool $hasQuorum,
            ) {
            }

            public function amLeader(): bool
            {
                return $this->amLeader;
            }

            public function leaderId(): ?string
            {
                return null;
            }

            public function hasQuorum(): bool
            {
                return $this->hasQuorum;
            }
        };
    }
}
