<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Constants\TimeConstants;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\SecondFactorTrustRestriction as EntitySecondFactorTrustRestriction;
use Hilos\Database\Object\Collection\SecondFactorTrustRestrictions as ObjectSecondFactorTrustRestrictions;

/**
 * SecondFactorTrustRestriction object - wraps the SecondFactorTrustRestriction entity (HIL-1900).
 *
 * One shortening of browser trust, unchanged after it is written: trusts granted no later than
 * `savedAt` live no longer than {@see limitAt()}, and a term of 0 days takes them out ({@see revokes()}).
 *
 * @extends Object_<EntitySecondFactorTrustRestriction>
 *
 * @property-read ?int $id
 * @property-read int $savedAt Epoch milliseconds
 * @property-read int $termDays Days a trust granted by `savedAt` may still live; 0 takes it out
 */
class SecondFactorTrustRestriction extends Object_
{
    public const string ENTITY_CLASS = EntitySecondFactorTrustRestriction::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectSecondFactorTrustRestrictions::class;
    public const string id = 'id';
    public const string savedAt = 'savedAt';
    public const string termDays = 'termDays';

    /**
     * Magic getter for entity properties.
     *
     * @param string $property Property name (see class @property list)
     * @return mixed Property value
     * @throws DatabaseException When the property is not a known SecondFactorTrustRestriction field
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::savedAt => $this->entity->saved_at,
            self::termDays => $this->entity->term_days,
            default => parent::__get($property),
        };
    }

    /**
     * Latest moment a trust this restriction applies to may still be trusted until.
     *
     * @return int Epoch milliseconds: the save moment plus the term
     */
    public function limitAt(): int
    {
        return $this->entity->saved_at
            + $this->entity->term_days * TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND;
    }

    /**
     * Whether the restriction takes trust out rather than shortening it.
     *
     * @return bool True for a term of 0 days
     */
    public function revokes(): bool
    {
        return $this->entity->term_days === 0;
    }

    /**
     * Whether a trust last granted at a moment falls under this restriction.
     *
     * @param int $grantedAt Moment the trust was last granted (epoch milliseconds)
     * @return bool True when the trust was granted no later than the save moment
     */
    public function appliesTo(int $grantedAt): bool
    {
        return $grantedAt <= $this->entity->saved_at;
    }

    /**
     * Converts the restriction row to an associative array.
     *
     * @return array<string, mixed> Restriction data (id, savedAt, termDays)
     */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::savedAt => $this->entity->saved_at,
            self::termDays => $this->entity->term_days,
        ];
    }
}
