<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\SignalRouter;
use Hilos\DaemonSection\DTO\DaemonAgentCronSignalData;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** Agent schedules are reported whole after a tick and repaired only while non-empty. */
final class AgentCronReportTest extends TestCase
{
    /** @var class-string<Hilos> */
    private string $previousAppClass;
    private ?SignalRouter $previousRouter;

    protected function setUp(): void
    {
        $this->previousAppClass = Hilos::appClass();
        $this->previousRouter = Hilos::$sr;
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, CronReportEnabledHilos::class);
        Hilos::$sr = new SignalRouter();
    }

    protected function tearDown(): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousAppClass);
        Hilos::$sr = $this->previousRouter;
        parent::tearDown();
    }

    public function testNoRulesProduceNoFrame(): void
    {
        $agent = new CronReportProbeAgent();
        $agent->reportCronRulesIfDue(100.0);
        self::assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testChangeFiringRepairAndWithdrawal(): void
    {
        $agent = new CronReportProbeAgent();
        $first = new CronRule('z-last', '* * * * *');
        $second = new CronRule('a-first', '0 3 * * *');
        $agent->rules = [$first, $second];
        $agent->reportCronRulesIfDue(100.0);
        $reported = $this->takeReport();
        self::assertSame('cron_probe', $reported->agentId);
        self::assertSame(['a-first', 'z-last'], array_map(static fn ($rule): string => $rule->name, $reported->rules));
        self::assertEquals($reported, DaemonAgentCronSignalData::fromArray($reported->toArray()));

        $agent->reportCronRulesIfDue(101.0);
        self::assertNull(Hilos::$sr?->getNextQueuedSignal());
        $first->lastRun = 0.0;
        self::assertTrue($first->shouldRun());
        $agent->reportCronRulesIfDue(102.0);
        self::assertNotNull($this->takeReport()->rules[1]->lastRunAt);

        $agent->reportCronRulesIfDue(161.0);
        self::assertNull(Hilos::$sr?->getNextQueuedSignal());
        $agent->reportCronRulesIfDue(162.0);
        self::assertCount(2, $this->takeReport()->rules);

        $agent->rules = [];
        $agent->reportCronRulesIfDue(163.0);
        self::assertSame([], $this->takeReport()->rules);
        $agent->reportCronRulesIfDue(300.0);
        self::assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testAProjectWithoutDaemonFeatureSendsNothing(): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, CronReportDisabledHilos::class);
        $agent = new CronReportProbeAgent();
        $agent->rules = [new CronRule('daily', '0 3 * * *')];
        $agent->reportCronRulesIfDue(100.0);
        self::assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    /** @return DaemonAgentCronSignalData Queued agent report */
    private function takeReport(): DaemonAgentCronSignalData
    {
        $signal = Hilos::$sr?->getNextQueuedSignal();
        self::assertNotNull($signal);
        self::assertSame(HilosSignalConstants::DAEMON_AGENT_CRON, $signal->signalName->getName());
        $data = $signal->data?->data;
        self::assertInstanceOf(DaemonAgentCronSignalData::class, $data);
        return $data;
    }
}

final class CronReportProbeAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'cron_probe';

    /** @var list<CronRule> */
    public array $rules = [];

    public function onStop(): void
    {
    }

    /** @return list<CronRule> Current test rules */
    protected function cronRules(): array
    {
        return $this->rules;
    }
}

abstract class CronReportEnabledHilos extends Hilos
{
    protected const array FEATURES = [HilosFeature::DAEMON];
}

abstract class CronReportDisabledHilos extends Hilos
{
    protected const array FEATURES = [];
}
