<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Log;

use Hilos\Log\LogRotationTriggerPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure log rotation trigger predicate (HIL-379).
 *
 * Locks the two-criterion semantics without any I/O: either the age or the size threshold
 * firing rotates, a threshold of 0 disables that criterion, and both 0 makes the policy inert
 * (preserving the daemon's start-only rotation). {@see LogRotator} covers the file moves.
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
}
