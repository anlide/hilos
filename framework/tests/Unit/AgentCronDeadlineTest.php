<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\EnvConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Hilos;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Calendar wake deadlines remain local and do not depend on the Daemon feature. */
final class AgentCronDeadlineTest extends TestCase
{
    private ?EnvAccessor $previousEnv;
    private string|false $previousProjectZone;

    protected function setUp(): void
    {
        $this->previousEnv = Hilos::$env;
        $this->previousProjectZone = getenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name);
        putenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name . '=UTC');
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
        DeadlineTestRule::$searches = 0;
    }

    protected function tearDown(): void
    {
        Hilos::$env = $this->previousEnv;
        putenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name . ($this->previousProjectZone === false ? '' : '=' . $this->previousProjectZone));
        parent::tearDown();
    }

    /** @return iterable<string, array{bool}> First observation or reuse of a prepared deadline */
    public static function minuteCrossings(): iterable
    {
        yield 'first observation' => [false];
        yield 'cached deadline' => [true];
    }

    /** @param bool $prepared Whether the previous tick already calculated this deadline */
    #[DataProvider('minuteCrossings')]
    public function testASixHundredSecondAllowanceCannotSkipAMinuteCrossedByTheTick(bool $prepared): void
    {
        $agent = new DeadlineTestAgent();
        $rule = new DeadlineTestRule('minute', '* * * * *');
        $agent->rules = [$rule];
        $minute = strtotime('2026-10-08 10:15:00 UTC');
        $rule->lastRun = floor(($minute - 1) / 60);
        $rule->now = $minute - 1;
        if ($prepared) {
            $agent->onTick();
            $agent->completeCronTick($minute - 0.9, $minute - 0.8);
        }

        // The rule checked 10:14; the hook's remaining work carried the worker into 10:15.
        $agent->onTick();
        $agent->completeCronTick($minute - 0.01, $minute + 0.01);
        self::assertSame(600.0, $agent->getMaxIdleSleepSeconds());
        self::assertSame((float)$minute, $agent->getCronDeadline());
        self::assertLessThanOrEqual($minute + 0.01, $agent->getCronDeadline());
        self::assertSame(0, $agent->runs);
        self::assertSame(1, DeadlineTestRule::$searches);

        // The overdue deadline asks for another tick now, through the rule's own shouldRun().
        $rule->now = $minute;
        $agent->onTick();
        $agent->completeCronTick($minute + 0.02, $minute + 0.03);
        self::assertSame(1, $agent->runs);
        self::assertSame($minute, $rule->lastFiredAt);
        self::assertSame((float)($minute + 60), $agent->getCronDeadline());
        self::assertSame(2, DeadlineTestRule::$searches);
        self::assertFalse($rule->shouldRun());
    }

    public function testRuleSetIdentityExpressionAndExpiryInvalidateTheCachedSearch(): void
    {
        $agent = new DeadlineTestAgent();
        $now = (float)strtotime('2026-10-08 10:14:00 UTC');
        $agent->completeCronTick($now, $now);
        self::assertNull($agent->getCronDeadline());
        $rule = new DeadlineTestRule('daily', '0 12 * * *');
        $agent->rules = [$rule];
        $agent->completeCronTick($now, $now);
        self::assertSame((float)strtotime('2026-10-08 12:00:00 UTC'), $agent->getCronDeadline());
        self::assertSame(1, DeadlineTestRule::$searches);
        for ($tick = 1; $tick <= 20; $tick++) {
            $agent->completeCronTick($now + $tick / 10, $now + $tick / 10);
            $agent->getCronDeadline();
        }
        self::assertSame(1, DeadlineTestRule::$searches);
        self::assertSame(22, $agent->observations);

        $rule->expression = '0 11 * * *';
        $agent->completeCronTick($now + 3, $now + 3);
        self::assertSame((float)strtotime('2026-10-08 11:00:00 UTC'), $agent->getCronDeadline());
        self::assertSame(2, DeadlineTestRule::$searches);
        $agent->rules[] = new DeadlineTestRule('sooner', '30 10 * * *');
        $agent->completeCronTick($now + 4, $now + 4);
        self::assertSame((float)strtotime('2026-10-08 10:30:00 UTC'), $agent->getCronDeadline());
        self::assertSame(4, DeadlineTestRule::$searches);
        $agent->rules = [$rule];
        $agent->completeCronTick($now + 5, $now + 5);
        self::assertSame((float)strtotime('2026-10-08 11:00:00 UTC'), $agent->getCronDeadline());
        self::assertSame(5, DeadlineTestRule::$searches);
        $agent->rules = [new DeadlineTestRule('daily', '0 11 * * *')];
        $agent->completeCronTick($now + 6, $now + 6);
        self::assertSame(6, DeadlineTestRule::$searches);
        $deadline = $agent->getCronDeadline();
        $agent->completeCronTick($deadline, $deadline);
        self::assertSame((float)strtotime('2026-10-09 11:00:00 UTC'), $agent->getCronDeadline());
        self::assertSame(7, DeadlineTestRule::$searches);
        $agent->rules = [];
        $agent->completeCronTick($deadline + 1, $deadline + 1);
        self::assertNull($agent->getCronDeadline());
        self::assertSame(7, DeadlineTestRule::$searches);
    }

    public function testBothZoneValueAndAccessorIdentityInvalidateTheSchedule(): void
    {
        $agent = new DeadlineTestAgent();
        $agent->rules = [new DeadlineTestRule('daily', '0 12 * * *')];
        $now = (float)strtotime('2026-10-08 10:14:00 UTC');
        $agent->completeCronTick($now, $now);
        self::assertSame((float)strtotime('2026-10-08 12:00:00 UTC'), $agent->getCronDeadline());
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
        $agent->completeCronTick($now + 1, $now + 1);
        self::assertSame(2, DeadlineTestRule::$searches);
        putenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name . '=Europe/Warsaw');
        $agent->completeCronTick($now + 2, $now + 2);
        self::assertSame((float)strtotime('2026-10-09 10:00:00 UTC'), $agent->getCronDeadline());
        self::assertSame(3, DeadlineTestRule::$searches);
        $agent->completeCronTick($now + 3, $now + 3);
        self::assertSame(3, DeadlineTestRule::$searches);
        $agent->completeCronTick($now - 1, $now - 1);
        self::assertSame(4, DeadlineTestRule::$searches);
    }

    public function testAnUnsearchableRuleKeepsAShortFallbackWithoutRepeatingTheSearch(): void
    {
        $agent = new DeadlineTestAgent();
        $agent->rules = [new DeadlineTestRule('never', '0 0 31 2 *')];
        $agent->completeCronTick(100.0, 100.01);
        self::assertSame(100.01 + 0.1, $agent->getCronDeadline());
        self::assertSame(1, DeadlineTestRule::$searches);
        $agent->completeCronTick(200.0, 200.01);
        self::assertSame(200.01 + 0.1, $agent->getCronDeadline());
        self::assertSame(1, DeadlineTestRule::$searches);
        $agent->rules[0]->expression = '* * * * *';
        $agent->completeCronTick(201.0, 201.01);
        self::assertSame(240.0, $agent->getCronDeadline());
        self::assertSame(2, DeadlineTestRule::$searches);
    }

    public function testAMissingCalendarContextUsesTheFallbackUntilOneArrives(): void
    {
        Hilos::$env = null;
        $agent = new DeadlineTestAgent();
        $agent->rules = [new DeadlineTestRule('minute', '* * * * *')];
        $agent->completeCronTick(100.0, 100.01);
        self::assertSame(100.01 + 0.1, $agent->getCronDeadline());
        self::assertSame(0, DeadlineTestRule::$searches);
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
        $agent->completeCronTick(101.0, 101.01);
        self::assertSame(120.0, $agent->getCronDeadline());
        self::assertSame(1, DeadlineTestRule::$searches);
    }
}

final class DeadlineTestAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_cron_deadline';

    protected const MAX_IDLE_SLEEP_SECONDS = 600;

    /** @var list<DeadlineTestRule> Current mutable schedule */
    public array $rules = [];
    public int $observations = 0;
    public int $runs = 0;

    /** Checks each rule through its ordinary execution predicate. */
    public function onTick(): void
    {
        foreach ($this->rules as $rule) {
            if ($rule->shouldRun()) {
                $this->runs++;
            }
        }
    }

    /** No resources are opened by this probe. */
    public function onStop(): void
    {
    }

    /** @return list<CronRule> One observation of the current schedule */
    protected function cronRules(): array
    {
        $this->observations++;
        return $this->rules;
    }
}

final class DeadlineTestRule extends CronRule
{
    public static int $searches = 0;
    public int $now = 0;

    /**
     * @param string $expression Current expression
     * @param int $after Strict lower bound
     * @return int|null Real calendar result, with the expensive entrance counted
     */
    public static function nextRunAfter(string $expression, int $after): ?int
    {
        self::$searches++;
        return parent::nextRunAfter($expression, $after);
    }

    /** @return int Controlled time of the rule's own check */
    protected function currentTimestamp(): int
    {
        return $this->now;
    }
}
