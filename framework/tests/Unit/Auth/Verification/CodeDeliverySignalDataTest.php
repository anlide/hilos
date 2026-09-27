<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Verification;

use Hilos\Auth\Verification\CodeDeliveryAvailability;
use Hilos\Auth\Verification\DTO\CodeDeliverySignalData;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\TestCase;

/** Delivery frames carry the same complete answer as the handshake (HIL-1102). */
final class CodeDeliverySignalDataTest extends TestCase
{
    public function testRoundTripKeepsBothKinds(): void
    {
        $payload = ['codeDelivery' => ['email' => true, 'phone' => false]];

        $this->assertSame($payload, CodeDeliverySignalData::fromArray($payload)->toArray());
    }

    public function testMissingPhoneIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);
        CodeDeliverySignalData::fromArray(['codeDelivery' => ['email' => true]]);
    }

    public function testMissingNodeIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);
        CodeDeliverySignalData::fromArray([]);
    }

    public function testNonBooleanFlagIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);
        CodeDeliverySignalData::fromArray(['codeDelivery' => ['email' => true, 'phone' => 'false']]);
    }

    public function testCurrentReadsTheHandshakeAvailability(): void
    {
        $this->assertSame(new CodeDeliveryAvailability()->toArray(), CodeDeliverySignalData::current()->codeDelivery);
    }
}
