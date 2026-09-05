<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\VerifierCircleMember as EntityVerifierCircleMember;
use Hilos\Database\Object\Collection\VerifierCircleMembers;
use Hilos\Database\Object\Item\Object_;

/**
 * VerifierCircleMember object - wraps a VerifierCircleMember entity.
 *
 * One named verifier of the system after a restore (HIL-643). Carries no behaviour
 * beyond field access: the lookups live on {@see VerifierCircleMembers}, and resolving
 * the pair to a person is the caller's step, never a field of this row.
 *
 * @extends Object_<EntityVerifierCircleMember>
 *
 * @property-read ?int $id
 * @property string $identityType
 * @property string $identifier
 */
final class VerifierCircleMember extends Object_
{
    public const string ENTITY_CLASS = EntityVerifierCircleMember::class;
    public const string id = 'id';
    public const string identityType = 'identityType';
    public const string identifier = 'identifier';

    /**
     * Returns the database collection key.
     *
     * @return string Collection key (HilosDbContext::verifierCircle)
     */
    protected static function getCollectionKey(): string
    {
        return HilosDbContext::verifierCircle;
    }

    /**
     * Magic getter for entity properties.
     *
     * @param string $property Property name (id, identityType, identifier)
     * @return mixed Property value
     * @throws DatabaseException When the property is not a known VerifierCircleMember field
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::identityType => $this->entity->identity_type,
            self::identifier => $this->entity->identifier,
            default => parent::__get($property),
        };
    }

    /**
     * Magic setter for entity properties.
     *
     * @param string $property Property name (identityType, identifier)
     * @param mixed $value Value to set
     * @throws DatabaseException When the property cannot be set on a VerifierCircleMember
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::identityType => $this->entity->identity_type = (string)$value,
            self::identifier => $this->entity->identifier = (string)$value,
            default => parent::__set($property, $value),
        };
    }

    /**
     * Converts the member to an associative array.
     *
     * @return array<string, mixed> Member data (id, identityType, identifier)
     */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::identityType => $this->entity->identity_type,
            self::identifier => $this->entity->identifier,
        ];
    }
}
