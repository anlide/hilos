<?php

declare(strict_types=1);

namespace Hilos\Backup;

use Hilos\Backup\Exception\BackupException;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Object\Objects;

/**
 * BackupTableResolver - the one place a backup catalog key becomes a table name.
 *
 * Both catalog-fed registries let a project write a class where a table is meant -
 * {@see BackupReferenceRegistry} for reference/seed tables, {@see
 * Anonymization\PiiRegistry} for PII columns - because a class survives a table rename
 * and a copied string does not. HIL-271 declared the two registries alike on purpose, so
 * the resolution lives here rather than once per registry: two copies of "what counts as
 * a table class" would be free to drift, and a project would then have to know which
 * registry it was writing for.
 */
final class BackupTableResolver
{
    /**
     * Derives the table name a backup catalog key stands for.
     *
     * Accepts an Entity subclass (reads its `_table`) or an Object collection subclass
     * (reads the table off its object's entity class), so a project can list whichever of
     * the two it registers.
     *
     * @param class-string|string $class Entity or Object collection class
     * @return string Table name
     * @throws BackupException When the class is neither an Entity nor an Object collection
     */
    public static function tableNameOf(string $class): string
    {
        if (is_subclass_of($class, Entity::class)) {
            return $class::_table;
        }

        if (is_subclass_of($class, Objects::class)) {
            $objectClass = $class::OBJECT_CLASS;
            $entityClass = $objectClass::ENTITY_CLASS;

            return $entityClass::_table;
        }

        throw new BackupException(
            "Backup catalog class {$class} is neither an Entity nor an Object collection",
        );
    }
}
