<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\Clock\ClusterClock;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Leadership;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\CLI\Exception\TestOnlyCommandOnProductionException;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Core\Daemon\CronFireTestRequests;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\Server\CommandServer;
use Hilos\Socket\Worker\DTO\CronSignalDTO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Unit tests for default daemon cron signal dispatch, and for a rule of the daemon fired once now
 * by test:cron:fire. Every firing case names APP_ENV explicitly: the master re-asks the gate.
 */
final class DaemonManagerCronTest extends TestCase
{
    private ?EnvAccessor $previousEnv;
    private ?ClusterContext $previousCluster;
    private string|false $previousAppEnv;
    private string|false $previousClusterEnabled;

    protected function setUp(): void
    {
        $this->previousEnv = Hilos::$env;
        $this->previousCluster = Hilos::$cluster;
        $this->previousAppEnv = getenv('APP_ENV');
        $this->previousClusterEnabled = getenv('CLUSTER_ENABLED');
        Hilos::$cluster = null;
    }

    public function tearDown(): void
    {
        Hilos::$sr = null;
        Hilos::$env = $this->previousEnv;
        Hilos::$cluster = $this->previousCluster;
        putenv($this->previousAppEnv === false ? 'APP_ENV' : 'APP_ENV=' . $this->previousAppEnv);
        putenv($this->previousClusterEnabled === false ? 'CLUSTER_ENABLED' : 'CLUSTER_ENABLED=' . $this->previousClusterEnabled);

        parent::tearDown();
    }

    public function testOnCronQueuesDaemonCronSignal(): void
    {
        $manager = new DaemonManagerCronTestManager();
        $rule = new CronRule('cleanup_history', '*/30 * * * *');

        $manager->invokeOnCron($rule);

        $signal = Hilos::$sr->getNextQueuedSignal();

        $this->assertNotNull($signal);
        $this->assertSame(SignalSource::DAEMON, $signal->signalSource->getSource());
        $this->assertSame(SignalTypeConstants::CRON, $signal->signalType->getType());
        $this->assertSame('cleanup_history', $signal->signalName->getName());
        $this->assertInstanceOf(CronSignalDTO::class, $signal->data);
        $this->assertSame('cleanup_history', $signal->data->cronName);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testClockCheckRuleIsRegisteredOnlyInClusterMode(): void
    {
        $this->environment('test');
        $manager = new DaemonManagerCronTestManager();
        $register = new ReflectionMethod(DaemonManager::class, 'registerClusterClockCheckRule');
        $register->invoke($manager);
        self::assertSame([], $manager->getCronRules());

        Hilos::$cluster = new ClusterContext();
        putenv('CLUSTER_ENABLED=false');
        $register->invoke($manager);
        self::assertSame([], $manager->getCronRules());

        putenv('CLUSTER_ENABLED=true');
        $register->invoke($manager);
        self::assertSame(['hilos_cluster_clock_check'], array_keys($manager->getCronRules()));
        self::assertSame('0 4 * * *', $manager->getCronRules()['hilos_cluster_clock_check']->expression);
    }

    public function testClockCronFiresTheMasterMeasurementWithoutAnAgentSignal(): void
    {
        $this->environment('test');
        putenv('CLUSTER_ENABLED=true');
        $leadership = new DaemonManagerCronTestLeadership();
        $leadership->leader = true;
        Hilos::$cluster = new ClusterContext();
        Hilos::$cluster->registerLeadership($leadership);
        $linkReads = 0;
        $clock = new ClusterClock(static function () use (&$linkReads): array {
            ++$linkReads;
            return [];
        });
        Hilos::$cluster->registerClock($clock);
        self::assertSame($clock, Hilos::$cluster->clock());
        $manager = new DaemonManagerCronTestManager();
        $manager->onBecameLeader(1);
        self::assertSame(1, $linkReads, 'Winning leadership starts the first measurement');
        new ReflectionMethod(DaemonManager::class, 'registerClusterClockCheckRule')->invoke($manager);
        $console = $manager->addConsole();
        $manager->markWorkersReady();
        $manager->markMinuteChecked();
        $manager->markFrameClean();

        $manager->requestCronFire($this->ask('clock-fire', ClusterClock::CRON_RULE));

        self::assertSame(2, $linkReads, 'The cron firing calls checkAll on the registered clock');
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
        self::assertTrue($manager->frameDirty());
        self::assertNotNull($manager->getCronRules()[ClusterClock::CRON_RULE]->lastFiredAt);
        self::assertFalse($manager->getCronRules()[ClusterClock::CRON_RULE]->isArmedForNextCheck());
        self::assertCount(1, $console->replies);
        self::assertTrue($console->replies[0]->isOk());

        $manager->onLostLeadership(1);
        $clock->checkAll();
        self::assertSame(2, $linkReads, 'Losing leadership stops measurement triggers');
    }

    /** The scheduler has checked this minute already; the armed rule fires anyway, once, and answers its moment. */
    public function testADaemonRuleFiresAtOncePastTheMinuteGuard(): void
    {
        $this->environment('test');
        $manager = new DaemonManagerCronTestManager();
        $console = $manager->addConsole();
        $manager->addRule('cleanup_history', '0 3 1 1 *');
        $manager->markWorkersReady();
        $manager->markMinuteChecked();
        $manager->markFrameClean();
        $before = time();

        $manager->requestCronFire($this->ask('c-1', 'cleanup_history'));

        $signal = Hilos::$sr->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertSame(SignalTypeConstants::CRON, $signal->signalType->getType());
        $this->assertSame('cleanup_history', $signal->signalName->getName());
        $this->assertInstanceOf(CronSignalDTO::class, $signal->data);
        $this->assertSame('0 3 1 1 *', $signal->data->cronExpression);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());

        $rule = $manager->getCronRules()['cleanup_history'];
        $this->assertSame('0 3 1 1 *', $rule->expression);
        $this->assertFalse($rule->isArmedForNextCheck());
        $this->assertNotNull($rule->lastFiredAt);
        $this->assertGreaterThanOrEqual($before, $rule->lastFiredAt);
        $this->assertLessThanOrEqual(time(), $rule->lastFiredAt);
        $this->assertTrue($manager->frameDirty());

        $this->assertCount(1, $console->replies);
        $this->assertSame('c-1', $console->replies[0]->correlationId);
        $this->assertTrue($console->replies[0]->isOk());
        $this->assertSame([CommandConstants::FIELD_FIRED_AT => $rule->lastFiredAt * 1000], $console->replies[0]->payload);
    }

