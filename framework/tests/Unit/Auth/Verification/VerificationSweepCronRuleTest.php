<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Verification;

use Hilos\Auth\Verification\VerificationSweepCronRule;
use PHPUnit\Framework\TestCase;

/** Sweep scheduling is mandatory and accepts only runnable expressions. */
final class VerificationSweepCronRuleTest extends TestCase
{
    public function testAcceptsRunnableFiveFieldSchedule(): void
    {
        self::assertNull(VerificationSweepCronRule::validate('*/10 * * * *'));
    }

    public function testRefusesEmptyMalformedAndNonStringSchedules(): void
    {
        foreach (['', '  ', '0 3 * * abc', '0 3 * *', 123, null] as $value) {
            self::assertSame('Value must be a five-field cron expression', VerificationSweepCronRule::validate($value));
        }
    }
}
