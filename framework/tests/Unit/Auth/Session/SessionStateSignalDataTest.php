<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Session;

use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\TestCase;

/**
 * Pins the session row id carried from the session holder to project agents.
 */
final class SessionStateSignalDataTest extends TestCase
{
    public function testRoundtripKeepsTheSessionId(): void
    {
        $frame = new SessionStateSignalData('token', 17, 41, ['accept-key']);

        self::assertEquals($frame, SessionStateSignalData::fromArray($frame->toArray()));
    }

    public function testNullSessionIdIsStillAnExplicitField(): void
    {
        $frame = new SessionStateSignalData('token', null, null, ['accept-key']);

        self::assertArrayHasKey(SessionStateSignalData::sessionId, $frame->toArray());
        self::assertNull(SessionStateSignalData::fromArray($frame->toArray())->sessionId);
    }

    public function testMissingSessionIdIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        SessionStateSignalData::fromArray([
            'sessionToken' => 'token',
            'userId' => null,
            'acceptKeys' => ['accept-key'],
        ]);
    }
}
