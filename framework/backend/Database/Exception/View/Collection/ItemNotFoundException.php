<?php

declare(strict_types=1);

namespace Hilos\Database\Exception\View\Collection;

use Hilos\Database\DatabaseException;

/**
 * Exception: a DB View collection read by `[]` holds no item at the key, `null` included.
 *
 * Extends DatabaseException directly: a caller that declares the family already covers the
 * miss. CollectionException is not its parent, which leaves that base where it stands.
 */
class ItemNotFoundException extends DatabaseException
{
    /**
     * @param string $collectionClass Collection class the key was read on
     * @param mixed $key Offset as the caller passed it; int, string and null are exported, any other type is named
     */
    public function __construct(string $collectionClass, mixed $key)
    {
        parent::__construct(sprintf(
            'Item not found in collection [%s] for key [%s]',
            $collectionClass,
            is_int($key) || is_string($key) || $key === null ? var_export($key, true) : get_debug_type($key),
        ));
    }
}
