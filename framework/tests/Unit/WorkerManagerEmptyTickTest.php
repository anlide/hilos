<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentIdleTracker;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Core\Browser\Context\HeldFacetCounts;
use Hilos\Core\Daemon\ContainedFailure;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Core\Daemon\Worker\WorkerTickUnit;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Database\Database;
use Hilos\Hilos;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Utils\Logger;
use Hilos\Utils\WorkerTickFailureLog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

require_once __DIR__ . '/PageSignalRouterPendingFrameTest.php';

/** The empty-hook optimization must leave every maintenance duty inside the agent's unit. */
final class WorkerManagerEmptyTickTest extends TestCase
{
    /** @var class-string<Hilos> Project facade to restore */
    private string $previousAppClass;

    private string $logFile;

    protected function setUp(): void
    {
        $this->previousAppClass = Hilos::appClass();
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, EmptyTickTestHilos::class);
        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-empty-tick');
        Logger::setLogFile($this->logFile);
        WorkerTickFailureLog::reset();
        ExecutionContext::clear();
        Hilos::$browser = null;
        Hilos::$rt = null;
    }

    protected function tearDown(): void
    {
        Database::handlerEnd();
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousAppClass);
        Hilos::$sr = null;
        Hilos::$browser = null;
        Hilos::$rt = null;
        ExecutionContext::clear();
        WorkerTickFailureLog::reset();
        Logger::resetLogFile();
        unlink($this->logFile);

        parent::tearDown();
    }

    /**
     * Detection is about implementation identity, before or after executing a parent call.
     *
     * @param class-string<EmptyTickTestAgent> $agentClass Hook inheritance case
     * @param bool $hasOverrides Whether all three hooks are overridden
     */
    #[DataProvider('hookImplementations')]
    public function testHookDetectionDoesNotExecuteHooksAndSurvivesParentCalls(string $agentClass, bool $hasOverrides): void
    {
        $agent = new $agentClass('1');
        for ($pass = 0; $pass < 2; $pass++) {
            $this->assertSame($hasOverrides, $agent->hasOwnTick());
            $this->assertSame($hasOverrides, $agent->hasOwnStopCheck());
            $this->assertSame($hasOverrides, $agent->hasCronRulesHook());
        }
        $this->assertSame([], $agent->calls);

        $manager = new EmptyTickTestManager();
        $manager->addTestAgent($agent);
        $manager->tickTestAgents();

        $this->assertSame($hasOverrides ? ['tick', 'cron', 'stop-check'] : [], $agent->calls);
        $this->assertSame($hasOverrides, $agent->hasOwnTick());
        $this->assertSame($hasOverrides, $agent->hasOwnStopCheck());
        $this->assertSame($hasOverrides, $agent->hasCronRulesHook());
        $this->assertSame([], $manager->failures);
    }

    /** @return iterable<string, array{class-string<EmptyTickTestAgent>, bool}> Implementation cases */
    public static function hookImplementations(): iterable
    {
        yield 'default' => [EmptyTickTestAgent::class, false];
        yield 'inherited default' => [EmptyTickTestInheritedDefaultAgent::class, false];
        yield 'override' => [EmptyTickTestOverrideAgent::class, true];
        yield 'parent call' => [EmptyTickTestParentCallAgent::class, true];
        yield 'inherited override' => [EmptyTickTestInheritedOverrideAgent::class, true];
    }

    public function testAnEmptyPassDoesNotEnterAnAgentContextOrOpenOrCloseAUnit(): void
    {
        $manager = new EmptyTickTestManager();
        $manager->addTestAgent(new EmptyTickTestAgent('1'));
        ExecutionContext::setCurrentAgentId('outside-agent');

        $manager->tickTestAgents();

        $this->assertSame('outside-agent', ExecutionContext::currentAgentId());
        $this->assertFalse($this->handlerIsOpen());

        // An empty pass must not close a unit its caller already opened either.
        Database::handlerStart();
        $manager->tickTestAgents();
        $this->assertTrue($this->handlerIsOpen());
        $this->assertSame('outside-agent', ExecutionContext::currentAgentId());
        $this->assertSame([], $this->workerProperty($manager, 'pageSignalRouters'));
        $this->assertSame([], $manager->failures);
    }

    public function testAnIndependentAgentInterfaceKeepsItsTickAndStopCheckInsideAUnit(): void
    {
        $manager = new EmptyTickTestManager();
        $agent = $this->createMock(EmptyTickTestIndependentAgent::class);
        $agent->method('getId')->willReturn('independent');
        $agent->method('getType')->willReturn('independent');
        $agent->expects($this->once())->method('onTick')->willReturnCallback(function (): void {
            $this->assertSame('independent', ExecutionContext::currentAgentId());
            $this->assertTrue($this->handlerIsOpen());
        });
        $agent->expects($this->once())->method('shouldStop')->willReturnCallback(function (): bool {
            $this->assertSame('independent', ExecutionContext::currentAgentId());
            $this->assertTrue($this->handlerIsOpen());
            return false;
        });
        $manager->addTestAgent($agent);

        $manager->tickTestAgents();

        $this->assertSame([], $manager->failures);
        $this->assertFalse($this->handlerIsOpen());
    }

    /** @param class-string<EmptyTickTestAgent> $agentClass One non-default maintenance hook */
    #[DataProvider('maintenanceHooks')]
    public function testACustomMaintenanceHookKeepsItsContextEvenWithADefaultTick(string $agentClass): void
    {
        $manager = new EmptyTickTestManager();
        $agent = new $agentClass('1');
        $manager->addTestAgent($agent);

        $manager->tickTestAgents();

        $this->assertSame([$agent->getId()], $agent->calls);
        $this->assertSame([], $manager->failures);
        $this->assertFalse($this->handlerIsOpen());
    }

    /** @return iterable<string, array{class-string<EmptyTickTestAgent>}> Conservative hook cases */
    public static function maintenanceHooks(): iterable
    {
        yield 'custom stop check' => [EmptyTickTestStopCheckAgent::class];
        yield 'custom cron rules' => [EmptyTickTestCronAgent::class];
    }

    public function testAnExistingRouterReleasesALivePendingSubscriptionForADefaultTick(): void
    {
        $manager = new EmptyTickTestManager();
        $agent = new EmptyTickTestAgent('1');
        $manager->addTestAgent($agent);
        $browser = new PendingFrameTestBrowser();
        Hilos::$browser = $browser;
        $factory = new PendingFrameTestPageFactory($agent);
        $router = new PageSignalRouter($factory, new ActionRouteConfig([]));
        $this->setWorkerProperty($manager, 'pageSignalRouters', [$agent->getId() => $router]);
        $router->dispatchPageSubscribe(
            new WebSocketPageSubscribeSignalDTO('ak-empty-tick', PendingFrameTestPage::PAGE, []),
            SignalSource::WEBSOCKET,
            PendingFrameTestPage::PAGE,
        );
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
        $browser->identity = ConnectionIdentity::resolved(27);

        $manager->tickTestAgents();

        $this->assertSame(['subscribe'], $factory->getPage(PendingFrameTestPage::PAGE)->handled);
        $this->assertSame(SignalTypeConstants::PAGE_RESPONSE, Hilos::$sr->getNextQueuedSignal()?->signalName->getName());
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
        $this->assertSame([], $manager->failures);
    }

    public function testACronWaitIsCollectedEvenWithAllDefaultHooks(): void
    {
        $manager = new EmptyTickTestManager();
        $agent = new EmptyTickTestAgent('1');
        $manager->addTestAgent($agent);
        $rule = new CronRule('empty_tick_wait', '* * * * *');
        $rule->fireOnNextCheck();
        $this->setWorkerProperty($manager, 'cronFireWaits', [$agent->getId() => ['unit-correlation' => $rule]]);
        $this->assertTrue($rule->shouldRun());

        $manager->tickTestAgents();

        $this->assertSame([], $this->workerProperty($manager, 'cronFireWaits'));
        $acks = $this->workerProperty($manager, 'cronFiredAcks');
        $this->assertCount(1, $acks);
        $this->assertSame('unit-correlation', $acks[0]->correlationId);
        $this->assertTrue($acks[0]->ok);
        $this->assertSame($rule->lastFiredAt * TimeConstants::MS_PER_SECOND, $acks[0]->firedAt);
        $this->assertSame([], $manager->failures);
    }

    public function testHeldFacetCountsKeepOnlyTheirAgentOnTheMaintenancePath(): void
    {
        $manager = new EmptyTickTestManager();
        $agent = new EmptyTickTestAgent('1');
        $manager->addTestAgent($agent);
        $browser = new class extends BrowserContext {};
        Hilos::$browser = $browser;
        $held = new ReflectionProperty(BrowserContext::class, 'heldFacetCounts');
        $other = new HeldFacetCounts('other-agent', 'page', 'ak-other', 'table', null, microtime(true));
        $held->setValue($browser, [
            $other,
            new HeldFacetCounts($agent->getId(), 'page', 'ak-empty-tick', 'table', null, microtime(true)),
        ]);
        $before = $held->getValue($browser);

        $this->assertTrue($browser->hasHeldFacetCounts($agent->getId()));
        $this->assertTrue($browser->hasHeldFacetCounts('other-agent'));
        $this->assertFalse($browser->hasHeldFacetCounts('absent-agent'));
        $this->assertSame($before, $held->getValue($browser));
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
        $manager->tickTestAgents();

        // No viewport remains, so release discards this agent's request and keeps its neighbor's.
        $this->assertFalse($browser->hasHeldFacetCounts($agent->getId()));
        $this->assertSame([$other], $held->getValue($browser));
        $this->assertSame([], $this->workerProperty($manager, 'pageSignalRouters'));
        $this->assertSame([], $manager->failures);
    }

    public function testADeclaredIdleWindowKeepsADefaultTickOnTheStopPath(): void
    {
        $manager = new EmptyTickTestManager();
        $agent = new EmptyTickTestIdleAgent('1');
        $manager->addTestAgent($agent);
        $tracker = $this->workerProperty($manager, 'agentIdleTracker');
        $this->assertInstanceOf(AgentIdleTracker::class, $tracker);
        $tracker->noteStarted($agent->getId(), microtime(true) - 241);

        $manager->tickTestAgents();

        $this->assertSame(['stop'], $agent->calls);
        $this->assertFalse($manager->hostsAgent($agent->getId()));
        $this->assertSame([], $manager->failures);
    }

    public function testSelfStopRunsInsideTheUnitAndRollsBackWhatOnStopLeavesOpen(): void
    {
        $manager = new EmptyTickTestManager();
        $agent = new EmptyTickTestAgent('1');
        $manager->addTestAgent($agent);
        $manager->tickTestAgents();
        $this->assertSame([], $agent->calls);

        $agent->leakOnStop = true;
        $agent->askToStop();
        $neighbor = new EmptyTickTestOverrideAgent('2');
        $manager->addTestAgent($neighbor);

        $manager->tickTestAgents();

        $this->assertSame(['stop'], $agent->calls);
        $this->assertTrue($agent->rolledBack);
        $this->assertFalse($manager->hostsAgent($agent->getId()));
        $this->assertSame(['tick', 'cron', 'stop-check'], $neighbor->calls);
        $this->assertCount(1, $manager->failures);
        $this->assertSame($agent->getId(), $manager->failures[0]->address);
        $this->assertSame(WorkerTickUnit::AGENT, $manager->failures[0]->unit);
        $this->assertFalse($this->handlerIsOpen());
    }

    public function testADeferredFailureBelongsToItsAgentAndTheNextAgentStillTicks(): void
    {
        $manager = new EmptyTickTestManager();
        $agent = new EmptyTickTestAgent('1');
        $neighbor = new EmptyTickTestOverrideAgent('2');
        $manager->addTestAgent($agent);
        $manager->addTestAgent($neighbor);
        $failure = new RuntimeException('deferred work failed');
        $rolledBack = false;
        $router = $this->getMockBuilder(PageSignalRouter::class)->disableOriginalConstructor()->onlyMethods([
            'releaseExpiredDeferredActions', 'releasePendingFrames', 'advanceBulkRuns',
        ])->getMock();
        $router->expects($this->once())->method('releaseExpiredDeferredActions')->willReturnCallback(function () use ($agent): void {
            $this->assertSame($agent->getId(), ExecutionContext::currentAgentId());
            $this->assertTrue($this->handlerIsOpen());
        });
        $router->expects($this->once())->method('releasePendingFrames')->willReturnCallback(function () use (
            $agent, $failure, &$rolledBack,
        ): void {
            $this->assertSame($agent->getId(), ExecutionContext::currentAgentId());
            Database::transactionStart();
            Database::onRollback(static function () use (&$rolledBack): void {
                $rolledBack = true;
            });
            throw $failure;
        });
        $router->expects($this->never())->method('advanceBulkRuns');
        $this->setWorkerProperty($manager, 'pageSignalRouters', [$agent->getId() => $router]);

        $manager->tickTestAgents();

        $this->assertTrue($rolledBack);
        $this->assertTrue($manager->hostsAgent($agent->getId()));
        $this->assertSame(['tick', 'cron', 'stop-check'], $neighbor->calls);
        $this->assertCount(2, $manager->failures);
        $this->assertSame($failure, $manager->failures[0]->failure);
        foreach ($manager->failures as $contained) {
            $this->assertSame(WorkerTickUnit::AGENT, $contained->unit);
            $this->assertSame($agent->getId(), $contained->address);
        }
        $this->assertFalse($this->handlerIsOpen());
    }

    /** @return bool Whether the DB handler boundary is currently open */
    private function handlerIsOpen(): bool
    {
        return new ReflectionProperty(Database::class, 'handlerOpen')->getValue();
    }

    /**
     * @param WorkerManager $manager Worker under test
     * @param string $name Worker-local state to inspect
     * @return mixed Current property value
     */
    private function workerProperty(WorkerManager $manager, string $name): mixed
    {
        return new ReflectionProperty(WorkerManager::class, $name)->getValue($manager);
    }

    /**
     * @param WorkerManager $manager Worker under test
     * @param string $name Worker-local state to arrange
     * @param mixed $value Fixture state
     */
    private function setWorkerProperty(WorkerManager $manager, string $name, mixed $value): void
    {
        new ReflectionProperty(WorkerManager::class, $name)->setValue($manager, $value);
    }
}

class EmptyTickTestAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_empty_tick';

    /** @var list<string> Hooks the worker actually invoked */
    public array $calls = [];

    public bool $leakOnStop = false;

    public bool $rolledBack = false;

    /** @param string $agentIndex Instance to distinguish from its neighbor */
    public function __construct(string $agentIndex)
    {
        $this->agentIndex = $agentIndex;
    }

    /** Requests the ordinary stop without overriding shouldStop(). */
    public function askToStop(): void
    {
        $this->selfStop();
    }

    /** Records the stop and optionally leaves a lazy transaction for the unit to close. */
    public function onStop(): void
    {
        TestCase::assertSame($this->getId(), ExecutionContext::currentAgentId());
        TestCase::assertTrue(new ReflectionProperty(Database::class, 'handlerOpen')->getValue());
        $this->calls[] = 'stop';
        if ($this->leakOnStop) {
            Database::transactionStart();
            Database::onRollback(function (): void {
                $this->rolledBack = true;
            });
        }
    }
}

final class EmptyTickTestInheritedDefaultAgent extends EmptyTickTestAgent
{
}

class EmptyTickTestOverrideAgent extends EmptyTickTestAgent
{
    /** Records useful tick work under the agent's boundary. */
    public function onTick(): void
    {
        TestCase::assertSame($this->getId(), ExecutionContext::currentAgentId());
        TestCase::assertTrue(new ReflectionProperty(Database::class, 'handlerOpen')->getValue());
        $this->calls[] = 'tick';
    }

