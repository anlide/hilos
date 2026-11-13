<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Database\Exception\DbCollectionNotReadableException;
use Hilos\Database\Exception\NamedDatabaseException;
use Hilos\Database\Schema\Schema;
use Hilos\Hilos;

/**
 * Whether an installation carries the data of a participant of an account operation: any of its
 * collections is mounted and its table is in the schema. A participant whose tables this
 * installation never created has nothing to carry and is not asked.
 */
final class AccountOperationActivation
{
    /**
     * @param list<string> $collections Collection keys of one participant
     * @return bool Whether any of them is mounted with its table in the schema
     * @throws DbCollectionNotReadableException When this process does not read one of the mounted collections
     * @throws NamedDatabaseException When a collection's database binding cannot be resolved
     */
    public static function anyActivated(array $collections): bool
    {
        foreach ($collections as $key) {
            $collection = Hilos::$db->getObjectCollection($key);
            if ($collection === null) {
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
