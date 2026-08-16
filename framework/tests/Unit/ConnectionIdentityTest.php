<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Browser\Context\ConnectionIdentity;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the three states a connection's identity can be in (HIL-599).
 *
 * The whole point of the type is that "nobody" and "not yet" stop being the same value, so
 * what is pinned here is exactly that they are distinguishable - a guest is settled and
 * judged now, a pending answer is not and is waited for, and neither can be mistaken for
 * the other by reading `userId` alone.
 */
final class ConnectionIdentityTest extends TestCase
{
    public function testAResolvedUserCarriesItsIdAndIsNotPending(): void
    {
        $identity = ConnectionIdentity::resolved(27);

        $this->assertFalse($identity->pending);
        $this->assertSame(27, $identity->userId);
    }

    public function testAGuestIsASettledAnswerAndNotAWait(): void
    {
        $identity = ConnectionIdentity::resolved(null);

        $this->assertFalse($identity->pending);
        $this->assertNull($identity->userId);
    }

    public function testAPendingIdentityCarriesNoUserButIsNotAGuestEither(): void
    {
        $identity = ConnectionIdentity::pending();

        $this->assertTrue($identity->pending);
        $this->assertNull($identity->userId);
    }
}
