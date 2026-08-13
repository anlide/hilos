<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeSet;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Core\Sync\DTO\SyncSignalDataInterface;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;
use Closure;

/**
 * Unit tests for WorkerManager browser context hooks.
 */
final class WorkerManagerBrowserContextTest extends TestCase
{
    public function tearDown(): void
    {
        Hilos::$browser = null;
        Hilos::$sr = null;

        parent::tearDown();
    }

    public function testWorkerRecordsDbRtSourceChangesInBrowserContext(): void
    {
        $browser = new WorkerManagerBrowserContextTestBrowserContext();
        Hilos::$browser = $browser;

        $manager = new WorkerManagerBrowserContextTestManager(new WorkerManagerBrowserContextTestAgent());

        $recordSourceChange = Closure::bind(
            static function (WorkerManager $manager, SyncSignalDataInterface $signalData): void {
                $manager->recordBrowserSourceChange($signalData);
            },
            null,
            WorkerManager::class,
        );

        foreach ([
            new DbSyncCreatedSignalData('users', '1', ['name' => 'Ada']),
            new DbSyncUpdatedSignalData('users', '1', ['name' => 'Grace']),
            new DbSyncDeletedSignalData('users', '1', ['name' => 'Grace']),
            new RtSyncCreatedSignalData('connections', 'ak-1', ['userId' => 1]),
            new RtSyncUpdatedSignalData('connections', 'ak-1', ['presence' => 'online']),
            new RtSyncDeletedSignalData('connections', 'ak-1', ['presence' => 'online']),
        ] as $signalData) {
            $recordSourceChange($manager, $signalData);
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
