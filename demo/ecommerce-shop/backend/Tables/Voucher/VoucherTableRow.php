<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tables\Voucher;

use Demo\EcommerceShop\Database\Object\Item\Voucher as ObjectVoucher;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;

/**
 * Backend row payload for the vouchers table: one voucher of the catalog.
 *
 * The code is the stored normalized value. The percent is the stored integer;
 * the percent sign is drawn by the page.
 */
final class VoucherTableRow extends AbstractTableRow
{
    public const string id = ObjectVoucher::id;
    public const string code = ObjectVoucher::code;
    public const string discountPercent = ObjectVoucher::discountPercent;
    public const string active = ObjectVoucher::active;

    /**
     * @param int $id Persisted voucher id
     * @param string $code Normalized voucher code
     * @param int $discountPercent Whole percent of the discount, from 1 to 100
     * @param bool $active Whether the voucher is given in the cart
     */
    public function __construct(
        public int $id,
        public string $code,
        public int $discountPercent,
        public bool $active,
    ) {
    }

    /**
     * @return int Voucher id, the stable row key of the vouchers table
     */
    public function getRowKey(): int
    {
        return $this->id;
    }

    /**
     * @return string Payload key the row key travels under
     */
    public static function keyField(): string
    {
        return self::id;
    }

    /**
     * @return array{id: int, code: string, discountPercent: int, active: bool} Row payload
     */
    public function toArray(): array
    {
        return [
            self::id => $this->id,
            self::code => $this->code,
            self::discountPercent => $this->discountPercent,
            self::active => $this->active,
        ];
    }

    /**
     * Builds a voucher table row from raw table payload.
     *
     * @param array<string, mixed> $data Raw row payload
     * @return static Restored row
     * @throws InvalidFormatException When the payload is missing a field the row is rendered by
     */
    public static function fromArray(array $data): static
    {
        return new static(
            id: self::requireInt($data, self::id),
            code: self::requireString($data, self::code),
            discountPercent: self::requireInt($data, self::discountPercent),
            active: self::requireBool($data, self::active),
        );
    }
}
