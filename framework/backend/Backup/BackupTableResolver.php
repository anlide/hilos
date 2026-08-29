<?php

declare(strict_types=1);

namespace Hilos\Backup;

use Hilos\Backup\Exception\BackupException;
use Hilos\Database\Context\DbContext;
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
     * @param class-string|string $class Entity or Object collection class
     * @return string Table name
     * @throws BackupException When the class is neither an Entity nor an Object collection
     */
    public static function tableNameOf(string $class): string
    {
        return self::entityClassOf($class)::_table;
    }

    /**
     * Derives the Entity class standing behind an Entity or an Object collection.
     *
     * Accepts either, so a caller may name whichever of the two it holds - a catalog key
     * or a collection a {@see DbContext} mounted. The Entity is where a table says what it
     * is called and what of it is personal, so a reader of either fact starts here.
     *
     * @param class-string|string $class Entity or Object collection class
     * @return class-string<Entity> Entity class behind it
     * @throws BackupException When the class is neither an Entity nor an Object collection
     */
    public static function entityClassOf(string $class): string
    {
        if (is_subclass_of($class, Entity::class)) {
            return $class;
        }

        if (is_subclass_of($class, Objects::class)) {
            $objectClass = $class::OBJECT_CLASS;

            return $objectClass::ENTITY_CLASS;
        }

        throw new BackupException(
            "Backup catalog class {$class} is neither an Entity nor an Object collection",
        );
    }
}
