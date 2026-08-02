<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\Communications\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Pages\Communications\DTO\HilosDeliveryRetryActionDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the delivery-retry action payload DTO (HIL-201).
 *
 * Locks the payload contract: the delivery id is read from either a FIELD_DATA
 * wrapper or a flat payload, non-numeric ids coerce to 0, the action name is the
 * retry constant, and round-tripping preserves the id.
 */
final class HilosDeliveryRetryActionDTOTest extends TestCase
{
    public function testReadsDeliveryIdFromWrappedPayload(): void
    {
        $dto = HilosDeliveryRetryActionDTO::fromArray([
            SignalPayloadConstants::FIELD_DATA => [HilosDeliveryRetryActionDTO::deliveryId => '42'],
        ]);

        self::assertSame(42, $dto->deliveryId);
    }

    public function testReadsDeliveryIdFromFlatPayload(): void
    {
        $dto = HilosDeliveryRetryActionDTO::fromArray([HilosDeliveryRetryActionDTO::deliveryId => 7]);

        self::assertSame(7, $dto->deliveryId);
    }

    public function testNonNumericDeliveryIdCoercesToZero(): void
    {
        $dto = HilosDeliveryRetryActionDTO::fromArray([HilosDeliveryRetryActionDTO::deliveryId => 'nope']);

        self::assertSame(0, $dto->deliveryId);
    }

    public function testActionNameIsRetryConstant(): void
    {
        self::assertSame(
            HilosSignalConstants::COMMUNICATIONS_DELIVERY_RETRY,
            new HilosDeliveryRetryActionDTO(1)->getAction(),
        );
    }

    public function testRoundTrip(): void
    {
        self::assertSame(
            [HilosDeliveryRetryActionDTO::deliveryId => 99],
            new HilosDeliveryRetryActionDTO(99)->toArray(),
        );
    }
}
