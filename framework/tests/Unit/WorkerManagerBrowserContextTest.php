<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Projection\SourceChange;
use Hilos\Core\Projection\SourceChangeSet;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WorkerManager browser context hooks.
 */
final class WorkerManagerBrowserContextTest extends TestCase
{
    public function tearDown(): void
    {
        Hilos::$browser = null;
        Hilos::$projection = null;
        Hilos::$sr = null;

        parent::tearDown();
    }

    public function testWorkerRecordsDbRtSourceChangesInBrowserContextWithoutProjection(): void
    {
        $browser = new WorkerManagerBrowserContextTestBrowserContext();
        Hilos::$browser = $browser;
        Hilos::$projection = null;

        $manager = new WorkerManagerBrowserContextTestManager(new WorkerManagerBrowserContextTestAgent());

        $recordSourceChange = \Closure::bind(
            static function (WorkerManager $manager, string $signalType, array $signalData): void {
                $manager->recordBrowserSourceChange($signalType, $signalData);
            },
            null,
            WorkerManager::class,
        );

        foreach ([
            [SignalTypeConstants::DB_SYNC_CREATED, ['collectionKey' => 'users', 'idString' => '1', 'row' => ['name' => 'Ada']]],
            [SignalTypeConstants::DB_SYNC_UPDATED, ['collectionKey' => 'users', 'idString' => '1', 'row' => ['name' => 'Grace']]],
            [SignalTypeConstants::DB_SYNC_DELETED, ['collectionKey' => 'users', 'idString' => '1', 'row' => ['name' => 'Grace']]],
            [SignalTypeConstants::RT_SYNC_CREATED, ['collectionKey' => 'connections', 'stateId' => 'ak-1', 'row' => ['userId' => 1]]],
            [SignalTypeConstants::RT_SYNC_UPDATED, ['collectionKey' => 'connections', 'stateId' => 'ak-1', 'row' => ['presence' => 'online']]],
            [SignalTypeConstants::RT_SYNC_DELETED, ['collectionKey' => 'connections', 'stateId' => 'ak-1', 'row' => ['presence' => 'online']]],
        ] as [$signalType, $signalData]) {
            $recordSourceChange($manager, $signalType, $signalData);
        }

        $this->assertTrue($browser->hasChanges());

        $browser->flushToSignalRouter();

        $this->assertFalse($browser->hasChanges());
        $this->assertCount(1, $browser->emittedChangeSets);

        $changes = $browser->emittedChangeSets[0]->all();
        $this->assertCount(2, $changes);
        $this->assertSame(
            [
                SourceChange::KIND_DB,
                SourceChange::KIND_RT,
            ],
            array_map(static fn(SourceChange $change): string => $change->kind, $changes),
        );
        $this->assertSame(
            [
                TableMutationType::Delete,
                TableMutationType::Delete,
            ],
            array_map(static fn(SourceChange $change): TableMutationType => $change->mutationType, $changes),
        );
        $this->assertSame(['name' => 'Grace'], $changes[0]->row);
        $this->assertSame(['userId' => 1, 'presence' => 'online'], $changes[1]->row);
    }
}

final class WorkerManagerBrowserContextTestBrowserContext extends BrowserContext
{
    /** @var list<SourceChangeSet> */
    public array $emittedChangeSets = [];

    public function configure(): void
    {
    }

    /**
     * Records the changes that reached the final browser emit hook.
     */
    protected function emitBrowserSignals(): void
    {
        $this->emittedChangeSets[] = $this->changes;
    }
}

final class WorkerManagerBrowserContextTestManager extends WorkerManager
{
    public function __construct(
        private readonly WorkerManagerBrowserContextTestAgent $testAgent,
    ) {
        parent::__construct(1);
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManager(): AgentManager
    {
        return new WorkerManagerBrowserContextTestAgentManager($this->testAgent);
    }
}

final class WorkerManagerBrowserContextTestAgentManager extends AgentManager
{
    public function __construct(
        private readonly WorkerManagerBrowserContextTestAgent $testAgent,
    ) {
    }

    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        return $this->testAgent;
    }
}

final class WorkerManagerBrowserContextTestAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_browser_context';

    public function onStop(): void
    {
    }
}
