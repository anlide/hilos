<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Log;

use Hilos\Constants\EnvConstants;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogConstants;
use Hilos\Hilos;
use Hilos\Log\LogRotationTriggerPolicy;
use Hilos\Log\LogRotator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure log rotation trigger predicate (HIL-379, HIL-380).
 *
 * Locks the axis semantics without any I/O: the age, size, or cron-schedule axis firing rotates,
 * a numeric threshold of 0 disables that axis, an empty or malformed cron expression disables the
 * schedule, and all axes off makes the policy inert (preserving the daemon's start-only rotation).
 * {@see LogRotator} covers the file moves; {@see CronRule} covers cron matching.
 *
 * The environment tests (HIL-682) lock the same promise one level up: a value the environment
 * cannot answer disables its own axis and nothing else. The two ways a read fails are staged the
 * two ways they happen — a malformed value in the process environment, and a project catalog that
 * never declared the key at all.
 */
final class LogRotationTriggerPolicyTest extends TestCase
{
    /** @var ?EnvAccessor Accessor to put back on the facade after a test replaced it */
    private ?EnvAccessor $previousEnv = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousEnv = Hilos::$env;
    }

    protected function tearDown(): void
    {
        Hilos::$env = $this->previousEnv;
        foreach ([
            EnvConstants::LOG_ROTATION_MAX_AGE_SECONDS,
            EnvConstants::LOG_ROTATION_MAX_LIVE_SIZE_BYTES,
            EnvConstants::LOG_ROTATION_CRON,
        ] as $key) {
            putenv($key->name);
        }

        parent::tearDown();
    }

    public function testBothThresholdsZeroIsInactiveAndNeverRotates(): void
    {
        $policy = new LogRotationTriggerPolicy(0, 0);

        $this->assertFalse($policy->isActive());
        $this->assertFalse($policy->shouldRotate(0.0, 0));
        $this->assertFalse($policy->shouldRotate(1_000_000.0, PHP_INT_MAX));
    }

    public function testAgeCriterionFiresAtOrBeyondThreshold(): void
    {
        $policy = new LogRotationTriggerPolicy(60, 0);

        $this->assertTrue($policy->isActive());
        $this->assertFalse($policy->shouldRotate(59.9, PHP_INT_MAX));
        $this->assertTrue($policy->shouldRotate(60.0, 0));
        $this->assertTrue($policy->shouldRotate(120.0, 0));
    }

    public function testSizeCriterionFiresAtOrBeyondThreshold(): void
    {
        $policy = new LogRotationTriggerPolicy(0, 1024);

        $this->assertTrue($policy->isActive());
        $this->assertFalse($policy->shouldRotate(1_000_000.0, 1023));
        $this->assertTrue($policy->shouldRotate(0.0, 1024));
        $this->assertTrue($policy->shouldRotate(0.0, 4096));
    }

    public function testEitherCriterionFiresWhenBothEnabled(): void
    {
        $policy = new LogRotationTriggerPolicy(60, 1024);

        $this->assertFalse($policy->shouldRotate(10.0, 100));
        $this->assertTrue($policy->shouldRotate(60.0, 100));
        $this->assertTrue($policy->shouldRotate(10.0, 2048));
    }

    public function testEmptyExpressionYieldsNoRuleAndInactiveWhenNumericAxesOff(): void
    {
        $policy = new LogRotationTriggerPolicy(0, 0, '');

        $this->assertNull($policy->createCronRule());
        $this->assertFalse($policy->isActive());
    }

    public function testScheduleAloneMakesPolicyActiveAndBuildsRule(): void
    {
        $policy = new LogRotationTriggerPolicy(0, 0, '0 3 * * *');

        $this->assertTrue($policy->isActive());
        $rule = $policy->createCronRule();
        $this->assertInstanceOf(CronRule::class, $rule);
        $this->assertSame('0 3 * * *', $rule->expression);
        // The schedule axis is not part of the numeric predicate: both numeric axes off stays false.
        $this->assertFalse($policy->shouldRotate(PHP_INT_MAX, PHP_INT_MAX));
    }

    public function testMalformedExpressionYieldsNoRuleAndInactiveWhenNumericAxesOff(): void
    {
        $policy = new LogRotationTriggerPolicy(0, 0, 'not a cron');

        $this->assertNull($policy->createCronRule());
        $this->assertFalse($policy->isActive());
    }

    public function testMalformedScheduleDisablesOnlyItsAxis(): void
    {
        $policy = new LogRotationTriggerPolicy(60, 0, '0 3 * *');

        $this->assertNull($policy->createCronRule());
        $this->assertTrue($policy->isActive());
        $this->assertTrue($policy->shouldRotate(60.0, 0));
    }

    public function testGarbageInsideAFieldDisablesTheScheduleToo(): void
    {
        // Five fields, so counting them accepted this; the rule that would run it does not.
        $policy = new LogRotationTriggerPolicy(0, 0, '0 3 * * abc');

        $this->assertNull($policy->createCronRule());
        $this->assertFalse($policy->isActive());
    }

    public function testPolicyBuiltFromValuesCarriesThemAsGiven(): void
    {
        // The shape the settings resolver builds: values in, no environment read.
        $policy = new LogRotationTriggerPolicy(3600, 1_048_576, '*/5 * * * *');

        $this->assertSame(3600, $policy->maxAgeSeconds);
        $this->assertSame(1_048_576, $policy->maxLiveSizeBytes);
        $this->assertSame('*/5 * * * *', $policy->cronExpression);
        $this->assertInstanceOf(CronRule::class, $policy->createCronRule());
    }

    public function testUnreadableAgeValueDisablesOnlyTheAgeAxis(): void
    {
        $this->putEnvironment(maxAge: '1h', maxSize: '1024', cron: '0 3 * * *');

        $policy = LogRotationTriggerPolicy::fromEnv();

        $this->assertSame(0, $policy->maxAgeSeconds);
        $this->assertSame(1024, $policy->maxLiveSizeBytes);
        $this->assertSame('0 3 * * *', $policy->cronExpression);
        $this->assertTrue($policy->isActive());
        $this->assertSame([EnvConstants::LOG_ROTATION_MAX_AGE_SECONDS->name], array_keys($policy->unreadable));
        $this->assertStringContainsString(
            EnvConstants::LOG_ROTATION_MAX_AGE_SECONDS->name,
            $policy->unreadable[EnvConstants::LOG_ROTATION_MAX_AGE_SECONDS->name],
        );
    }

    public function testUncatalogedCronKeyDisablesOnlyTheScheduleAxis(): void
    {
        $this->putEnvironment(maxAge: '60', maxSize: '1024', cron: '0 3 * * *');
        // A project whose env catalog never declared the cron key: reading it throws, the rest answer.
        Hilos::$env = $this->envWithoutCronKey();

        $policy = LogRotationTriggerPolicy::fromEnv();

        $this->assertSame(60, $policy->maxAgeSeconds);
        $this->assertSame(1024, $policy->maxLiveSizeBytes);
        $this->assertNull($policy->cronExpression);
        $this->assertNull($policy->createCronRule());
        $this->assertTrue($policy->isActive());
        $this->assertSame([EnvConstants::LOG_ROTATION_CRON->name], array_keys($policy->unreadable));
    }

    public function testEnvironmentThatAnswersLeavesNothingUnreadable(): void
    {
        $this->putEnvironment(maxAge: '60', maxSize: '1024', cron: '0 3 * * *');

        $policy = LogRotationTriggerPolicy::fromEnv();

        $this->assertSame([], $policy->unreadable);
        $this->assertSame(60, $policy->maxAgeSeconds);
        $this->assertSame(1024, $policy->maxLiveSizeBytes);
        $this->assertSame('0 3 * * *', $policy->cronExpression);
    }

    /**
     * Writes all three rotation keys into the process environment, which the accessor reads first.
     *
     * @param string $maxAge Raw value for the age axis
     * @param string $maxSize Raw value for the size axis
     * @param string $cron Raw value for the schedule axis
     */
    private function putEnvironment(string $maxAge, string $maxSize, string $cron): void
    {
        putenv(EnvConstants::LOG_ROTATION_MAX_AGE_SECONDS->name . '=' . $maxAge);
        putenv(EnvConstants::LOG_ROTATION_MAX_LIVE_SIZE_BYTES->name . '=' . $maxSize);
        putenv(EnvConstants::LOG_ROTATION_CRON->name . '=' . $cron);
    }

    /**
     * Builds an accessor over a catalog carrying the two numeric keys and no cron key.
     *
     * @return EnvAccessor Accessor that answers for the numeric axes and throws for the schedule
     */
    private function envWithoutCronKey(): EnvAccessor
    {
        LogRotationTriggerPolicyTestCatalog::$catalog = [
            EnvConstants::LOG_ROTATION_MAX_AGE_SECONDS->name => [
                EnvCatalogConstants::CATALOG_ENTRY_TYPE => EnvCatalogConstants::TYPE_INTEGER,
                EnvCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => 0,
            ],
            EnvConstants::LOG_ROTATION_MAX_LIVE_SIZE_BYTES->name => [
                EnvCatalogConstants::CATALOG_ENTRY_TYPE => EnvCatalogConstants::TYPE_INTEGER,
                EnvCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => 0,
            ],
        ];

        return new EnvAccessor(LogRotationTriggerPolicyTestCatalog::class);
    }
}

/**
 * Catalog provider whose entries the rotation policy test scripts.
 */
final class LogRotationTriggerPolicyTestCatalog implements CatalogProviderInterface
{
    /** @var array<string, array<string, mixed>> Env catalog the accessor under test reads */
    public static array $catalog = [];

    /**
     * Returns the catalog the test scripted.
     *
     * @return array<string, array<string, mixed>> Catalog keyed by env variable name
     */
    public static function getCatalog(): array
    {
        return self::$catalog;
    }
}
