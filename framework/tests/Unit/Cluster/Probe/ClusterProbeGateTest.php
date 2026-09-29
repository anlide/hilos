<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Probe;

use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Probe\ClusterProbe;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Where a cluster probe may start: on a clustered node of a known, non-production environment.
 *
 * One node, a production-like environment and an unreadable one all answer no, because a probe
 * that starts where it should not blocks a real worker, while one missing from a stand only
 * fails the scenario that names it.
 */
final class ClusterProbeGateTest extends TestCase
{
    private ?EnvAccessor $previousEnv = null;

    private ?ClusterContext $previousCluster = null;

    /** @var string|false APP_ENV the suite runs under, put back so this file does not decide what the next one reads */
    private string|false $previousAppEnv = false;

    protected function setUp(): void
    {
        $this->previousEnv = Hilos::$env;
        $this->previousCluster = Hilos::$cluster;
        $this->previousAppEnv = getenv('APP_ENV');
    }

    protected function tearDown(): void
    {
        Hilos::$env = $this->previousEnv;
        Hilos::$cluster = $this->previousCluster;
        putenv('CLUSTER_ENABLED');
        $this->previousAppEnv === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $this->previousAppEnv);

        parent::tearDown();
    }

    public function testNoProbeRunsWithoutAClusterContext(): void
    {
        $this->bootNode(clustered: false, appEnv: 'test');
        Hilos::$cluster = null;

        $this->assertFalse(ClusterProbe::mayRunHere());
    }

    public function testNoProbeRunsOnASingleNode(): void
    {
        $this->bootNode(clustered: false, appEnv: 'test');

        $this->assertFalse(ClusterProbe::mayRunHere(), 'A node with cluster mode off is its own stand, not a cluster one');
    }

    #[DataProvider('productionLikeEnvironments')]
    public function testNoProbeRunsOnAProductionLikeCluster(string $appEnv): void
    {
        $this->bootNode(clustered: true, appEnv: $appEnv);

        $this->assertFalse(ClusterProbe::mayRunHere());
    }

    public function testNoProbeRunsWhereTheEnvironmentIsUnknown(): void
    {
        $this->bootNode(clustered: true, appEnv: 'weekend-box');

        $this->assertFalse(ClusterProbe::mayRunHere(), 'An environment nobody recognizes is not evidence of a stand');
    }

    #[DataProvider('standEnvironments')]
    public function testAProbeRunsOnAClusterOfAStandEnvironment(string $appEnv): void
    {
        $this->bootNode(clustered: true, appEnv: $appEnv);

        $this->assertTrue(ClusterProbe::mayRunHere());
    }

    /**
     * @return array<string, array{string}> Environments a probe must never start in
     */
    public static function productionLikeEnvironments(): array
    {
        return ['prod' => ['prod'], 'staging' => ['staging']];
    }

    /**
     * @return array<string, array{string}> Environments a cluster stand runs under
     */
    public static function standEnvironments(): array
    {
        return ['dev' => ['dev'], 'test' => ['test']];
    }

    /**
     * @param bool $clustered Whether cluster mode is on
     * @param string $appEnv APP_ENV the node runs under
     */
    private function bootNode(bool $clustered, string $appEnv): void
    {
        putenv('CLUSTER_ENABLED=' . ($clustered ? 'true' : 'false'));
        putenv('APP_ENV=' . $appEnv);
        Hilos::$env = new EnvAccessor();
        Hilos::$cluster = new ClusterContext();
    }
}
