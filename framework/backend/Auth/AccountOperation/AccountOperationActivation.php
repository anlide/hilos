<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Database\Exception\NamedDatabaseException;
use Hilos\Database\Schema\Schema;
use Hilos\Hilos;

/**
 * Whether an installation carries the data of a participant of an account operation: a registered
 * collection is mounted and its table is in the schema. This checks collection and schema
 * metadata only; it does not read participant rows. A participant whose tables this installation
 * never created has nothing to carry and is not asked.
 */
final class AccountOperationActivation
{
    /**
     * Checks collection and schema metadata only; it never reads participant rows.
     *
     * @param list<string> $collections Collection keys of one participant
     * @return bool Whether any of them is mounted with its table in the schema
     * @throws NamedDatabaseException When a collection's database binding cannot be resolved
     */
    public static function anyActivated(array $collections): bool
    {
        foreach (Hilos::$db->getObjectCollectionClasses() as $collection) {
            if (!in_array($collection::COLLECTION_KEY, $collections, true)) {
                continue;
            }
            $entityClass = ($collection::OBJECT_CLASS)::ENTITY_CLASS;
            if (Schema::getTable($entityClass::_table, $entityClass::getDatabase()->schemaIndex) !== null) {
                return true;
            }
        }

        return false;
    }
}
