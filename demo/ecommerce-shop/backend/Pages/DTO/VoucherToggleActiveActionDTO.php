<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages\DTO;

use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Database\Object\Item\Voucher as ObjectVoucher;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** Request to toggle one voucher's active flag. */
final class VoucherToggleActiveActionDTO extends ActionPayloadDTO
{
    public const array SECRET_FIELDS = [];

    /**
     * @param int $id Voucher primary key
     * @throws InvalidFormatException When the ID is not positive
     */
    public function __construct(public readonly int $id)
    {
        if ($id <= 0) {
            throw new InvalidFormatException('The voucher ID must be a positive integer.');
        }
    }

    /** @return string Voucher active toggle action name */
    public function getAction(): string
    {
        return EcommerceShopSignalConstants::VOUCHER_TOGGLE_ACTIVE;
    }

    /**
     * @param array<string, mixed> $data Action payload, optionally wrapped
     * @return static Voucher toggle request
     * @throws InvalidFormatException When the payload lacks a positive integer voucher ID
     */
    public static function fromArray(array $data): static
    {
        $inner = array_key_exists(SignalPayloadConstants::FIELD_DATA, $data)
            ? self::requireArray($data, SignalPayloadConstants::FIELD_DATA)
            : $data;

        return new static(self::requireInt($inner, ObjectVoucher::id));
    }

    /** @return array{id: int} Toggle payload */
    public function toArray(): array
    {
        return [ObjectVoucher::id => $this->id];
    }
}