    /** @return bool This agent stays up after recording the stop check */
    public function shouldStop(): bool
    {
        $this->calls[] = 'stop-check';
        return false;
    }

    /** @return list<CronRule> An empty current schedule after recording the read */
    protected function cronRules(): array
    {
        $this->calls[] = 'cron';
        return [];
    }
}

final class EmptyTickTestInheritedOverrideAgent extends EmptyTickTestOverrideAgent
{
}

final class EmptyTickTestParentCallAgent extends EmptyTickTestAgent
{
    /** Calls the default tick before doing useful work. */
    public function onTick(): void
    {
        parent::onTick();
        $this->calls[] = 'tick';
    }

    /** @return bool Default stop request after recording useful work */
    public function shouldStop(): bool
    {
        $this->calls[] = 'stop-check';
        return parent::shouldStop();
    }

    /** @return list<CronRule> Default schedule after recording useful work */
    protected function cronRules(): array
    {
        $this->calls[] = 'cron';
        return parent::cronRules();
    }
}

final class EmptyTickTestStopCheckAgent extends EmptyTickTestAgent
{
    /** @return bool Default stop request read inside the worker's unit */
    public function shouldStop(): bool
    {
        TestCase::assertTrue(new ReflectionProperty(Database::class, 'handlerOpen')->getValue());
        $this->calls[] = ExecutionContext::currentAgentId();
        return parent::shouldStop();
    }
}

