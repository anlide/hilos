<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Session;

use Hilos\Auth\Session\DTO\AccountBlockChangedSignalData;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\TestCase;

/**
 * Pins the frame a block writer sends the sessions library: one person, named by a real account id (HIL-289).
 */
final class AccountBlockChangedSignalDataTest extends TestCase
{
    public function testRoundtripKeepsThePerson(): void
    {
        $frame = new AccountBlockChangedSignalData(42);

        self::assertSame(['userId' => 42], $frame->toArray());
        self::assertSame(42, AccountBlockChangedSignalData::fromArray($frame->toArray())->userId);
    }

    public function testZeroNamesNoAccountAndIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        AccountBlockChangedSignalData::fromArray(['userId' => 0]);
    }

    public function testNegativeIdIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        new AccountBlockChangedSignalData(-3);
    }

    public function testMissingPersonIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        AccountBlockChangedSignalData::fromArray([]);
    }
}
