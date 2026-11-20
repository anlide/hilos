<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Session;

use Hilos\Auth\Session\DTO\SessionsSweptSignalData;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\TestCase;

/**
 * Transport contract of the session-sweep notification.
 */
final class SessionsSweptSignalDataTest extends TestCase
{
    public function testRoundtripPreservesSessionTokensAndIds(): void
    {
        $payload = new SessionsSweptSignalData(
            [
                '0123456789abcdef0123456789abcdef',
                'fedcba9876543210fedcba9876543210',
            ],
            [41, 7],
        );

        $roundtrip = SessionsSweptSignalData::fromArray($payload->toArray());

        self::assertSame($payload->sessionTokens, $roundtrip->sessionTokens);
        self::assertSame($payload->sessionIds, $roundtrip->sessionIds);
        self::assertSame($payload->toArray(), $roundtrip->toArray());
    }

    public function testPayloadWithoutSessionTokenListIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        SessionsSweptSignalData::fromArray([SessionsSweptSignalData::sessionIds => [41]]);
    }

    public function testPayloadWithoutSessionIdListIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        SessionsSweptSignalData::fromArray([SessionsSweptSignalData::sessionTokens => ['0123456789abcdef0123456789abcdef']]);
    }

    public function testPayloadWithANonIntegerSessionIdIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        SessionsSweptSignalData::fromArray([
            SessionsSweptSignalData::sessionTokens => ['0123456789abcdef0123456789abcdef'],
            SessionsSweptSignalData::sessionIds => [41, '7'],
        ]);
    }
}
