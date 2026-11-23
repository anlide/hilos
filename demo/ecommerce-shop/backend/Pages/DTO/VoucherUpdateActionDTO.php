<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages\DTO;

use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Database\Object\Item\Voucher as ObjectVoucher;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** Voucher edit request carrying the raw draft and the current values known to the editor at save time. */
final class VoucherUpdateActionDTO extends ActionPayloadDTO
{
    public const string expectedCode = 'expectedCode';
    public const string expectedDiscountPercent = 'expectedDiscountPercent';
    public const string expectedActive = 'expectedActive';

    public const array SECRET_FIELDS = [];

    /**
     * @param int $id Voucher primary key
     * @param string $code New voucher code as entered
     * @param string $discountPercent New discount as entered
     * @param bool $active New active flag
     * @param string $expectedCode Current voucher code known to the editor
     * @param int $expectedDiscountPercent Current discount known to the editor
     * @param bool $expectedActive Current active flag known to the editor
     */
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly string $discountPercent,
        public readonly bool $active,
        public readonly string $expectedCode,
        public readonly int $expectedDiscountPercent,
        public readonly bool $expectedActive,
    ) {
    }

    /** @return string Voucher update action name */
    public function getAction(): string
    {
        return EcommerceShopSignalConstants::VOUCHER_UPDATE;
    }

    /**
     * @param array<string, mixed> $data Action payload, optionally wrapped
     * @return static Voucher ID and unmodified draft and expected values
     * @throws InvalidFormatException When the data section or a required field has the wrong type
     */
    public static function fromArray(array $data): static
    {
        $inner = array_key_exists(SignalPayloadConstants::FIELD_DATA, $data)
            ? self::requireArray($data, SignalPayloadConstants::FIELD_DATA)
            : $data;

        return new static(
            self::requireInt($inner, ObjectVoucher::id),
            self::requireString($inner, ObjectVoucher::code),
            self::requireString($inner, ObjectVoucher::discountPercent),
            self::requireBool($inner, ObjectVoucher::active),
            self::requireString($inner, self::expectedCode),
            self::requireInt($inner, self::expectedDiscountPercent),
            self::requireBool($inner, self::expectedActive),
        );
    }

    /**
     * @return array{
     *     id: int, code: string, discountPercent: string, active: bool,
     *     expectedCode: string, expectedDiscountPercent: int, expectedActive: bool
     * } Edit payload
     */
    public function toArray(): array
    {
        return [
            ObjectVoucher::id => $this->id,
            ObjectVoucher::code => $this->code,
            ObjectVoucher::discountPercent => $this->discountPercent,
            ObjectVoucher::active => $this->active,
            self::expectedCode => $this->expectedCode,
            self::expectedDiscountPercent => $this->expectedDiscountPercent,
            self::expectedActive => $this->expectedActive,
        ];
    }
}
