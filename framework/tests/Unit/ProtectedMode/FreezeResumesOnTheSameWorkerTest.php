<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\ProtectedMode;

use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Server\WorkerServer;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Tests the preference a lift states when it puts back a roster the freeze took down.
 *
 * The pick itself was never wrong - any monopolistic worker holding nothing can host any
 * monopolistic agent, which is why it chose at random and nobody minded. What made the random
 * choice cost something is how often it is made: a protected-mode freeze stops the whole roster
 * and the lift starts it again, so a run with 25 freezes deals every agent out 25 times and an
 * agent ends it having lived on a dozen workers.
 *
 * So what is worth proving is the preference and its limits: it is honoured when the worker can
 * still take the agent, and it gets out of the way the moment it cannot - a preference that
 * refused to fall back would keep an agent off a node that can still run it, which is a worse
 * failure than the churn it was written against.
 */
final class FreezeResumesOnTheSameWorkerTest extends TestCase
{
    public function testAnAgentIsHandedBackToTheWorkerItWasStoppedOn(): void
    {
        $server = $this->serverWithFreeMonopolisticWorkers([1, 2, 3]);

        $picked = $this->pick($server, requiresMonopolistic: true, preferredWorkerId: -2);

        $this->assertNotNull($picked);
        $this->assertSame(2, $picked->getWorkerIndex());
    }

    public function testAPreferenceIsHonouredOnEveryReplayAndNotOnlyTheFirst(): void
    {
        $server = $this->serverWithFreeMonopolisticWorkers([1, 2, 3]);

        // The churn this guards against is repetition: one lucky pick proves nothing, since the
        // random choice would land on the same worker one time in three by itself.
        for ($replay = 0; $replay < 8; $replay++) {
            $picked = $this->pick($server, requiresMonopolistic: true, preferredWorkerId: -3);

            $this->assertNotNull($picked);
            $this->assertSame(3, $picked->getWorkerIndex());
        }
    }

    public function testAPreferenceForAWorkerThatIsGoneFallsBackToTheOrdinaryPick(): void
    {
        $server = $this->serverWithFreeMonopolisticWorkers([1, 2]);

        $picked = $this->pick($server, requiresMonopolistic: true, preferredWorkerId: -9);

        $this->assertNotNull($picked);
        $this->assertContains($picked->getWorkerIndex(), [1, 2]);
    }

    public function testAPreferenceForAWorkerThatIsNoLongerFreeFallsBackToo(): void
    {
        // Worker 2 has an agent on it now, so a monopolistic agent may not join it - the
        // preference has to give way to the rule it was never allowed to break.
        $server = $this->serverWithFreeMonopolisticWorkers([1, 2, 3], occupied: 2);

        $picked = $this->pick($server, requiresMonopolistic: true, preferredWorkerId: -2);

        $this->assertNotNull($picked);
        $this->assertContains($picked->getWorkerIndex(), [1, 3]);
    }

    public function testNoPreferenceLeavesTheChoiceAsItWas(): void
    {
        $server = $this->serverWithFreeMonopolisticWorkers([4]);

        $picked = $this->pick($server, requiresMonopolistic: true, preferredWorkerId: null);

        $this->assertNotNull($picked);
        $this->assertSame(4, $picked->getWorkerIndex());
    }

    /**
     * Asks the server for a worker the way a start does.
     *
     * @param WorkerServer $server Server holding the worker links
     * @param bool $requiresMonopolistic Whether the agent needs a worker of its own
     * @param ?int $preferredWorkerId Worker the caller wants back, or null for no preference
     * @return ?WorkerClient Worker the server picked
     */
    private function pick(WorkerServer $server, bool $requiresMonopolistic, ?int $preferredWorkerId): ?WorkerClient
    {
        return new ReflectionMethod(WorkerServer::class, 'selectWorkerForAgent')
            ->invoke($server, $requiresMonopolistic, $preferredWorkerId);
    }

    /**
     * Builds a server whose links are monopolistic workers, all free but the one named.
     *
     * @param list<int> $workerIndexes Indexes of the monopolistic workers linked to the node
     * @param ?int $occupied Index of the worker that already hosts an agent, or null when all are free
     * @return WorkerServer Server carrying those links and an agent manager that answers for them
     */
    private function serverWithFreeMonopolisticWorkers(array $workerIndexes, ?int $occupied = null): WorkerServer
    {
        // Skip the constructor: it reads worker env and creates a log directory this test does
        // not exercise.
        $server = new ReflectionClass(SameWorkerTestWorkerServer::class)->newInstanceWithoutConstructor();

        $clients = [];
        foreach ($workerIndexes as $workerIndex) {
            $client = new ReflectionClass(SameWorkerTestWorkerClient::class)->newInstanceWithoutConstructor();
            $client->setWorkerIndex($workerIndex);
            $client->setIsMonopolistic(true);
            $clients[] = $client;
        }

        new ReflectionProperty(WorkerServer::class, 'clients')->setValue($server, $clients);
        new ReflectionProperty(WorkerServer::class, 'agentManager')
            ->setValue($server, new SameWorkerTestAgentManagerDaemon($occupied));

        return $server;
    }
}

/**
 * Worker server that carries the links and nothing else.
 */
final class SameWorkerTestWorkerServer extends WorkerServer
{
    protected function onStart(): void
    {
        // Not used in this test
    }
}

/**
 * Worker client that carries nothing but the index and kind it reports.
 */
final class SameWorkerTestWorkerClient extends WorkerClient
{
    public function __construct()
    {
    }
}

/**
 * Agent manager that answers how loaded a worker is from one declared occupancy.
 */
final class SameWorkerTestAgentManagerDaemon extends AgentManagerDaemon
{
    /**
     * @param ?int $occupiedWorkerIndex Monopolistic worker index that already hosts an agent
     */
    public function __construct(private readonly ?int $occupiedWorkerIndex)
    {
    }

    public function getAgentCountOnWorker(int $workerIndex, bool $isMonopolistic): int
    {
        return $workerIndex === $this->occupiedWorkerIndex ? 1 : 0;
    }

    /**
     * @param string $agentType Agent type that was asked for
     * @param ?string $agentIndex Agent index that was asked for
     * @return AgentDaemonInterface Never built: this test never starts an agent
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new LogicException('This test picks a worker and starts nothing.');
    }
}
