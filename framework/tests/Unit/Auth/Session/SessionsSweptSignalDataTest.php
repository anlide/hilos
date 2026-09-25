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
    public function testRoundtripPreservesSessionTokens(): void
    {
        $payload = new SessionsSweptSignalData([
            '0123456789abcdef0123456789abcdef',
            'fedcba9876543210fedcba9876543210',
        ]);

        $roundtrip = SessionsSweptSignalData::fromArray($payload->toArray());

        self::assertSame($payload->sessionTokens, $roundtrip->sessionTokens);
        self::assertSame($payload->toArray(), $roundtrip->toArray());
    }

    public function testPayloadWithoutSessionTokenListIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        SessionsSweptSignalData::fromArray([]);
    }
}
