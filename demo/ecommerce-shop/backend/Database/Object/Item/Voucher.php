<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Object\Item;

use Demo\EcommerceShop\Database\Entity\Item\Voucher as EntityVoucher;
use Demo\EcommerceShop\Database\Object\Collection\Vouchers as ObjectVouchers;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\PropertyNotAccessibleException;
use Hilos\Database\Object\Item\Object_;

/**
 * Voucher - Object wrapper for voucher entity.
 *
 * @extends Object_<EntityVoucher>
 *
 * @property-read ?int $id
 * @property string $code
 * @property int $discountPercent
 * @property bool $active
 */
final class Voucher extends Object_
{
    public const string ENTITY_CLASS = EntityVoucher::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectVouchers::class;

    public const string id = 'id';
    public const string code = 'code';
    public const string discountPercent = 'discountPercent';
    public const string active = 'active';

    /**
     * Returns the value of a voucher object property by name.
     *
     * @param string $property Property name (id, code, discountPercent, active)
     * @return mixed Property value or parent method result
     * @throws DatabaseException If the entity is misconfigured
     * @throws PropertyNotAccessibleException If the property does not exist
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::code => $this->entity->code,
            self::discountPercent => $this->entity->discount_percent,
            self::active => $this->entity->active,
            default => parent::__get($property),
        };
    }

    /**
     * Sets the value of a voucher object property.
     *
     * @param string $property Property name to set (code, discountPercent, active)
     * @param mixed $value New value
     * @throws DatabaseException If entity or collection access fails
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::code => $this->entity->code = (string)$value,
            self::discountPercent => $this->entity->discount_percent = (int)$value,
            self::active => $this->entity->active = (bool)$value,
            default => parent::__set($property, $value),
        };
    }

    /**
     * Converts the voucher object to an associative array with all fields.
     *
     * @return array<string, mixed> Key => value array
     */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::code => $this->entity->code,
            self::discountPercent => $this->entity->discount_percent,
            self::active => $this->entity->active,
        ];
    }
}
