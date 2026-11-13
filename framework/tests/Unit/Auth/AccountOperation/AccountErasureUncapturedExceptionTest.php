<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\AccountOperation;

use Hilos\Auth\AccountOperation\Exception\AccountErasureUncapturedException;
use Hilos\Core\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

/** The two waits of the person's erasure part, told apart by the notifications they name (HIL-1941). */
final class AccountErasureUncapturedExceptionTest extends TestCase
{
    /** The wait for the addresses names no notification. */
    public function testTheAddressesWaitNamesNoNotification(): void
    {
        $wait = AccountErasureUncapturedException::addresses();

        self::assertInstanceOf(ValidationException::class, $wait);
        self::assertSame('The person\'s erasure part waits for its addresses to be captured.', $wait->getMessage());
        self::assertSame([], $wait->notificationIds);
    }

    /** The wait for the deliveries names its notifications in ascending order, in the words and in the field alike. */
    public function testTheDeliveriesWaitNamesItsNotificationsInAscendingOrder(): void
    {
        $wait = AccountErasureUncapturedException::deliveries([7, 3]);

        self::assertInstanceOf(ValidationException::class, $wait);
        self::assertSame('The person\'s erasure part waits for the deliveries of notifications #3, #7 to be captured.', $wait->getMessage());
        self::assertSame([3, 7], $wait->notificationIds);
    }
}
