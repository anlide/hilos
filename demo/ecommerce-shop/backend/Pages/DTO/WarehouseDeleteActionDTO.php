<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages\DTO;

use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Database\Object\Item\Warehouse as ObjectWarehouse;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** Request to delete one warehouse by its persisted ID. */
final class WarehouseDeleteActionDTO extends ActionPayloadDTO
{
    public const array SECRET_FIELDS = [];

    /**
     * @param int $id Warehouse primary ID
     * @throws InvalidFormatException When the ID is not positive
     */
    public function __construct(public readonly int $id)
    {
        if ($id <= 0) {
            throw new InvalidFormatException('The warehouse ID must be a positive integer.');
        }
    }

    /** @return string Warehouse delete action name */
    public function getAction(): string
    {
        return EcommerceShopSignalConstants::WAREHOUSE_DELETE;
    }

    /**
     * @param array<string, mixed> $data Raw action payload, optionally wrapped
     * @return static Warehouse deletion request
     * @throws InvalidFormatException When the payload is not an object or the ID is missing or invalid
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            throw new InvalidFormatException('The warehouse action needs an object payload.');
        }
        if (isset($inner[SignalPayloadConstants::FIELD_DATA]) && is_array($inner[SignalPayloadConstants::FIELD_DATA])) {
            $inner = $inner[SignalPayloadConstants::FIELD_DATA];
        }

        return new static(self::requireInt($inner, ObjectWarehouse::id));
    }

    /** @return array<string, int> Deletion payload without envelope metadata */
    public function toArray(): array
    {
        return [ObjectWarehouse::id => $this->id];
    }
}
