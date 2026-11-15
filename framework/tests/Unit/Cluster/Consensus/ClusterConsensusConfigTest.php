<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Consensus;

use Hilos\Cluster\Consensus\ClusterConsensusConfig;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Constants\EnvConstants;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The declared master set fixes the quorum independently of live membership. */
final class ClusterConsensusConfigTest extends TestCase
{
    private ?EnvAccessor $previousEnv;
    private string|false $previousMasterSet;

    /** @return iterable<string, array{int, int}> Declared master counts and their majority thresholds */
    public static function masterSets(): iterable
    {
        yield 'one master' => [1, 1];
        yield 'two masters' => [2, 2];
        yield 'three masters' => [3, 2];
        yield 'four masters' => [4, 3];
        yield 'five masters' => [5, 3];
    }

    /**
     * @param int $masterCount Number of declared masters
     * @param int $expectedQuorum Required majority threshold
     */
    #[DataProvider('masterSets')]
    public function testQuorumIsAMajorityOfTheDeclaredMasterSet(int $masterCount, int $expectedQuorum): void
    {
        putenv(EnvConstants::CLUSTER_MASTER_SET->name . '=' . implode(',', array_map(
            static fn (int $number): string => 'm' . $number,
            range(1, $masterCount),
        )));

        $config = ClusterConsensusConfig::fromEnv(NodeIdentity::of('m1', 'Node m1', NodeRole::Master, []));

        $this->assertSame($expectedQuorum, $config->quorumSize);
        $this->assertCount($masterCount, $config->masterSet);
    }

    /** Isolates the master set and the facade's environment accessor. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        $this->previousMasterSet = getenv(EnvConstants::CLUSTER_MASTER_SET->name);
        Hilos::$env = new EnvAccessor();
    }

    /** Restores the original accessor and the process master set, including its absence. */
    protected function tearDown(): void
    {
        Hilos::$env = $this->previousEnv;
        putenv($this->previousMasterSet === false
            ? EnvConstants::CLUSTER_MASTER_SET->name
            : EnvConstants::CLUSTER_MASTER_SET->name . '=' . $this->previousMasterSet);
        parent::tearDown();
    }
}
