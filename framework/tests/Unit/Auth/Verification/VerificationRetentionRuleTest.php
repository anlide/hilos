<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Verification;

use Hilos\Auth\Verification\VerificationRetentionRule;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/** A row must survive as long as it counts against the send cap. */
final class VerificationRetentionRuleTest extends TestCase
{
    private ?EnvAccessor $previousEnv = null;

    protected function setUp(): void
    {
        $this->previousEnv = Hilos::$env;
        Hilos::$env = null;
    }

    protected function tearDown(): void
    {
        Hilos::$env = $this->previousEnv;
    }

    public function testAcceptsWholeSecondsAtOrAboveTheWindow(): void
    {
        self::assertNull(VerificationRetentionRule::validate(3600));
        self::assertNull(VerificationRetentionRule::validate('7200'));
    }

    public function testRefusesShortOrNonIntegralValues(): void
    {
        $refusal = 'Retention must be at least the send window of 3600 seconds';
        foreach ([3599, '3599', -1, '7.5', 3600.0, '', null] as $value) {
            self::assertSame($refusal, VerificationRetentionRule::validate($value));
        }
    }
}
