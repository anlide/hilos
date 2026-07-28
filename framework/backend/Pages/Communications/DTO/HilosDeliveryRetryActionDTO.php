<?php

declare(strict_types=1);

namespace Hilos\Pages\Communications\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * DTO for the communications_delivery_retry action payload (HIL-201).
 *
 * Names the failed delivery row to re-queue by id. The handler validates the row is
 * in a failed state, resets it to pending with zero attempts, and re-queues the
 * channel's deliver signal; the real send path then runs again.
 */
final class HilosDeliveryRetryActionDTO extends ActionPayloadDTO
{
    /** Payload key: the delivery row id to retry. */
    public const string deliveryId = 'deliveryId';

    /**
     * @param int $deliveryId Delivery row id to retry
     */
    public function __construct(
        public readonly int $deliveryId,
    ) {
    }

    /**
     * @return string Action name constant
     */
    public function getAction(): string
    {
        return HilosSignalConstants::COMMUNICATIONS_DELIVERY_RETRY;
    }

    /**
     * @param array<string, mixed> $data Raw payload (may contain a FIELD_DATA wrapper)
     * @return static Instance
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            $inner = [];
        }

        return new static(
            deliveryId: is_numeric($inner[self::deliveryId] ?? null) ? (int) $inner[self::deliveryId] : 0,
        );
    }

    /**
     * @return array<string, mixed> Data with the delivery id
     */
    public function toArray(): array
    {
        return [
            self::deliveryId => $this->deliveryId,
        ];
    }
}
