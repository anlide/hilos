<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Core\Router\DTO;

use Demo\EcommerceShop\Database\Object\Item\ProductWarehouseStock;
use Demo\EcommerceShop\Pages\DTO\ProductWarehousePlaceActionDTO;
use Hilos\BaseDTO;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Exception\InvalidFormatException;

/** Asks the stock library to place a unique piece and reply to the Overview page. */
final class ProductWarehousePlaceSignalData extends BaseDTO implements HandoverAskInterface
{
    private const string replySignal = 'replySignal';
    private const string successMessage = 'successMessage';

    /**
     * @param int $productId Id of the unique piece
     * @param int $warehouseId Id of the destination warehouse
     * @param ?int $expectedWarehouseId Id of the current warehouse, or null for an unplaced piece
     * @param string $replySignal Agent signal name for the outcome
     * @param string $acceptKey Connection waiting for the outcome
     * @param ?string $requestId Tracked request id, or null for an untracked action
     * @param string $action Browser action the outcome answers
     * @param ?string $successMessage Success text, or null for placement on Overview
     * @throws InvalidFormatException When a supplied id is not positive
     */
    public function __construct(
        public readonly int $productId,
        public readonly int $warehouseId,
        public readonly ?int $expectedWarehouseId,
        public readonly string $replySignal,
        public readonly string $acceptKey,
        public readonly ?string $requestId,
        public readonly string $action,
        public readonly ?string $successMessage,
    ) {
        if ($productId <= 0 || $warehouseId <= 0 || ($expectedWarehouseId !== null && $expectedWarehouseId <= 0)) {
            throw new InvalidFormatException('Product and warehouse IDs must be positive integers.');
        }
    }

    /**
     * @param array<string, mixed> $data Placement request as transported between agents
     * @return static Placement and reply address
     * @throws InvalidFormatException When the request lacks a required field or carries an invalid value
     */
    public static function fromArray(array $data): static
    {
        if (!array_key_exists(ProductWarehousePlaceActionDTO::expectedWarehouseId, $data)) {
            throw new InvalidFormatException(
                'Payload carries no integer or null under key ' . ProductWarehousePlaceActionDTO::expectedWarehouseId,
            );
        }

        return new static(
            productId: self::requireInt($data, ProductWarehouseStock::productId),
            warehouseId: self::requireInt($data, ProductWarehouseStock::warehouseId),
            expectedWarehouseId: self::optionalInt($data, ProductWarehousePlaceActionDTO::expectedWarehouseId),
            replySignal: self::requireString($data, self::replySignal),
            acceptKey: self::requireString($data, SignalPayloadConstants::FIELD_ACCEPT_KEY),
            requestId: self::optionalString($data, SignalPayloadConstants::FIELD_REQUEST_ID),
            action: self::requireString($data, SignalPayloadConstants::FIELD_ACTION),
            successMessage: self::optionalString($data, self::successMessage),
        );
    }

    /** @return array<string, mixed> Placement and reply address for transport */
    public function toArray(): array
    {
        return [
            ProductWarehouseStock::productId => $this->productId,
            ProductWarehouseStock::warehouseId => $this->warehouseId,
            ProductWarehousePlaceActionDTO::expectedWarehouseId => $this->expectedWarehouseId,
            self::replySignal => $this->replySignal,
            SignalPayloadConstants::FIELD_ACCEPT_KEY => $this->acceptKey,
            SignalPayloadConstants::FIELD_REQUEST_ID => $this->requestId,
            SignalPayloadConstants::FIELD_ACTION => $this->action,
            self::successMessage => $this->successMessage,
        ];
    }
}
