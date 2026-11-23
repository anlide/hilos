<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages\DTO;

use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Database\Object\Item\Voucher as ObjectVoucher;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** Voucher create request carrying the discount as entered on the form. */
final class VoucherCreateActionDTO extends ActionPayloadDTO
{
    public const array SECRET_FIELDS = [];

    /**
     * @param string $code Voucher code as entered
     * @param string $discountPercent Discount as entered
     * @param bool $active Whether the voucher is active
     */
    public function __construct(
        public readonly string $code,
        public readonly string $discountPercent,
        public readonly bool $active,
    ) {
    }

    /** @return string Voucher create action name */
    public function getAction(): string
    {
        return EcommerceShopSignalConstants::VOUCHER_CREATE;
    }

    /**
     * @param array<string, mixed> $data Action payload, optionally wrapped
     * @return static Unmodified create fields
     * @throws InvalidFormatException When the data section or a required field has the wrong type
     */
    public static function fromArray(array $data): static
    {
        $inner = array_key_exists(SignalPayloadConstants::FIELD_DATA, $data)
            ? self::requireArray($data, SignalPayloadConstants::FIELD_DATA)
            : $data;

        return new static(
            self::requireString($inner, ObjectVoucher::code),
            self::requireString($inner, ObjectVoucher::discountPercent),
            self::requireBool($inner, ObjectVoucher::active),
        );
    }

    /** @return array{code: string, discountPercent: string, active: bool} Create payload */
    public function toArray(): array
    {
        return [
            ObjectVoucher::code => $this->code,
            ObjectVoucher::discountPercent => $this->discountPercent,
            ObjectVoucher::active => $this->active,
        ];
    }
}