final class EmptyTickTestCronAgent extends EmptyTickTestAgent
{
    /** @return list<CronRule> Empty schedule read inside the worker's unit */
    protected function cronRules(): array
    {
        TestCase::assertTrue(new ReflectionProperty(Database::class, 'handlerOpen')->getValue());
        $this->calls[] = ExecutionContext::currentAgentId();
        return [];
    }
}

final class EmptyTickTestIdleAgent extends EmptyTickTestAgent
{
    public const string AGENT_TYPE = 'unit_empty_tick_idle';
}

/** AgentInterface implementations can supply a stop check without inheriting AbstractAgent. */
interface EmptyTickTestIndependentAgent extends AgentInterface
{
    /** @return bool Whether this independent agent asks to stop */
    public function shouldStop(): bool;
}

abstract class EmptyTickTestHilos extends Hilos
{
    public const array AGENTS = [
        EmptyTickTestIdleAgent::AGENT_TYPE => [AgentRegistryKey::IDLE_TIMEOUT => 240],
    ];
}

final class EmptyTickTestManager extends WorkerManager
{
    /** @var list<ContainedFailure> Failures attributed to the units that did the work */
    public array $failures = [];

    public function __construct()
    {
        parent::__construct(1);
    }

    /** @param AgentInterface $agent Agent to tick without running a start hook */
    public function addTestAgent(AgentInterface $agent): void
    {
        $this->agentManager->addAgent($agent->getId(), $agent);
    }

    /**
     * @param string $agentId Agent to look for
     * @return bool Whether the worker still holds the agent
     */
    public function hostsAgent(string $agentId): bool
    {
        return $this->agentManager->hasAgent($agentId);
    }

    /** Runs the real tick pass without a socket or a clock wait. */
    public function tickTestAgents(): void
    {
        Closure::bind(function (): void {
            $this->tickAgents();
        }, $this, WorkerManager::class)();
    }

    /** @return SignalRouter Ordinary worker-local router */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    /** @return AgentManager Manager populated directly by each test */
    protected function createAgentManager(): AgentManager
    {
        return new class extends AgentManager {
            /**
             * @param string $agentType Unused type
             * @param ?string $agentIndex Unused index
             * @return AgentInterface Never returns: tests supply live instances directly
             */
            protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
            {
                throw new RuntimeException('The empty tick test supplies its own agents.');
            }
        };
    }

    /** @param ContainedFailure $failure Failure handed to the project after cleanup */
    protected function onTickFailure(ContainedFailure $failure): void
    {
        $this->failures[] = $failure;
    }
}
