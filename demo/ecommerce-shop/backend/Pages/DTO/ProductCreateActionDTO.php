<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages\DTO;

use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Database\Object\Item\Product as ObjectProduct;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * Add request carrying the written price in hryvnias and the id of the published picture.
 *
 * The page checks the picture and converts the price before handing the fields to the products library.
 */
final class ProductCreateActionDTO extends ActionPayloadDTO
{
    public const array SECRET_FIELDS = [];

    /**
     * @param string $name Raw product name
     * @param int $categoryId Category selected for the product
     * @param string $kind Product kind as chosen on the form
     * @param string $price Amount written in hryvnias
     * @param string $description Raw product description
     * @param int $pictureFileId Registry file published for the new product
     */
    public function __construct(
        public readonly string $name,
        public readonly int $categoryId,
        public readonly string $kind,
        public readonly string $price,
        public readonly string $description,
        public readonly int $pictureFileId,
    ) {
    }

    /** @return string Product create action name */
    public function getAction(): string
    {
        return EcommerceShopSignalConstants::PRODUCT_CREATE;
    }

    /**
     * @param array<string, mixed> $data Raw action payload, optionally wrapped
     * @return static Unmodified create fields
     * @throws InvalidFormatException When the data section has the wrong type, or a field is missing or mistyped
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            throw new InvalidFormatException('The product action needs an object payload.');
        }

        return new static(
            self::requireString($inner, ObjectProduct::name),
            self::requireInt($inner, ObjectProduct::categoryId),
            self::requireString($inner, ObjectProduct::kind),
            self::requireString($inner, ObjectProduct::price),
            self::requireString($inner, ObjectProduct::description),
            self::requireInt($inner, ObjectProduct::pictureFileId),
        );
    }

    /** @return array{name: string, categoryId: int, kind: string, price: string, description: string, pictureFileId: int} Add payload */
    public function toArray(): array
    {
        return [
            ObjectProduct::name => $this->name,
            ObjectProduct::categoryId => $this->categoryId,
            ObjectProduct::kind => $this->kind,
            ObjectProduct::price => $this->price,
            ObjectProduct::description => $this->description,
            ObjectProduct::pictureFileId => $this->pictureFileId,
        ];
    }
}
