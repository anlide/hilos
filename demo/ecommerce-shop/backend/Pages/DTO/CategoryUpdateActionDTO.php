<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages\DTO;

use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Database\Object\Item\Category as ObjectCategory;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** Edits a category only while its fields still match the editor's expected values. */
final class CategoryUpdateActionDTO extends ActionPayloadDTO
{
    public const string expectedName = 'expectedName';
    public const string expectedDescription = 'expectedDescription';
    public const string expectedPictureFileId = 'expectedPictureFileId';

    public const array SECRET_FIELDS = [];

    /**
     * @param int $id Category primary ID
     * @param string $name Raw new category name
     * @param string $description Raw new category description
     * @param ?int $pictureFileId Fresh picture to use, or null to keep the current picture
     * @param string $expectedName Name in the editor's baseline
     * @param string $expectedDescription Description in the editor's baseline
     * @param int $expectedPictureFileId Picture in the editor's baseline
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $description,
        public readonly ?int $pictureFileId,
        public readonly string $expectedName,
        public readonly string $expectedDescription,
        public readonly int $expectedPictureFileId,
    ) {
    }

    /** @return string Category update action name */
    public function getAction(): string
    {
        return EcommerceShopSignalConstants::CATEGORY_UPDATE;
    }

    /**
     * @param array<string, mixed> $data Raw action payload, optionally wrapped
     * @return static Category ID with the unmodified new and expected values
     * @throws InvalidFormatException When the payload is not an object or a required field is missing or invalid
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            throw new InvalidFormatException('The category action needs an object payload.');
        }
        if (!array_key_exists(ObjectCategory::pictureFileId, $inner)) {
            throw new InvalidFormatException('Payload carries no value under key ' . ObjectCategory::pictureFileId);
        }

        return new static(
            self::requireInt($inner, ObjectCategory::id),
            self::requireString($inner, ObjectCategory::name),
            self::requireString($inner, ObjectCategory::description),
            self::optionalInt($inner, ObjectCategory::pictureFileId),
            self::requireString($inner, self::expectedName),
            self::requireString($inner, self::expectedDescription),
            self::requireInt($inner, self::expectedPictureFileId),
        );
    }

    /** @return array<string, int|string|null> Update payload without envelope metadata */
    public function toArray(): array
    {
        return [
            ObjectCategory::id => $this->id,
            ObjectCategory::name => $this->name,
            ObjectCategory::description => $this->description,
            ObjectCategory::pictureFileId => $this->pictureFileId,
            self::expectedName => $this->expectedName,
            self::expectedDescription => $this->expectedDescription,
            self::expectedPictureFileId => $this->expectedPictureFileId,
        ];
    }
}
