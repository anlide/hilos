<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Auth\Throttle\ThrottlePolicy;
use Hilos\Auth\Throttle\ThrottleScope;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Feature\Definition\AuthThrottleFeature;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\AuthAttempt as StateAuthAttempt;
use Hilos\Runtime\View\Collection\AuthAttempts;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The ladder a repeat offender walks up, and the quiet that walks it back down (HIL-420).
 *
 * Two rules decide how heavily the layer leans on a client, and neither is visible from a
 * single attempt: a key that breaches again is punished for longer than last time, and a key
 * that stops for long enough is forgiven the punishment it earned. Both are pinned here
 * against chosen numbers, because in production they are a day and an hour apart and nothing
 * that runs in seconds would ever see them.
 */
final class AuthThrottleLadderTest extends TestCase
{
    private const string IDENTITY = '198.51.100.4';

    private const string ACTION = 'sign_in';

    private const string AGENT_ID = 'unit-throttle-ladder';

    /** @var list<int> Ladder these cases judge against, short enough to reach the end of */
    private const array STEPS = [30, 120, 600];

    /** @var list<string> Environment names this case sets and has to unset again */
    private const array KNOBS = [
        'HILOS_AUTH_THROTTLE_WINDOW',
        'HILOS_AUTH_THROTTLE_MAX_SESSION',
        'HILOS_AUTH_THROTTLE_MAX_IP',
        'HILOS_AUTH_THROTTLE_STEPS',
    ];

    private ?EnvAccessor $previousEnv = null;

