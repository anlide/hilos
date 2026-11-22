<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages\DTO;

use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Database\Object\Item\Product as ObjectProduct;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** The catalog fields to save and the version of the product the editor read. */
final class ProductUpdateActionDTO extends ActionPayloadDTO
{
    public const array SECRET_FIELDS = [];
    public const string expectedVersion = 'expectedVersion';

    private const string VERSION_PATTERN = '/\A[0-9a-f]{64}\z/';

    /**
     * @param int $productId Product being edited
     * @param string $expectedVersion Version of the saved fields the editor read
     * @param string $name Raw product name
     * @param int $categoryId Category selected for the product
     * @param string $price Amount written in hryvnias
     * @param string $description Raw product description
     * @param ?int $pictureFileId Fresh publication to use, or null to keep the current picture
     * @throws InvalidFormatException When either required id or the version is invalid
     */
    public function __construct(
        public readonly int $productId,
        public readonly string $expectedVersion,
        public readonly string $name,
        public readonly int $categoryId,
        public readonly string $price,
        public readonly string $description,
        public readonly ?int $pictureFileId,
    ) {
        if ($productId <= 0 || $categoryId <= 0) {
            throw new InvalidFormatException('Product and category IDs must be positive integers.');
        }
        if (preg_match(self::VERSION_PATTERN, $expectedVersion) !== 1) {
            throw new InvalidFormatException('The product version is invalid.');
        }
    }

    /** @return string Product update action name */
    public function getAction(): string
    {
        return EcommerceShopSignalConstants::PRODUCT_UPDATE;
    }

    /**
     * @param array<string, mixed> $data Raw action payload, optionally wrapped
     * @return static Product fields as submitted, with an explicit choice for the picture
     * @throws InvalidFormatException When the payload lacks a required field, carries an invalid value or supplies a kind
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            throw new InvalidFormatException('The product action needs an object payload.');
        }
        if (array_key_exists(ObjectProduct::kind, $inner)) {
            throw new InvalidFormatException('A product keeps the kind it was created with');
        }
        if (!array_key_exists(ObjectProduct::pictureFileId, $inner)) {
            throw new InvalidFormatException('Payload carries no integer or null under key ' . ObjectProduct::pictureFileId);
        }

        return new static(
            self::requireInt($inner, AdminProductPageSubscribeParams::PRODUCT_ID),
            self::requireString($inner, self::expectedVersion),
            self::requireString($inner, ObjectProduct::name),
            self::requireInt($inner, ObjectProduct::categoryId),
            self::requireString($inner, ObjectProduct::price),
            self::requireString($inner, ObjectProduct::description),
            self::optionalInt($inner, ObjectProduct::pictureFileId),
        );
    }

    /**
     * @return array{productId: int, expectedVersion: string, name: string, categoryId: int,
     *     price: string, description: string, pictureFileId: ?int} Product update payload
     */
    public function toArray(): array
    {
        return [
            AdminProductPageSubscribeParams::PRODUCT_ID => $this->productId,
            self::expectedVersion => $this->expectedVersion,
            ObjectProduct::name => $this->name,
            ObjectProduct::categoryId => $this->categoryId,
            ObjectProduct::price => $this->price,
            ObjectProduct::description => $this->description,
            ObjectProduct::pictureFileId => $this->pictureFileId,
        ];
    }
}