    public function testARuleTheDaemonDoesNotHoldIsRefused(): void
    {
        $this->environment('test');
        $manager = new DaemonManagerCronTestManager();
        $console = $manager->addConsole();
        $manager->addRule('cleanup_history', '0 3 1 1 *');
        $manager->markWorkersReady();

        $manager->requestCronFire($this->ask('c-2', 'nightly_digest'));

        $this->assertRefusedWithoutFiring($manager, $console, 'c-2', 'The daemon has no cron rule nightly_digest.');
    }

    public function testAFollowerRefusesBecauseDaemonRulesRunOnTheLeader(): void
    {
        $this->environment('test');
        $leadership = new DaemonManagerCronTestLeadership();
        $cluster = new ClusterContext();
        $cluster->registerLeadership($leadership);
        Hilos::$cluster = $cluster;
        $manager = new DaemonManagerCronTestManager();
        $console = $manager->addConsole();
        $manager->addRule('cleanup_history', '0 3 1 1 *');
        $manager->markWorkersReady();

        $manager->requestCronFire($this->ask('c-3', 'cleanup_history'));

        $this->assertRefusedWithoutFiring(
            $manager,
            $console,
            'c-3',
            'Daemon cron rules run on the leader; this node is not the leader.',
        );
    }

    public function testWorkersNotReadyYetAreARefusal(): void
    {
        $this->environment('test');
        $manager = new DaemonManagerCronTestManager();
        $console = $manager->addConsole();
        $manager->addRule('cleanup_history', '0 3 1 1 *');

        $manager->requestCronFire($this->ask('c-4', 'cleanup_history'));

        $this->assertRefusedWithoutFiring($manager, $console, 'c-4', "The daemon's workers are not ready yet.");
    }

    public function testProductionIsRefusedAtTheMasterDoor(): void
    {
        $this->environment('prod');
        $manager = new DaemonManagerCronTestManager();
        $console = $manager->addConsole();
        $manager->addRule('cleanup_history', '0 3 1 1 *');
        $manager->markWorkersReady();

        $manager->requestCronFire($this->ask('c-5', 'cleanup_history'));

        $this->assertRefusedWithoutFiring(
            $manager,
            $console,
            'c-5',
            TestOnlyCommandOnProductionException::message(CliCommands::CRON_TEST_FIRE),
        );
    }

    public function testAPayloadWithoutARuleNameIsRefused(): void
    {
        $this->environment('test');
        $manager = new DaemonManagerCronTestManager();
        $console = $manager->addConsole();
        $manager->addRule('cleanup_history', '0 3 1 1 *');
        $manager->markWorkersReady();

        $manager->requestCronFire(new CommandRequestDTO('c-6', CliCommands::CRON_TEST_FIRE, [CommandConstants::FIELD_AGENT_ID => null]));

        $this->assertRefusedWithoutFiring($manager, $console, 'c-6', CronFireTestRequests::INVALID_PAYLOAD);
    }

