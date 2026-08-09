<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Router\SignalName;
use PHPUnit\Framework\TestCase;

/**
 * Tests that a signal name always names something (HIL-547).
 *
 * "No name" used to be spelled as the empty string, which made a broken payload
 * indistinguishable from a transport signal. A transport signal now names its own
 * type, and the empty name is not a state this type can hold.
 */
final class SignalNameTest extends TestCase
{
    public function testKeepsTheNameItWasGiven(): void
    {
        $this->assertSame(
            SignalTypeConstants::FRAME_BINARY,
            new SignalName(SignalTypeConstants::FRAME_BINARY)->getName(),
        );
    }

    public function testRejectsAnEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SignalName('');
    }
}
