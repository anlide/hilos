<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Log;

use Hilos\Core\Daemon\Cron\CronRule;
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
 */
final class LogRotationTriggerPolicyTest extends TestCase
{
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
}
