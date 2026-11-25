<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages\DTO;

use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Database\Object\Item\Voucher;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** A voucher code entered on the cart page and the kind of cart displayed there. */
final class CartVoucherApplyActionDTO extends ActionPayloadDTO
{
    public const array SECRET_FIELDS = [];

    private const string ownerKind = 'ownerKind';

    /**
     * @param string $code Voucher code as entered
     * @param string $ownerKind Kind of cart the page displayed
     * @throws InvalidFormatException When the cart kind is unknown
     */
    public function __construct(public readonly string $code, public readonly string $ownerKind)
    {
        if ($ownerKind !== CartStepQuantityActionDTO::GUEST && $ownerKind !== CartStepQuantityActionDTO::ACCOUNT) {
            throw new InvalidFormatException('Unknown cart owner kind');
        }
    }

    /** @return string Apply voucher action name */
    public function getAction(): string
    {
        return EcommerceShopSignalConstants::CART_APPLY_VOUCHER;
    }

    /**
     * @param array<string, mixed> $data Action payload, optionally wrapped
     * @return static Parsed action with the original code text
     * @throws InvalidFormatException When the wrapper or either required field is invalid
     */
    public static function fromArray(array $data): static
    {
        $inner = array_key_exists(SignalPayloadConstants::FIELD_DATA, $data)
            ? self::requireArray($data, SignalPayloadConstants::FIELD_DATA)
            : $data;

        return new static(
            self::requireString($inner, Voucher::code),
            self::requireString($inner, self::ownerKind),
        );
    }

    /** @return array{code: string, ownerKind: string} Voucher application payload */
    public function toArray(): array
    {
        return [
            Voucher::code => $this->code,
            self::ownerKind => $this->ownerKind,
        ];
    }
}
