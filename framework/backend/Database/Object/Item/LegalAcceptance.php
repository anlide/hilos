<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\LegalAcceptance as EntityLegalAcceptance;
use Hilos\Database\Object\Collection\LegalAcceptances as ObjectLegalAcceptances;

/**
 * Scalar acceptance record. There is no operation that rewrites a stored acceptance.
 *
 * @extends Object_<EntityLegalAcceptance>
 * @property-read ?int $id
 * @property int $userId
 * @property string $document
 * @property string $revisionId
 * @property string $acceptedAt
 */
class LegalAcceptance extends Object_
{
    public const string ENTITY_CLASS = EntityLegalAcceptance::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectLegalAcceptances::class;
    public const string id = 'id';
    public const string userId = 'userId';
    public const string document = 'document';
    public const string revisionId = 'revisionId';
    public const string acceptedAt = 'acceptedAt';

    /**
     * @param string $property Scalar field
     * @return mixed Field value
     * @throws DatabaseException When the field is unknown
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::userId => $this->entity->user_id,
            self::document => $this->entity->document,
            self::revisionId => $this->entity->revision_id,
            self::acceptedAt => $this->entity->accepted_at,
            default => parent::__get($property),
        };
    }

    /**
     * Initializes a new record; persisted records cannot be edited.
     *
     * @param string $property Scalar field
     * @param mixed $value Field value
     * @throws DatabaseException When the stored row is immutable or the field is unknown
     */
    public function __set(string $property, mixed $value): void
    {
        if ($this->entity->id !== null) {
            throw new DatabaseException('A stored legal acceptance is immutable');
        }
        match ($property) {
            self::userId => $this->entity->user_id = (int)$value,
            self::document => $this->entity->document = (string)$value,
            self::revisionId => $this->entity->revision_id = (string)$value,
            self::acceptedAt => $this->entity->accepted_at = (string)$value,
            default => parent::__set($property, $value),
        };
    }

    /** @return array<string, mixed> Scalar acceptance data */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::userId => $this->entity->user_id,
            self::document => $this->entity->document,
            self::revisionId => $this->entity->revision_id,
            self::acceptedAt => $this->entity->accepted_at,
        ];
    }
}
