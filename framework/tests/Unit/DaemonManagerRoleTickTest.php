<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\ClusterNode;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeLifecycleState;
use Hilos\Cluster\NodeRole;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Router\SignalRouter;
use Hilos\Hilos;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Unit tests for the daemon's role-based tick dispatch and membership hooks (HIL-338).
 */
final class DaemonManagerRoleTickTest extends TestCase
{
    public function tearDown(): void
    {
        Hilos::$sr = null;

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{NodeLifecycleState, string}> Phase and the hook it must fire
     */
    public static function phaseHookProvider(): iterable
    {
        yield 'standalone' => [NodeLifecycleState::Standalone, 'onTickStandalone'];
        yield 'master-leader' => [NodeLifecycleState::MasterLeader, 'onTickLeaderMaster'];
        yield 'master-follower' => [NodeLifecycleState::MasterFollowerOrCandidate, 'onTickNotLeaderMaster'];
        yield 'master-no-quorum' => [NodeLifecycleState::MasterNoQuorum, 'onTickNotLeaderMaster'];
        yield 'slave' => [NodeLifecycleState::Slave, 'onTickSlave'];
    }

    /**
     * @param NodeLifecycleState $state Phase to dispatch
     * @param string $expectedHook Hook name that must fire for the phase
     */
    #[DataProvider('phaseHookProvider')]
    public function testRunPhaseTickDispatchesToTheHookForThePhase(NodeLifecycleState $state, string $expectedHook): void
    {
        $manager = new DaemonManagerRoleTickTestManager();

        $runPhaseTick = new ReflectionMethod(DaemonManager::class, 'runPhaseTick');
        $runPhaseTick->invoke($manager, $state);

        $this->assertSame([$expectedHook], $manager->firedHooks);
    }

    public function testMembershipHooksDefaultToNoOps(): void
    {
        $manager = new DaemonManagerRoleTickTestManager();
        $node = ClusterNode::fromIdentity(NodeIdentity::of('node-b', NodeRole::Master, []), true, 100.0);

        $manager->onNodeJoined($node);
        $manager->onNodeLeft($node);

        $this->assertSame([], $manager->firedHooks);
    }
}

final class DaemonManagerRoleTickTestManager extends DaemonManager
{
    /** @var list<string> Names of the tick hooks that fired, in order */
    public array $firedHooks = [];

    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new DaemonManagerRoleTickTestAgentManagerDaemon();
    }

    protected function onTickStandalone(): void
    {
        $this->firedHooks[] = 'onTickStandalone';
    }

    protected function onTickLeaderMaster(): void
    {
        $this->firedHooks[] = 'onTickLeaderMaster';
    }

    protected function onTickNotLeaderMaster(): void
    {
        $this->firedHooks[] = 'onTickNotLeaderMaster';
    }

    protected function onTickSlave(): void
    {
        $this->firedHooks[] = 'onTickSlave';
    }
}

final class DaemonManagerRoleTickTestAgentManagerDaemon extends AgentManagerDaemon
{
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException('not used in test');
    }
}
