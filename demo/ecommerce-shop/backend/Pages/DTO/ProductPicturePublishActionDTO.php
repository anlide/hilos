<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages\DTO;

use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** Publish request for the picture of a new product, named by the id the browser gave its upload. */
final class ProductPicturePublishActionDTO extends ActionPayloadDTO
{
    public const array SECRET_FIELDS = [];

    /** Wire key for the id the browser gave the upload. */
    public const string clientUploadId = 'clientUploadId';

    /**
     * @param string $clientUploadId Id the browser gave the upload, checked by the files door
     */
    public function __construct(public readonly string $clientUploadId)
    {
    }

    /** @return string Product picture publish action name */
    public function getAction(): string
    {
        return EcommerceShopSignalConstants::PRODUCT_PICTURE_PUBLISH;
    }

    /**
     * @param array<string, mixed> $data Raw action payload, optionally wrapped
     * @return static Unmodified upload id
     * @throws InvalidFormatException When the data section or the upload id has the wrong type
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            throw new InvalidFormatException('The product picture action needs an object payload.');
        }

        return new static(self::requireString($inner, self::clientUploadId));
    }

    /** @return array{clientUploadId: string} Publish payload without envelope metadata */
    public function toArray(): array
    {
        return [self::clientUploadId => $this->clientUploadId];
    }
}
