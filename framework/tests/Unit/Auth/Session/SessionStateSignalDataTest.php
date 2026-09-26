<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Session;

use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\TestCase;

/**
 * Pins the session row id and the "Access closed" card carried from the session holder to project agents.
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

    public function testRoundtripKeepsTheBlockedCard(): void
    {
        $frame = new SessionStateSignalData('token', 17, null, ['accept-key'])
            ->withAccountBlocked(['identifier' => 'maria@example.com']);

        $read = SessionStateSignalData::fromArray($frame->toArray());

        self::assertSame(['identifier' => 'maria@example.com'], $read->accountBlocked);
        self::assertEquals($frame, $read);
    }

    public function testBlockedCardWithoutAnAddressSurvivesTheRoundtrip(): void
    {
        $frame = new SessionStateSignalData('token', 17, null, ['accept-key'])->withAccountBlocked(['identifier' => null]);

        self::assertSame(['identifier' => null], SessionStateSignalData::fromArray($frame->toArray())->accountBlocked);
    }

    public function testFrameWithoutACardCarriesNull(): void
    {
        $frame = new SessionStateSignalData('token', 17, 41, ['accept-key']);

        self::assertArrayHasKey(SessionStateSignalData::accountBlocked, $frame->toArray());
        self::assertNull(SessionStateSignalData::fromArray($frame->toArray())->accountBlocked);
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
