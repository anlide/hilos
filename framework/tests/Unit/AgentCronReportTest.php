<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentCronRuleSnapshot;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Core\Daemon\Cron\Exception\CronRuleNameException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\SignalRouter;
use Hilos\DaemonSection\DTO\DaemonAgentCronSignalData;
use Hilos\Hilos;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Agent schedules are reported whole after a tick and repaired only while non-empty; a rule armed
 * by name for a test fires at its next check and its run enters the next report.
 */
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
        $first = new CronReportFixedClockRule('z-last', '* * * * *');
        $second = new CronRule('a-first', '0 3 * * *');
        $agent->rules = [$first, $second];
        $agent->reportCronRulesIfDue(100.0);
        $reported = $this->takeReport();
        self::assertSame('cron_probe', $reported->agentId);
        self::assertSame(['a-first', 'z-last'], array_map(static fn ($rule): string => $rule->name, $reported->rules));
        self::assertEquals($reported, DaemonAgentCronSignalData::fromArray($reported->toArray()));
        self::assertNull($reported->rules[0]->lastRunAt);
        self::assertNull($reported->rules[1]->lastRunAt);

        $agent->reportCronRulesIfDue(101.0);
        self::assertNull(Hilos::$sr?->getNextQueuedSignal());
        $first->lastRun = 0.0;
        self::assertTrue($first->shouldRun());
        self::assertSame(1_767_225_600, $first->lastFiredAt);
        self::assertFalse($first->shouldRun());
        $agent->reportCronRulesIfDue(102.0);
        $fired = $this->takeReport();
        self::assertSame(1_767_225_600_000, $fired->rules[1]->lastRunAt);
        self::assertSame($fired->toArray(), DaemonAgentCronSignalData::fromArray($fired->toArray())->toArray());

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

    /** A retained mutable rule must be compared with the values observed before the mutation. */
    public function testMutableRuleChangesAndUnchangedObservations(): void
    {
        $agent = new CronReportProbeAgent();
        $rule = new CronRule('daily', '0 3 * * *');
        $agent->rules = [$rule];
        $agent->reportCronRulesIfDue(100.0);
        $this->takeReport();
        $observed = new ReflectionProperty(AbstractAgent::class, 'observedCronRules');
        $initial = $observed->getValue($agent);
        self::assertInstanceOf(AgentCronRuleSnapshot::class, $initial[0]);
        self::assertSame($rule, $initial[0]->rule);
        self::assertSame('daily', $initial[0]->name);

        $agent->reportCronRulesIfDue(101.0);
        self::assertSame($initial, $observed->getValue($agent));
        self::assertNull(Hilos::$sr?->getNextQueuedSignal());

        $rule->expression = '30 4 * * *';
        $agent->reportCronRulesIfDue(102.0);
        self::assertSame('30 4 * * *', $this->takeReport()->rules[0]->expression);
        self::assertSame('0 3 * * *', $initial[0]->expression);

        $rule->lastFiredAt = 1_767_225_600;
        $agent->reportCronRulesIfDue(103.0);
        self::assertSame(1_767_225_600_000, $this->takeReport()->rules[0]->lastRunAt);
        $fired = $observed->getValue($agent);
        self::assertSame(1_767_225_600, $fired[0]->lastFiredAt);
        self::assertNull($initial[0]->lastFiredAt);

        $agent->reportCronRulesIfDue(162.0);
        self::assertSame($fired, $observed->getValue($agent));
        self::assertNull(Hilos::$sr?->getNextQueuedSignal());
        $agent->reportCronRulesIfDue(163.0);
        self::assertSame(1_767_225_600_000, $this->takeReport()->rules[0]->lastRunAt);
        self::assertNotSame($fired[0], $observed->getValue($agent)[0]);

        $rule->lastFiredAt = null;
        $agent->reportCronRulesIfDue(164.0);
        self::assertNull($this->takeReport()->rules[0]->lastRunAt);
    }

    /** Hook order and identities are observed separately from the sorted outgoing schedule. */
    public function testDynamicSetReplacementAndReordering(): void
    {
        $agent = new CronReportProbeAgent();
        $first = new CronRule('z-last', '0 3 * * *');
        $second = new CronRule('a-first', '30 * * * *');
        $agent->rules = [$first, $second];
        $agent->reportCronRulesIfDue(100.0);
        $this->takeReport();
        $observed = new ReflectionProperty(AbstractAgent::class, 'observedCronRules');

        $agent->rules = [$second, $first];
        $agent->reportCronRulesIfDue(101.0);
        self::assertNull(Hilos::$sr?->getNextQueuedSignal());
        $reordered = $observed->getValue($agent);
        self::assertSame([$second, $first], array_map(static fn ($snapshot): CronRule => $snapshot->rule, $reordered));
        $agent->reportCronRulesIfDue(102.0);
        self::assertSame($reordered, $observed->getValue($agent));
        self::assertNull(Hilos::$sr?->getNextQueuedSignal());

        $replacement = new CronRule('a-first', '30 * * * *');
        $agent->rules = [$replacement, $first];
        $agent->reportCronRulesIfDue(103.0);
        self::assertSame($replacement, $observed->getValue($agent)[0]->rule);
        self::assertNull(Hilos::$sr?->getNextQueuedSignal());
        $replaced = $observed->getValue($agent);
        $agent->reportCronRulesIfDue(104.0);
        self::assertSame($replaced, $observed->getValue($agent));
        self::assertNull(Hilos::$sr?->getNextQueuedSignal());

        $agent->rules = [$first];
        $agent->reportCronRulesIfDue(105.0);
        self::assertSame(['z-last'], array_map(static fn ($rule): string => $rule->name, $this->takeReport()->rules));
        $agent->rules = [$first, new CronRule('b-added', '* * * * *')];
        $agent->reportCronRulesIfDue(106.0);
        self::assertSame(['b-added', 'z-last'], array_map(static fn ($rule): string => $rule->name, $this->takeReport()->rules));
        $agent->rules = [new CronRule('renamed', '0 3 * * *'), $replacement];
        $agent->reportCronRulesIfDue(107.0);
        self::assertSame(['a-first', 'renamed'], array_map(static fn ($rule): string => $rule->name, $this->takeReport()->rules));
    }

    /** @return iterable<string, array{bool, bool}> Prior observation and duplicate identity cases */
    public static function duplicateRuleSets(): iterable
    {
        yield 'initial distinct rules' => [false, false];
        yield 'replacement by duplicate name' => [true, false];
        yield 'replacement by repeated reference' => [true, true];
    }

    /**
     * A refused observation must never become the next tick's fast-path baseline.
     *
     * @param bool $reportBefore Whether a valid schedule was already reported
     * @param bool $repeatReference Whether both entries point at the same object
     */
    #[DataProvider('duplicateRuleSets')]
    public function testDuplicateNamesAreRefusedOnEveryObservation(bool $reportBefore, bool $repeatReference): void
    {
        $agent = new CronReportProbeAgent();
        $first = new CronRule('daily', '0 3 * * *');
        if ($reportBefore) {
            $agent->rules = [$first, new CronRule('other', '0 3 * * *')];
            $agent->reportCronRulesIfDue(100.0);
            $this->takeReport();
        }
        $agent->rules = [$first, $repeatReference ? $first : new CronRule('daily', '0 3 * * *')];
        foreach ([101.0, 102.0] as $now) {
            try {
                $agent->reportCronRulesIfDue($now);
                self::fail('Duplicate names must be refused before caching an observation');
            } catch (InvalidFormatException $exception) {
                self::assertStringContainsString('unique sorted names', $exception->getMessage());
            }
            self::assertNull(Hilos::$sr?->getNextQueuedSignal());
        }
    }

    public function testArmingFindsTheRuleByNameAndTheReportCarriesItsRun(): void
    {
        $agent = new CronReportProbeAgent();
        $nightly = new CronReportFixedClockRule('nightly', '0 3 * * *');
        $hourly = new CronReportFixedClockRule('hourly', '30 * * * *');
        $agent->rules = [$nightly, $hourly];
        $agent->reportCronRulesIfDue(100.0);
        self::assertNull($this->takeReport()->rules[1]->lastRunAt);

        self::assertNull($agent->armCronRuleForTest('weekly'));
        self::assertFalse($nightly->shouldRun());
        self::assertFalse($hourly->shouldRun());

        self::assertSame($nightly, $agent->armCronRuleForTest('nightly'));
        self::assertFalse($hourly->shouldRun());
        self::assertTrue($nightly->shouldRun());
        $agent->reportCronRulesIfDue(101.0);
        $fired = $this->takeReport();
        self::assertSame(['hourly', 'nightly'], array_map(static fn ($rule): string => $rule->name, $fired->rules));
        self::assertNull($fired->rules[0]->lastRunAt);
        self::assertSame('0 3 * * *', $fired->rules[1]->expression);
        self::assertSame(1_767_225_600_000, $fired->rules[1]->lastRunAt);
    }

    public function testAProjectWithoutDaemonFeatureSendsNothing(): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, CronReportDisabledHilos::class);
        $agent = new CronReportProbeAgent();
        $agent->rules = [new CronRule('daily', '0 3 * * *')];
        $agent->reportCronRulesIfDue(100.0);
        self::assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testProjectStartRefusesReservedNameBeforeAReportEvenWithoutDaemonFeature(): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, CronReportDisabledHilos::class);
        $agent = new CronReportReservedPrefixAgent();

        try {
            $agent->onStart();
            self::fail('A project rule with the reserved prefix must be refused at start');
        } catch (CronRuleNameException $exception) {
            self::assertStringContainsString('hilos_project_rule', $exception->getMessage());
            self::assertStringContainsString(CronRule::FRAMEWORK_NAME_PREFIX, $exception->getMessage());
        }

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

final class CronReportReservedPrefixAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'cron_reserved';

    public function onStart(): void
    {
        new CronRule('hilos_project_rule', '* * * * *');
    }

    public function onStop(): void
    {
    }
}

final class CronReportFixedClockRule extends CronRule
{
    /** @return int A known epoch second at which this test fires the rule */
    protected function currentTimestamp(): int
    {
        return 1_767_225_600;
    }
}