    private ?RtContext $previousRt = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousEnv = Hilos::$env;
        $this->previousRt = Hilos::$rt;
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
    }

    protected function tearDown(): void
    {
        RtTruthSourceRegistry::unregister(StateAuthAttempt::RT_COLLECTION, self::AGENT_ID);
        ExecutionContext::clear();
        foreach (self::KNOBS as $knob) {
            putenv($knob);
        }

        Hilos::$rt = $this->previousRt;
        Hilos::$env = $this->previousEnv;

        parent::tearDown();
    }

    public function testEachBreachCostsTheKeyLongerThanTheLastOne(): void
    {
        $policy = $this->policy();

        $level = 0;
        $blocks = [];
        foreach (self::STEPS as $ignored) {
            $level = $policy->escalate($level);
            $blocks[] = $policy->blockSecondsFor($level);
        }

        $this->assertSame(self::STEPS, $blocks);
    }

    public function testTheLadderDoesNotRunOffItsOwnEnd(): void
    {
        $policy = $this->policy();
        $last = count(self::STEPS);

        $this->assertSame($last, $policy->escalate($last));
        $this->assertSame($last, $policy->escalate($last + 5));
        $this->assertSame(self::STEPS[$last - 1], $policy->blockSecondsFor($last + 5));
    }

    public function testAKeyThatNeverBreachedIsNotBlockedAtAll(): void
    {
        $this->assertSame(0, $this->policy()->blockSecondsFor(0));
    }

    public function testAnAddressIsAllowedMoreAttemptsThanOneBrowserOnIt(): void
    {
        $policy = $this->policy();

        $this->assertSame(2, $policy->maxFor(ThrottleScope::SESSION));
        $this->assertSame(5, $policy->maxFor(ThrottleScope::IP));
    }

    public function testNumbersThatWouldDisableOrLockOutAreClampedIntoUse(): void
    {
        putenv(EnvConstants::HILOS_AUTH_THROTTLE_WINDOW->name . '=0');
        putenv(EnvConstants::HILOS_AUTH_THROTTLE_MAX_SESSION->name . '=0');
        putenv(EnvConstants::HILOS_AUTH_THROTTLE_MAX_IP->name . '=-3');
        putenv(EnvConstants::HILOS_AUTH_THROTTLE_STEPS->name . '=nonsense,0,-1');

        $policy = ThrottlePolicy::fromEnv();

        $this->assertSame(1.0, $policy->windowSeconds);
        $this->assertSame(1, $policy->maxFor(ThrottleScope::SESSION));
        $this->assertSame(1, $policy->maxFor(ThrottleScope::IP));
        $this->assertSame([30, 120, 600, 3600], $policy->steps);
    }

    public function testALevelIsForgivenOnlyAfterItHasCooledForADay(): void
    {
        $policy = $this->policy();
        $attempts = $this->mountedCounters();
        $now = microtime(true);

        $attempts->actions->open(ThrottleScope::IP, self::IDENTITY, self::ACTION);
        $counter = $attempts[StateAuthAttempt::keyFor(ThrottleScope::IP, self::IDENTITY, self::ACTION)];
        $this->assertNotNull($counter);
        $counter->actions->countAttempt($now, $policy->windowSeconds);
        $counter->actions->block(1, null, $now);

        // The window is long over, but the ladder is not: an hour of quiet buys nothing.
        $anHourOn = $now + 3600;
        $this->assertSame(0, $attempts->actions->sweep($anHourOn, $policy->windowSeconds, $policy->cooldownSeconds()));

        $aDayOn = $now + $policy->cooldownSeconds();
        $this->assertSame(1, $attempts->actions->sweep($aDayOn, $policy->windowSeconds, $policy->cooldownSeconds()));
        $this->assertNull($attempts[StateAuthAttempt::keyFor(ThrottleScope::IP, self::IDENTITY, self::ACTION)]);
    }

    public function testAServedBlockIsNotSweptWhileItIsStillInForce(): void
    {
        $policy = $this->policy();
        $attempts = $this->mountedCounters();
        $now = microtime(true);

        $attempts->actions->open(ThrottleScope::IP, self::IDENTITY, self::ACTION);
        $counter = $attempts[StateAuthAttempt::keyFor(ThrottleScope::IP, self::IDENTITY, self::ACTION)];
        $this->assertNotNull($counter);
        $sweptAt = $now + $policy->cooldownSeconds();
        $counter->actions->block(1, $sweptAt + self::STEPS[0], $now);

        // Quiet for longer than the cooldown, but the block itself has not lifted yet.
        $this->assertSame(0, $attempts->actions->sweep($sweptAt, $policy->windowSeconds, $policy->cooldownSeconds()));
        $this->assertNotNull($attempts[StateAuthAttempt::keyFor(ThrottleScope::IP, self::IDENTITY, self::ACTION)]);
    }

    /**
     * The policy these cases judge against.
     *
     * @return ThrottlePolicy Policy built from the numbers set here
     */
    private function policy(): ThrottlePolicy
    {
        putenv(EnvConstants::HILOS_AUTH_THROTTLE_WINDOW->name . '=60');
        putenv(EnvConstants::HILOS_AUTH_THROTTLE_MAX_SESSION->name . '=2');
        putenv(EnvConstants::HILOS_AUTH_THROTTLE_MAX_IP->name . '=5');
        putenv(EnvConstants::HILOS_AUTH_THROTTLE_STEPS->name . '=' . implode(',', self::STEPS));

        return ThrottlePolicy::fromEnv();
    }

    /**
     * Mounts the counters and claims them, the way the throttle agent does on start.
     *
     * @return AuthAttempts Counters this case writes through
     */
    private function mountedCounters(): AuthAttempts
    {
        $rt = new ThrottleLadderTestRtContext();
        $rt->mountFeatureRuntime([new AuthThrottleFeature()]);
        Hilos::$rt = $rt;

        RtTruthSourceRegistry::register(StateAuthAttempt::RT_COLLECTION, true, self::AGENT_ID);
        ExecutionContext::setCurrentAgentId(self::AGENT_ID);

        $attempts = $rt->hilosAuthAttempts;
        $this->assertInstanceOf(AuthAttempts::class, $attempts);

        return $attempts;
    }
}

/**
 * Runtime context fixture that mounts only what the declared feature asks for.
 */
final class ThrottleLadderTestRtContext extends RtContext
{
    /**
     * Declares no project runtime of its own; the feature mount is the whole fixture.
     */
    public function configure(): void
    {
    }
}
