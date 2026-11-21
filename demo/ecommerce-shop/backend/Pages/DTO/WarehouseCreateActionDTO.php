<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages\DTO;

use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Database\Object\Item\Warehouse as ObjectWarehouse;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** Add request; the warehouses library trims and checks the raw name and address at the write boundary. */
final class WarehouseCreateActionDTO extends ActionPayloadDTO
{
    public const array SECRET_FIELDS = [];

    /**
     * @param string $name Raw warehouse name, checked by the warehouse actions
     * @param string $address Raw warehouse address, checked by the warehouse actions
     */
    public function __construct(public readonly string $name, public readonly string $address)
    {
    }

    /** @return string Warehouse create action name */
    public function getAction(): string
    {
        return EcommerceShopSignalConstants::WAREHOUSE_CREATE;
    }

    /**
     * @param array<string, mixed> $data Raw action payload, optionally wrapped
     * @return static Unmodified name and address
     * @throws InvalidFormatException When the data section, the name or the address has the wrong type
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            throw new InvalidFormatException('The warehouse action needs an object payload.');
        }

        return new static(
            self::requireString($inner, ObjectWarehouse::name),
            self::requireString($inner, ObjectWarehouse::address),
        );
    }

    /** @return array<string, string> Add payload without envelope metadata */
    public function toArray(): array
    {
        return [ObjectWarehouse::name => $this->name, ObjectWarehouse::address => $this->address];
    }
}