    /**
     * @param string $correlationId Console correlation
     * @param string $ruleName Rule of the daemon to fire
     * @return CommandRequestDTO The ask as the command channel hands it to the master
     */
    private function ask(string $correlationId, string $ruleName): CommandRequestDTO
    {
        return new CommandRequestDTO($correlationId, CliCommands::CRON_TEST_FIRE, [
            CommandConstants::FIELD_AGENT_ID => null,
            CommandConstants::FIELD_RULE_NAME => $ruleName,
        ]);
    }

    /** @param string $env Environment of the master under test */
    private function environment(string $env): void
    {
        putenv('APP_ENV=' . $env);
        Hilos::$env = new EnvAccessor();
    }

    /**
     * @param DaemonManagerCronTestManager $manager Master that refused
     * @param DaemonManagerCronTestConsole $console Console that holds the ask
     * @param string $correlationId Refused ask
     * @param string $reason Exact refusal expected on the console
     */
    private function assertRefusedWithoutFiring(
        DaemonManagerCronTestManager $manager,
        DaemonManagerCronTestConsole $console,
        string $correlationId,
        string $reason,
    ): void {
        $this->assertCount(1, $console->replies);
        $this->assertSame($correlationId, $console->replies[0]->correlationId);
        $this->assertFalse($console->replies[0]->isOk());
        $this->assertSame($reason, $console->replies[0]->payload[CommandConstants::FIELD_MESSAGE] ?? null);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
        foreach ($manager->getCronRules() as $rule) {
            $this->assertNull($rule->lastFiredAt);
            $this->assertFalse($rule->isArmedForNextCheck());
        }
    }
}

final class DaemonManagerCronTestManager extends DaemonManager
{
    public function invokeOnCron(CronRule $rule): void
    {
        $this->onCron($rule);
    }

    /** @return DaemonManagerCronTestConsole Console that captures the master's replies */
    public function addConsole(): DaemonManagerCronTestConsole
    {
        $console = new DaemonManagerCronTestConsole();
        $this->registerServer($console);
        return $console;
    }

    /**
     * @param string $name Rule name
     * @param string $expression Rule expression
     */
    public function addRule(string $name, string $expression): void
    {
        $this->addCronRule($name, $expression);
    }

    public function markWorkersReady(): void
    {
        new ReflectionProperty(DaemonManager::class, 'workersReady')->setValue($this, true);
    }

    /** Shuts the scheduler's minute guard, as a check earlier in this minute leaves it. */
    public function markMinuteChecked(): void
    {
        new ReflectionProperty(DaemonManager::class, 'lastCronCheckMinute')->setValue($this, (int)floor(time() / 60));
    }

    public function markFrameClean(): void
    {
        new ReflectionProperty(DaemonManager::class, 'cronFrameDirty')->setValue($this, false);
    }

    /** @return bool Whether the cron part of the node picture is due to be sent again */
    public function frameDirty(): bool
    {
        return new ReflectionProperty(DaemonManager::class, 'cronFrameDirty')->getValue($this);
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new DaemonManagerCronTestAgentManagerDaemon();
    }
}

final class DaemonManagerCronTestAgentManagerDaemon extends AgentManagerDaemon
{
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException('not used in test');
    }
}

final class DaemonManagerCronTestConsole extends CommandServer
{
    /** @var list<CommandReplyDTO> */
    public array $replies = [];

    /** Bypasses the listening socket; the test only reads what the master delivers. */
    public function __construct()
    {
    }

    /**
     * @param string $correlationId Held console correlation
     * @param CommandReplyDTO $reply Reply the master delivers
     */
    public function deliver(string $correlationId, CommandReplyDTO $reply): void
    {
        $this->replies[] = $reply;
    }

    protected function onStart(): void
    {
    }
}

final class DaemonManagerCronTestLeadership implements Leadership
{
    public bool $leader = false;

    /** @return bool Whether this test node leads */
    public function amLeader(): bool
    {
        return $this->leader;
    }

    /** @return ?string No known leader id */
    public function leaderId(): ?string
    {
        return null;
    }

    /** @return bool No known leader WebSocket */
    public function leaderWebSocketOpen(): bool
    {
        return false;
    }

    /** @return bool No quorum required by this test */
    public function hasQuorum(): bool
    {
        return false;
    }
}
