<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\EntityCollection;
use Hilos\Database\Entity\Collection\FileVariants as EntityFileVariants;
use Hilos\Database\Entity\Item\FileVariant as EntityFileVariant;
use Hilos\Database\Object\Item\FileVariant as ObjectFileVariant;
use Hilos\Database\Object\Objects;

/**
 * Rendered registry copies, loaded by original file rather than as a whole table.
 *
 * @extends Objects<ObjectFileVariant>
 * @method ObjectFileVariant|null offsetGet(mixed $offset)
 */
final class FileVariants extends Objects
{
    public const string OBJECT_CLASS = ObjectFileVariant::class;
    public const string ENTITY_COLLECTION_CLASS = EntityFileVariants::class;
    public const string COLLECTION_KEY = HilosDbContext::fileVariants;

    /**
     * @param int $fileId Original registry file id
     * @param string $variant Declared variant name
     * @return ?ObjectFileVariant Stored copy, or null before the first rendering
     * @throws DatabaseException When the lookup fails
     * @throws InvalidArgumentException When the entity query is invalid
     */
    public function findFor(int $fileId, string $variant): ?ObjectFileVariant
    {
        return $this->hydrateAll(EntityFileVariant::get([
            EntityFileVariant::file_id => $fileId,
            EntityFileVariant::variant => $variant,
        ]))[0] ?? null;
    }

    /**
     * A bounded infrastructure list: one entry per rendered declaration for this file.
     *
     * @param int $fileId Original registry file id
     * @return list<ObjectFileVariant> Its stored copies, empty when none
     * @throws DatabaseException When the lookup fails
     * @throws InvalidArgumentException When the entity query is invalid
     */
    public function forFile(int $fileId): array
    {
        return $this->hydrateAll(EntityFileVariant::get([EntityFileVariant::file_id => $fileId]));
    }

    /**
     * @param EntityCollection<EntityFileVariant> $entities Rows read from the registry
     * @return list<ObjectFileVariant> Cached objects in query order
     */
    private function hydrateAll(EntityCollection $entities): array
    {
        $variants = [];
        foreach ($entities as $entity) {
            if ($entity->id === null) {
                continue;
            }
            if (!isset($this->objects[$entity->id])) {
                $this->hydrate($entity->id, ObjectFileVariant::fromEntity($entity));
            }
            $variants[] = $this->objects[$entity->id];
        }

        return $variants;
    }
}
