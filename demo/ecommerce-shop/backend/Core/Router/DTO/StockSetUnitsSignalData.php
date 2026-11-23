<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Core\Router\DTO;

use Demo\EcommerceShop\Database\Object\Item\ProductWarehouseStock;
use Hilos\BaseDTO;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Exception\InvalidFormatException;

/** Stock page → stock library: judge and write an edit, then answer the submitting connection. */
final class StockSetUnitsSignalData extends BaseDTO implements HandoverAskInterface
{
    public const string expectedUnits = 'expectedUnits';

    /**
     * @param int $productId Stock product being edited
     * @param int $warehouseId Warehouse whose stock is being edited
     * @param string $units Raw units to judge at the writer
     * @param int $expectedUnits Units the editor opened with
     * @param string $replySignal Agent-signal name the answer travels under
     * @param string $acceptKey Initiating connection accept key to answer
     * @param ?string $requestId Client-minted request id, or null for an untracked submit
     * @param string $action Browser action name the ack is addressed to
     * @param ?string $successMessage Sentence to speak on success, or null for a silent success
     */
    public function __construct(
        public readonly int $productId,
        public readonly int $warehouseId,
        public readonly string $units,
        public readonly int $expectedUnits,
        public readonly string $replySignal,
        public readonly string $acceptKey,
        public readonly ?string $requestId,
        public readonly string $action,
        public readonly ?string $successMessage,
    ) {
    }

    /** @return array<string, mixed> Stock edit and handover address */
    public function toArray(): array
    {
        return [
            ProductWarehouseStock::productId => $this->productId,
            ProductWarehouseStock::warehouseId => $this->warehouseId,
            ProductWarehouseStock::units => $this->units,
            self::expectedUnits => $this->expectedUnits,
            'replySignal' => $this->replySignal,
            'acceptKey' => $this->acceptKey,
            'requestId' => $this->requestId,
            'action' => $this->action,
            'successMessage' => $this->successMessage,
        ];
    }

    /**
     * @param array<string, mixed> $data Stock edit and handover address
     * @return static Stock ask with its original edit and caller
     * @throws InvalidFormatException When a stock edit or handover field is invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(
            productId: self::requireInt($data, ProductWarehouseStock::productId),
            warehouseId: self::requireInt($data, ProductWarehouseStock::warehouseId),
            units: self::requireString($data, ProductWarehouseStock::units),
            expectedUnits: self::requireInt($data, self::expectedUnits),
            replySignal: self::requireString($data, 'replySignal'),
            acceptKey: self::requireString($data, 'acceptKey'),
            requestId: self::optionalString($data, 'requestId'),
            action: self::requireString($data, 'action'),
            successMessage: self::optionalString($data, 'successMessage'),
        );
    }
}
