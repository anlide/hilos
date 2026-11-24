<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages\DTO;

use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** Identifies a saved cart line and the kind of cart the shopper saw. */
final class CartRemoveLineActionDTO extends ActionPayloadDTO
{
    public const array SECRET_FIELDS = [];
    public const string GUEST = 'guest';
    public const string ACCOUNT = 'account';

    private const string lineId = 'lineId';
    private const string ownerKind = 'ownerKind';

    /**
     * @param int $lineId Saved product line primary key
     * @param string $ownerKind Kind of cart the page displayed
     * @throws InvalidFormatException When the line ID or cart kind is invalid
     */
    public function __construct(public readonly int $lineId, public readonly string $ownerKind)
    {
        if ($lineId <= 0) {
            throw new InvalidFormatException('The cart line ID must be a positive integer.');
        }
        if ($ownerKind !== self::GUEST && $ownerKind !== self::ACCOUNT) {
            throw new InvalidFormatException('The cart owner kind must be guest or account.');
        }
    }

    /** @return string Tracked cart removal action name */
    public function getAction(): string
    {
        return EcommerceShopSignalConstants::CART_REMOVE_LINE;
    }

    /**
     * @param array<string, mixed> $data Raw action payload, optionally wrapped
     * @return static The requested line and its expected cart kind
     * @throws InvalidFormatException When the payload is not an object or a required field is missing or invalid
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            throw new InvalidFormatException('The cart removal action needs an object payload.');
        }

        return new static(self::requireInt($inner, self::lineId), self::requireString($inner, self::ownerKind));
    }

    /** @return array{lineId: int, ownerKind: string} Cart removal payload */
    public function toArray(): array
    {
        return [self::lineId => $this->lineId, self::ownerKind => $this->ownerKind];
    }
}
