<?php

declare(strict_types=1);

namespace Hilos\Core\Router\DTO;

use Hilos\Core\Router\EmitFanoutDelivery;
use Hilos\Core\Router\SignalDataInterface;

/**
 * One outbound WebSocket message produced from an emit signal.
 */
final readonly class EmitFanoutItem
{
    /**
     * @param EmitFanoutDelivery $delivery Delivery strategy
     * @param string $wireSignalName Client message type (e.g. table_mutation)
     * @param SignalDataInterface $innerPayload Serializable inner `data` payload
     * @param ?string $targetAcceptKey Required when delivery is Single
     * @param ?string $excludeAcceptKey Optional exclude for AllExcept (initiator skip)
     */
    public function __construct(
        public EmitFanoutDelivery $delivery,
        public string $wireSignalName,
        public SignalDataInterface $innerPayload,
        public ?string $targetAcceptKey = null,
        public ?string $excludeAcceptKey = null,
    ) {
    }
}
