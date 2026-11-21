<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages\DTO;

use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Database\Object\Item\Category as ObjectCategory;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * DTO for category_move_up action payload.
 */
final class CategoryMoveUpActionDTO extends ActionPayloadDTO
{
    public const array SECRET_FIELDS = [];

    /**
     * @param int $id Category ID to move up
     */
    public function __construct(
        public readonly int $id,
    ) {
    }

    /**
     * Action name this DTO represents.
     *
     * @return string Action name constant
     */
    public function getAction(): string
    {
        return EcommerceShopSignalConstants::CATEGORY_MOVE_UP;
    }

    /**
     * Creates instance from payload array.
     *
     * @param array<string, mixed> $data Raw payload (may contain FIELD_DATA wrapper)
     * @return static Instance
     * @throws InvalidFormatException When the payload is not an array or has no valid category id
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            throw new InvalidFormatException('Action payload must be an array');
        }
        if (isset($inner[SignalPayloadConstants::FIELD_DATA]) && is_array($inner[SignalPayloadConstants::FIELD_DATA])) {
            $inner = $inner[SignalPayloadConstants::FIELD_DATA];
        }

        return new static(
            id: self::requireInt($inner, ObjectCategory::id),
        );
    }

    /**
     * Serializes to array.
     *
     * @return array<string, int> Action payload data
     */
    public function toArray(): array
    {
        return [
            ObjectCategory::id => $this->id,
        ];
    }
}
