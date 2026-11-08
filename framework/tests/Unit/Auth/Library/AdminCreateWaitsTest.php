<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Library;

use Hilos\Auth\Library\AdminCreateWaits;
use Hilos\Constants\CommandChannelWindows;
use Hilos\Users\DTO\UserAdminCreateSignalData;
use PHPUnit\Framework\TestCase;

final class AdminCreateWaitsTest extends TestCase
{
    public function testTheDeadlineExpiresAtItsBoundaryAndOnlyOnce(): void
    {
        $waits = new AdminCreateWaits();
        $ask = new UserAdminCreateSignalData('corr-1', 'token-1', false);
        self::assertFalse($waits->isWaiting('token-1'));
        $waits->park($ask, 100.0);
        self::assertTrue($waits->isWaiting('token-1'));
        $deadline = 100.0 + CommandChannelWindows::AGENT_WAIT_SECONDS;
        self::assertSame([], $waits->expired($deadline - 0.001));
        self::assertSame([$ask], $waits->expired($deadline));
        self::assertFalse($waits->isWaiting('token-1'));
        self::assertSame([], $waits->expired($deadline + 1.0));
        self::assertNull($waits->settle($ask));
    }

    public function testAnUnrelatedAnswerCannotConsumeTheParkedCommand(): void
    {
        $waits = new AdminCreateWaits();
        $ask = new UserAdminCreateSignalData('corr-1', 'token-1', true);
        $waits->park($ask, 100.0);
        self::assertNull($waits->settle(new UserAdminCreateSignalData('corr-2', 'token-1', true)));
        self::assertNull($waits->settle(new UserAdminCreateSignalData('corr-1', 'token-2', true)));
        self::assertTrue($waits->isWaiting('token-1'));
        self::assertSame($ask, $waits->settle($ask));
        self::assertFalse($waits->isWaiting('token-1'));
        self::assertSame([], $waits->expired(1000.0));
    }

    public function testALateAnswerDoesNotConsumeANewerCommandForTheSameSession(): void
    {
        $waits = new AdminCreateWaits();
        $old = new UserAdminCreateSignalData('old', 'token-1', false);
        $new = new UserAdminCreateSignalData('new', 'token-1', false);
        $waits->park($old, 100.0);
        self::assertSame([$old], $waits->expired(100.0 + CommandChannelWindows::AGENT_WAIT_SECONDS));
        $waits->park($new, 200.0);
        self::assertNull($waits->settle($old));
        self::assertSame($new, $waits->settle($new));
    }

    public function testEachSessionHasItsOwnDeadline(): void
    {
        $waits = new AdminCreateWaits();
        $first = new UserAdminCreateSignalData('first', 'token-1', false);
        $second = new UserAdminCreateSignalData('second', 'token-2', false);
        $waits->park($first, 100.0);
        $waits->park($second, 200.0);
        self::assertSame([$first], $waits->expired(100.0 + CommandChannelWindows::AGENT_WAIT_SECONDS));
        self::assertTrue($waits->isWaiting('token-2'));
        self::assertSame([$second], $waits->expired(200.0 + CommandChannelWindows::AGENT_WAIT_SECONDS));
    }
}
