<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages\DTO;

use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Database\Object\Item\Category as ObjectCategory;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * Add request; the categories library trims and checks the raw name and description at the write
 * boundary, and the page checks the picture is a fresh file of the person who adds.
 */
final class CategoryCreateActionDTO extends ActionPayloadDTO
{
    public const array SECRET_FIELDS = [];

    /**
     * @param string $name Raw category name, checked by the category actions
     * @param string $description Raw category description, checked by the category actions
     * @param int $pictureFileId Registry file published for the new category
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly int $pictureFileId,
    ) {
    }

    /** @return string Category create action name */
    public function getAction(): string
    {
        return EcommerceShopSignalConstants::CATEGORY_CREATE;
    }

    /**
     * @param array<string, mixed> $data Raw action payload, optionally wrapped
     * @return static Unmodified name, description and picture file
     * @throws InvalidFormatException When the data section, the name, the description or the picture file has the wrong type
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            throw new InvalidFormatException('The category action needs an object payload.');
        }

        return new static(
            self::requireString($inner, ObjectCategory::name),
            self::requireString($inner, ObjectCategory::description),
            self::requireInt($inner, ObjectCategory::pictureFileId),
        );
    }

    /** @return array{name: string, description: string, pictureFileId: int} Add payload without envelope metadata */
    public function toArray(): array
    {
        return [
            ObjectCategory::name => $this->name,
            ObjectCategory::description => $this->description,
            ObjectCategory::pictureFileId => $this->pictureFileId,
        ];
    }
}
